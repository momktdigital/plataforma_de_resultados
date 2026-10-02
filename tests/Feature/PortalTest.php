<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Configuracao;
use App\Models\Questao;
use App\Models\RateLimit2fa;
use App\Models\Resposta;
use App\Models\VerificacaoEmail;
use App\Services\Portal\CaptchaVerifier;
use App\Services\Portal\SmtpEmailSender;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A tela do portal não exige login, mas o app se considera
        // "não instalado" (e redireciona pro wizard) enquanto não houver
        // nenhum admin cadastrado — ver App\Support\InstallStatus.
        Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    private function aluno(array $atributos = []): Aluno
    {
        return Aluno::create(array_merge([
            'ra' => '2026001',
            'cpf' => '12345678909',
            'data_nascimento' => '2000-03-15',
            'nome' => 'Fulano de Tal',
        ], $atributos));
    }

    /** Simula o primeiro fator (CPF + nascimento) já aprovado nesta sessão — o que libera `verificar`/`reenviar`. */
    private function primeiroFator(Aluno $aluno, ?int $ate = null): void
    {
        $this->withSession(['portal_pre_auth' => [
            'aluno_id' => $aluno->id,
            'cpf' => $aluno->cpf,
            'ate' => $ate ?? Carbon::now()->addMinutes(15)->timestamp,
        ]]);
    }

    /** O código de 2FA só existe no banco como HMAC (ver VerificacaoEmail::hashDoCodigo()). */
    private function codigoGuardado(Aluno $aluno, string $codigo = '123456'): string
    {
        return VerificacaoEmail::hashDoCodigo($aluno->cpf, $codigo);
    }

    public function test_consulta_sem_2fa_mostra_resultados_diretamente(): void
    {
        $aluno = $this->aluno();
        $avaliacao = Avaliacao::create(['nome' => 'ENADE 2026']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => $aluno->ra, 'questao_numero' => 1, 'resposta' => 'A']);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $response = $this->followingRedirects()->post('/portal/consultar', [
            'cpf' => '123.456.789-09',
            'data_nascimento' => '15/03/2000',
        ]);

        $response->assertOk();
        $response->assertSee('ENADE 2026');
        $response->assertSee('100%');
    }

    public function test_login_bem_sucedido_regenera_o_id_de_sessao(): void
    {
        $this->aluno();

        // Sessão "fixada" antes do login — simula alguém que abriu o portal
        // num computador compartilhado antes do aluno se autenticar.
        $this->get('/portal');
        $idAntesDoLogin = $this->app['session']->getId();

        $this->post('/portal/consultar', [
            'cpf' => '123.456.789-09',
            'data_nascimento' => '15/03/2000',
        ]);

        $this->assertNotSame($idAntesDoLogin, $this->app['session']->getId());
    }

    public function test_sair_invalida_a_sessao_em_vez_de_so_esquecer_o_aluno(): void
    {
        $this->aluno();

        $this->post('/portal/consultar', [
            'cpf' => '123.456.789-09',
            'data_nascimento' => '15/03/2000',
        ]);

        $this->get('/portal/resultados')->assertOk();

        $idAntesDeSair = $this->app['session']->getId();

        $this->get('/portal/sair')->assertRedirect(route('portal.consulta'));

        // O boletim não fica mais acessível...
        $this->get('/portal/resultados')->assertRedirect(route('portal.consulta'));
        // ...e o ID de sessão anterior foi descartado (não só o vínculo com o aluno).
        $this->assertNotSame($idAntesDeSair, $this->app['session']->getId());
    }

    public function test_cpf_ou_nascimento_incorretos_retorna_erro(): void
    {
        $this->aluno();

        $response = $this->post('/portal/consultar', [
            'cpf' => '123.456.789-09',
            'data_nascimento' => '01/01/1999',
        ]);

        $response->assertSessionHasErrors('cpf');
    }

    public function test_2fa_ativo_sem_email_cadastrado_retorna_erro(): void
    {
        Configuracao::definir('smtp_ativo', '1');
        $this->aluno(['email' => null]);

        $response = $this->post('/portal/consultar', [
            'cpf' => '123.456.789-09',
            'data_nascimento' => '15/03/2000',
        ]);

        $response->assertSessionHasErrors('cpf');
        $response->assertSessionHasErrors(['cpf' => 'O 2FA está ativo, mas você não tem e-mail cadastrado. Contate a secretaria.']);
    }

    public function test_2fa_ativo_envia_codigo_e_pede_verificacao(): void
    {
        Configuracao::definir('smtp_ativo', '1');
        $this->aluno(['email' => 'aluno@example.com']);

        $this->mock(SmtpEmailSender::class, function (MockInterface $mock) {
            $mock->shouldReceive('enviar')->once()
                ->with('aluno@example.com', \Mockery::type('string'), \Mockery::type('string'));
        });

        $response = $this->post('/portal/consultar', [
            'cpf' => '123.456.789-09',
            'data_nascimento' => '15/03/2000',
        ]);

        $response->assertOk();
        $response->assertSee('Verificação');
        $this->assertDatabaseHas('verificacoes_email', ['cpf' => '12345678909']);
        // O CPF não é repassado à tela do código (nem em campo oculto): a sessão é que lembra de quem é.
        $response->assertDontSee('12345678909');
        $response->assertDontSee('123.456.789-09');
    }

    // ------------------------------------------------------------ 2FA: primeiro fator, hash do código, limite por CPF

    /** Código que o aluno recebeu por e-mail numa consulta com 2FA ativo (capturado do corpo da mensagem). */
    private function consultarEReceberCodigo(Aluno $aluno): string
    {
        $corpo = '';
        $this->mock(SmtpEmailSender::class, function (MockInterface $mock) use (&$corpo) {
            $mock->shouldReceive('enviar')->andReturnUsing(function ($para, $assunto, $texto) use (&$corpo) {
                $corpo = $texto;
            });
        });

        $this->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '15/03/2000'])->assertOk();
        preg_match('/\b(\d{6})\b/', $corpo, $m);

        return $m[1];
    }

    public function test_codigo_de_2fa_nunca_fica_em_texto_puro_no_banco(): void
    {
        Configuracao::definir('smtp_ativo', '1');
        $aluno = $this->aluno(['email' => 'aluno@example.com']);

        $codigo = $this->consultarEReceberCodigo($aluno);

        $guardado = VerificacaoEmail::firstOrFail()->codigo;
        $this->assertNotSame($codigo, $guardado);
        $this->assertSame(64, strlen($guardado));
        $this->assertSame(VerificacaoEmail::hashDoCodigo($aluno->cpf, $codigo), $guardado);
        $this->assertSame(0, \DB::table('verificacoes_email')->where('codigo', $codigo)->count());
    }

    public function test_o_codigo_recebido_por_email_abre_o_portal(): void
    {
        Configuracao::definir('smtp_ativo', '1');
        $aluno = $this->aluno(['email' => 'aluno@example.com']);
        $codigo = $this->consultarEReceberCodigo($aluno);

        $this->post('/portal/verificar', ['codigo' => $codigo])->assertRedirect(route('portal.resultados'));
        $this->get('/portal/resultados')->assertOk();
    }

    public function test_verificar_e_reenviar_nao_funcionam_sem_o_primeiro_fator(): void
    {
        Configuracao::definir('smtp_ativo', '1');
        $aluno = $this->aluno(['email' => 'aluno@example.com']);
        VerificacaoEmail::create(['cpf' => $aluno->cpf, 'codigo' => $this->codigoGuardado($aluno), 'expira_em' => Carbon::now()->addMinutes(10)]);
        $this->mock(SmtpEmailSender::class, fn (MockInterface $mock) => $mock->shouldNotReceive('enviar'));

        // Quem só sabe o CPF: nem reenvia e-mail para o aluno, nem gasta as tentativas do código dele.
        $this->post('/portal/verificar', ['cpf' => $aluno->cpf, 'codigo' => '000000'])
            ->assertRedirect(route('portal.consulta'))
            ->assertSessionHasErrors('cpf');
        $this->post('/portal/reenviar', ['cpf' => $aluno->cpf])
            ->assertRedirect(route('portal.consulta'))
            ->assertSessionHasErrors('cpf');

        $this->assertDatabaseHas('verificacoes_email', ['cpf' => $aluno->cpf, 'tentativas_falhas' => 0, 'vezes_reenviado' => 0]);
        $this->assertDatabaseCount('rate_limit_2fa', 0);
    }

    public function test_primeiro_fator_expirado_nao_vale(): void
    {
        $aluno = $this->aluno(['email' => 'aluno@example.com']);
        VerificacaoEmail::create(['cpf' => $aluno->cpf, 'codigo' => $this->codigoGuardado($aluno), 'expira_em' => Carbon::now()->addMinutes(10)]);
        $this->primeiroFator($aluno, ate: Carbon::now()->subMinute()->timestamp);

        $this->post('/portal/verificar', ['codigo' => '123456'])
            ->assertRedirect(route('portal.consulta'))
            ->assertSessionHasErrors(['cpf' => 'Sua verificação expirou. Informe o CPF e a data de nascimento novamente.']);
        $this->get('/portal/resultados')->assertRedirect(route('portal.consulta'));
    }

    public function test_cpf_enviado_no_corpo_e_ignorado_vale_o_da_sessao(): void
    {
        $meu = $this->aluno(['email' => 'aluno@example.com']);
        $outro = $this->aluno(['ra' => '2026002', 'cpf' => '98765432100', 'email' => 'outro@example.com']);
        VerificacaoEmail::create(['cpf' => $outro->cpf, 'codigo' => $this->codigoGuardado($outro), 'expira_em' => Carbon::now()->addMinutes(10)]);
        VerificacaoEmail::create(['cpf' => $meu->cpf, 'codigo' => $this->codigoGuardado($meu, '654321'), 'expira_em' => Carbon::now()->addMinutes(10)]);
        $this->primeiroFator($meu);

        // Mandar o CPF do outro e o código dele não entra na conta dele.
        $this->post('/portal/verificar', ['cpf' => $outro->cpf, 'codigo' => '123456'])->assertOk()->assertSee('Código incorreto');

        $this->assertDatabaseHas('verificacoes_email', ['cpf' => $meu->cpf, 'tentativas_falhas' => 1]);
        $this->assertDatabaseHas('verificacoes_email', ['cpf' => $outro->cpf, 'tentativas_falhas' => 0]);
        $this->get('/portal/resultados')->assertRedirect(route('portal.consulta'));
    }

    public function test_reenviar_gera_um_codigo_novo_e_o_antigo_deixa_de_valer(): void
    {
        Configuracao::definir('smtp_ativo', '1');
        $aluno = $this->aluno(['email' => 'aluno@example.com']);
        $velho = $this->consultarEReceberCodigo($aluno);
        VerificacaoEmail::query()->update(['tentativas_falhas' => 2, 'criado_em' => Carbon::now()->subMinutes(5)]);

        $corpo = '';
        $this->mock(SmtpEmailSender::class, function (MockInterface $mock) use (&$corpo) {
            $mock->shouldReceive('enviar')->once()->andReturnUsing(function ($para, $assunto, $texto) use (&$corpo) {
                $corpo = $texto;
            });
        });
$this->post('/portal/reenviar')->assertOk()->assertSee('Código reenviado com sucesso.');
        preg_match('/\b(\d{6})\b/', $corpo, $m);
        $novo = $m[1];

        $this->assertDatabaseHas('verificacoes_email', ['cpf' => $aluno->cpf, 'tentativas_falhas' => 0, 'vezes_reenviado' => 1]);
        $this->assertSame(VerificacaoEmail::hashDoCodigo($aluno->cpf, $novo), VerificacaoEmail::firstOrFail()->codigo);

        if ($velho !== $novo) {
            $this->post('/portal/verificar', ['codigo' => $velho])->assertSee('Código incorreto');
        }
        $this->post('/portal/verificar', ['codigo' => $novo])->assertRedirect(route('portal.resultados'));
    }

    public function test_consultas_erradas_de_varios_ips_bloqueiam_o_cpf(): void
    {
        $aluno = $this->aluno();

        // 10 chutes de data de nascimento para o MESMO CPF, cada um de um IP diferente (o throttle por IP não pega).
        for ($i = 1; $i <= 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
                ->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '01/01/19'.(70 + $i)])
                ->assertSessionHasErrors(['cpf' => 'Nenhum aluno encontrado com este CPF e Data de Nascimento.']);
        }

        // O 11º, de um IP novo e mesmo com a data CERTA, é recusado: o CPF ficou bloqueado.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '15/03/2000'])
            ->assertSessionHasErrors('cpf');
        $this->assertStringContainsString('Muitas tentativas para este CPF', session('errors')->first('cpf'));

        // Outro CPF não é afetado.
        $outro = $this->aluno(['ra' => '2026002', 'cpf' => '98765432100']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.98'])
            ->post('/portal/consultar', ['cpf' => $outro->cpf, 'data_nascimento' => '15/03/2000'])
            ->assertRedirect(route('portal.resultados'));
    }

    public function test_cpf_que_nao_existe_tambem_conta_e_a_resposta_e_a_mesma(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.1.0.{$i}"])
                ->post('/portal/consultar', ['cpf' => '11144477735', 'data_nascimento' => '01/01/2000'])
                ->assertSessionHasErrors(['cpf' => 'Nenhum aluno encontrado com este CPF e Data de Nascimento.']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.99'])
            ->post('/portal/consultar', ['cpf' => '11144477735', 'data_nascimento' => '01/01/2000'])
            ->assertSessionHasErrors('cpf');
        $this->assertStringContainsString('Muitas tentativas', session('errors')->first('cpf'));
    }

    public function test_acertar_a_consulta_zera_o_contador_do_cpf(): void
    {
        $aluno = $this->aluno();

        for ($i = 1; $i <= 9; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.2.0.{$i}"])
                ->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '01/01/1990']);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.2.0.50'])
            ->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '15/03/2000'])
            ->assertRedirect(route('portal.resultados'));

        // Contador zerado: mais 9 erros ainda não bloqueiam.
        for ($i = 1; $i <= 9; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.3.0.{$i}"])
                ->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '01/01/1990']);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.3.0.50'])
            ->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '15/03/2000'])
            ->assertRedirect(route('portal.resultados'));
    }

    public function test_captcha_ativo_exige_token(): void
    {
        Configuracao::definir('recaptcha_ativo', '1');
        Configuracao::definir('recaptcha_secret_key', 'secreto');
        $this->aluno();

        $response = $this->post('/portal/consultar', [
            'cpf' => '123.456.789-09',
            'data_nascimento' => '15/03/2000',
        ]);

        $response->assertSessionHasErrors('captcha');
    }

    public function test_captcha_valido_permite_consulta(): void
    {
        Configuracao::definir('recaptcha_ativo', '1');
        Configuracao::definir('recaptcha_secret_key', 'secreto');
        $this->aluno();

        $this->mock(CaptchaVerifier::class, function (MockInterface $mock) {
            $mock->shouldReceive('verificarRecaptcha')->once()->with('secreto', 'token-valido')->andReturn(true);
        });

        $response = $this->followingRedirects()->post('/portal/consultar', [
            'cpf' => '123.456.789-09',
            'data_nascimento' => '15/03/2000',
            'g-recaptcha-response' => 'token-valido',
        ]);

        $response->assertOk();
    }

    public function test_captcha_ativo_sem_secret_key_recusa_a_consulta_em_vez_de_pular_a_verificacao(): void
    {
        $this->aluno();

        foreach (['recaptcha' => 'g-recaptcha-response', 'hcaptcha' => 'h-captcha-response'] as $tipo => $campo) {
            Configuracao::definir('recaptcha_ativo', $tipo === 'recaptcha' ? '1' : '0');
            Configuracao::definir('hcaptcha_ativo', $tipo === 'hcaptcha' ? '1' : '0');
            Configuracao::definir('recaptcha_secret_key', '');
            Configuracao::definir('hcaptcha_secret_key', '');

            // O verificador nem deve ser chamado: qualquer texto no token NÃO pode bastar.
            $this->mock(CaptchaVerifier::class, function (MockInterface $mock) {
                $mock->shouldNotReceive('verificarRecaptcha');
                $mock->shouldNotReceive('verificarHcaptcha');
            });

            $this->post('/portal/consultar', [
                'cpf' => '123.456.789-09',
                'data_nascimento' => '15/03/2000',
                $campo => 'qualquer-coisa',
            ])->assertSessionHasErrors('captcha');

            $this->assertDatabaseCount('verificacoes_email', 0);
        }
    }

    public function test_verificar_codigo_correto_mostra_resultados(): void
    {
        $aluno = $this->aluno(['email' => 'aluno@example.com']);
        $avaliacao = Avaliacao::create(['nome' => 'ENADE 2026']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => $aluno->ra, 'questao_numero' => 1, 'resposta' => 'A']);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        VerificacaoEmail::create([
            'cpf' => $aluno->cpf,
            'codigo' => $this->codigoGuardado($aluno),
            'expira_em' => Carbon::now()->addMinutes(10),
        ]);

        $this->primeiroFator($aluno);
        $response = $this->followingRedirects()->post('/portal/verificar', ['codigo' => '123456']);

        $response->assertOk();
        $response->assertSee('ENADE 2026');
        $this->assertDatabaseMissing('verificacoes_email', ['cpf' => $aluno->cpf]);
    }

    public function test_verificar_codigo_errado_incrementa_tentativas(): void
    {
        $aluno = $this->aluno();
        VerificacaoEmail::create([
            'cpf' => $aluno->cpf,
            'codigo' => $this->codigoGuardado($aluno),
            'expira_em' => Carbon::now()->addMinutes(10),
        ]);

        $this->primeiroFator($aluno);
        $response = $this->post('/portal/verificar', ['codigo' => '000000']);

        $response->assertOk();
        $response->assertSee('Código incorreto');
        $this->assertDatabaseHas('verificacoes_email', ['cpf' => $aluno->cpf, 'tentativas_falhas' => 1]);
    }

    public function test_verificar_bloqueia_apos_3_tentativas_falhas(): void
    {
        $aluno = $this->aluno();
        VerificacaoEmail::create([
            'cpf' => $aluno->cpf,
            'codigo' => $this->codigoGuardado($aluno),
            'tentativas_falhas' => 2,
            'expira_em' => Carbon::now()->addMinutes(10),
        ]);

        $this->primeiroFator($aluno);
        $response = $this->post('/portal/verificar', ['codigo' => '000000']);

        $response->assertRedirect(route('portal.consulta'));
        $response->assertSessionHasErrors('cpf');
        $this->assertDatabaseHas('rate_limit_2fa', ['tentativas' => 1]);
    }

    public function test_ip_bloqueado_impede_verificar(): void
    {
        $aluno = $this->aluno();
        VerificacaoEmail::create([
            'cpf' => $aluno->cpf,
            'codigo' => $this->codigoGuardado($aluno),
            'expira_em' => Carbon::now()->addMinutes(10),
        ]);
        RateLimit2fa::create([
            'ip_address' => '127.0.0.1',
            'tentativas' => 10,
            'bloqueado_ate' => Carbon::now()->addMinutes(30),
        ]);

        $this->primeiroFator($aluno);
        $response = $this->post('/portal/verificar', ['codigo' => '123456']);

        $response->assertRedirect(route('portal.consulta'));
        $response->assertSessionHasErrors(['cpf' => 'Muitas tentativas deste dispositivo. Tente novamente em 1 hora.']);
    }

    public function test_codigo_expirado_pede_nova_consulta(): void
    {
        $aluno = $this->aluno();
        VerificacaoEmail::create([
            'cpf' => $aluno->cpf,
            'codigo' => $this->codigoGuardado($aluno),
            'expira_em' => Carbon::now()->subMinute(),
        ]);

        $this->primeiroFator($aluno);
        $response = $this->post('/portal/verificar', ['codigo' => '123456']);

        $response->assertRedirect(route('portal.consulta'));
        $response->assertSessionHasErrors(['cpf' => 'Código expirado. Solicite um novo código.']);
    }

    public function test_reenviar_respeita_cooldown_de_1_minuto_na_primeira_vez(): void
    {
        $aluno = $this->aluno(['email' => 'aluno@example.com']);
        VerificacaoEmail::create([
            'cpf' => $aluno->cpf,
            'codigo' => $this->codigoGuardado($aluno),
            'expira_em' => Carbon::now()->addMinutes(10),
        ]);

        $this->primeiroFator($aluno);
        $response = $this->post('/portal/reenviar');

        $response->assertOk();
        $response->assertSee('Aguarde');
    }

    public function test_reenviar_apos_cooldown_envia_novamente(): void
    {
        $aluno = $this->aluno(['email' => 'aluno@example.com']);
        $verificacao = VerificacaoEmail::create([
            'cpf' => $aluno->cpf,
            'codigo' => $this->codigoGuardado($aluno),
            'expira_em' => Carbon::now()->addMinutes(10),
        ]);
        $verificacao->forceFill(['criado_em' => Carbon::now()->subMinutes(2)])->save();

        $this->mock(SmtpEmailSender::class, function (MockInterface $mock) {
            $mock->shouldReceive('enviar')->once();
        });

        $this->primeiroFator($aluno);
        $response = $this->post('/portal/reenviar');

        $response->assertOk();
        $response->assertSee('Código reenviado com sucesso.');
        $this->assertDatabaseHas('verificacoes_email', ['cpf' => $aluno->cpf, 'vezes_reenviado' => 1]);
    }

    public function test_consultar_tem_throttle_apertado(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/portal/consultar', []);
        }

        $this->post('/portal/consultar', [])->assertStatus(429);
    }

    public function test_navegar_pelos_resultados_nao_compartilha_o_throttle_de_consultar(): void
    {
        $aluno = $this->aluno();
        $this->post('/portal/consultar', [
            'cpf' => '123.456.789-09',
            'data_nascimento' => '15/03/2000',
        ]);

        // Mais chamadas do que o limite de /portal/consultar (10) — como são
        // rotas sem throttle compartilhado, nenhuma delas deveria travar.
        for ($i = 0; $i < 15; $i++) {
            $this->get('/portal/resultados')->assertOk();
        }
    }
}
