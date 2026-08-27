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

    public function test_verificar_codigo_correto_mostra_resultados(): void
    {
        $aluno = $this->aluno(['email' => 'aluno@example.com']);
        $avaliacao = Avaliacao::create(['nome' => 'ENADE 2026']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => $aluno->ra, 'questao_numero' => 1, 'resposta' => 'A']);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        VerificacaoEmail::create([
            'cpf' => $aluno->cpf,
            'codigo' => '123456',
            'expira_em' => Carbon::now()->addMinutes(10),
        ]);

        $response = $this->followingRedirects()->post('/portal/verificar', ['cpf' => $aluno->cpf, 'codigo' => '123456']);

        $response->assertOk();
        $response->assertSee('ENADE 2026');
        $this->assertDatabaseMissing('verificacoes_email', ['cpf' => $aluno->cpf]);
    }

    public function test_verificar_codigo_errado_incrementa_tentativas(): void
    {
        $aluno = $this->aluno();
        VerificacaoEmail::create([
            'cpf' => $aluno->cpf,
            'codigo' => '123456',
            'expira_em' => Carbon::now()->addMinutes(10),
        ]);

        $response = $this->post('/portal/verificar', ['cpf' => $aluno->cpf, 'codigo' => '000000']);

        $response->assertOk();
        $response->assertSee('Código incorreto');
        $this->assertDatabaseHas('verificacoes_email', ['cpf' => $aluno->cpf, 'tentativas_falhas' => 1]);
    }

    public function test_verificar_bloqueia_apos_3_tentativas_falhas(): void
    {
        $aluno = $this->aluno();
        VerificacaoEmail::create([
            'cpf' => $aluno->cpf,
            'codigo' => '123456',
            'tentativas_falhas' => 2,
            'expira_em' => Carbon::now()->addMinutes(10),
        ]);

        $response = $this->post('/portal/verificar', ['cpf' => $aluno->cpf, 'codigo' => '000000']);

        $response->assertRedirect(route('portal.consulta'));
        $response->assertSessionHasErrors('cpf');
        $this->assertDatabaseHas('rate_limit_2fa', ['tentativas' => 1]);
    }

    public function test_ip_bloqueado_impede_verificar(): void
    {
        $aluno = $this->aluno();
        VerificacaoEmail::create([
            'cpf' => $aluno->cpf,
            'codigo' => '123456',
            'expira_em' => Carbon::now()->addMinutes(10),
        ]);
        RateLimit2fa::create([
            'ip_address' => '127.0.0.1',
            'tentativas' => 10,
            'bloqueado_ate' => Carbon::now()->addMinutes(30),
        ]);

        $response = $this->post('/portal/verificar', ['cpf' => $aluno->cpf, 'codigo' => '123456']);

        $response->assertRedirect(route('portal.consulta'));
        $response->assertSessionHasErrors(['cpf' => 'Muitas tentativas deste dispositivo. Tente novamente em 1 hora.']);
    }

    public function test_codigo_expirado_pede_nova_consulta(): void
    {
        $aluno = $this->aluno();
        VerificacaoEmail::create([
            'cpf' => $aluno->cpf,
            'codigo' => '123456',
            'expira_em' => Carbon::now()->subMinute(),
        ]);

        $response = $this->post('/portal/verificar', ['cpf' => $aluno->cpf, 'codigo' => '123456']);

        $response->assertRedirect(route('portal.consulta'));
        $response->assertSessionHasErrors(['cpf' => 'Código expirado. Solicite um novo código.']);
    }

    public function test_reenviar_respeita_cooldown_de_1_minuto_na_primeira_vez(): void
    {
        $aluno = $this->aluno(['email' => 'aluno@example.com']);
        VerificacaoEmail::create([
            'cpf' => $aluno->cpf,
            'codigo' => '123456',
            'expira_em' => Carbon::now()->addMinutes(10),
        ]);

        $response = $this->post('/portal/reenviar', ['cpf' => $aluno->cpf]);

        $response->assertOk();
        $response->assertSee('Aguarde');
    }

    public function test_reenviar_apos_cooldown_envia_novamente(): void
    {
        $aluno = $this->aluno(['email' => 'aluno@example.com']);
        $verificacao = VerificacaoEmail::create([
            'cpf' => $aluno->cpf,
            'codigo' => '123456',
            'expira_em' => Carbon::now()->addMinutes(10),
        ]);
        $verificacao->forceFill(['criado_em' => Carbon::now()->subMinutes(2)])->save();

        $this->mock(SmtpEmailSender::class, function (MockInterface $mock) {
            $mock->shouldReceive('enviar')->once();
        });

        $response = $this->post('/portal/reenviar', ['cpf' => $aluno->cpf]);

        $response->assertOk();
        $response->assertSee('Código reenviado com sucesso.');
        $this->assertDatabaseHas('verificacoes_email', ['cpf' => $aluno->cpf, 'vezes_reenviado' => 1]);
    }
}
