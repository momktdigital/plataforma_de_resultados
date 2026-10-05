<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Configuracao;
use App\Models\LoginCodigo;
use App\Models\VerificacaoEmail;
use App\Services\Portal\SmtpEmailSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Coordenador entra com um código enviado ao e-mail (sem senha); só administrador
 * é obrigado a ter senha. E o código de 2FA do aluno vai para o e-mail pessoal ou
 * o acadêmico, conforme a configuração do portal.
 */
class LoginCodigoCoordenadorTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{0: string, 1: string, 2: string}> e-mails capturados: [destinatário, assunto, corpo] */
    private array $enviados = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->enviados = [];
        $this->mock(SmtpEmailSender::class, function (MockInterface $mock) {
            $mock->shouldReceive('enviar')->andReturnUsing(function (string $para, string $assunto, string $corpo) {
                $this->enviados[] = [$para, $assunto, $corpo];
            });
        });
        Configuracao::definir('smtp_ativo', '1');

        // Sem nenhum administrador o app cai no instalador (middleware `instalado`).
        Admin::create(['username' => 'instalador', 'password_hash' => Hash::make('x'), 'role' => Admin::ROLE_ADMIN]);
    }

    private function coordenador(string $username = 'coord', ?string $email = 'coord@faa.edu.br', ?string $senha = null): Admin
    {
        return Admin::create([
            'username' => $username,
            'email' => $email,
            'password_hash' => Hash::make($senha ?? 'aleatoria-que-ninguem-sabe-9876'),
            'role' => Admin::ROLE_COORDENADOR,
        ]);
    }

    /** O código de 6 dígitos do último e-mail enviado. */
    private function ultimoCodigo(): string
    {
        $this->assertNotEmpty($this->enviados, 'nenhum e-mail enviado');
        preg_match('/<b>(\d{6})<\/b>/', end($this->enviados)[2], $m);

        return $m[1];
    }

    private function pedirCodigo(string $identificador = 'coord'): void
    {
        $this->post('/login/codigo', ['identificador' => $identificador])->assertRedirect(route('login.codigo.form'));
    }

    public function test_login_do_coordenador_com_codigo_enviado_ao_email(): void
    {
        $coordenador = $this->coordenador();

        $this->pedirCodigo();

        $this->assertCount(1, $this->enviados);
        $this->assertSame('coord@faa.edu.br', $this->enviados[0][0]);
        $this->assertDatabaseCount('login_codigos', 1);
        $this->assertStringNotContainsString($this->ultimoCodigo(), json_encode(LoginCodigo::first()->toArray()), 'só o hash do código é guardado');

        $this->post('/login/codigo/verificar', ['codigo' => $this->ultimoCodigo()])
            ->assertRedirect(route('coordenador.painel'));

        $this->assertAuthenticatedAs($coordenador, 'admin');
        $this->assertDatabaseCount('login_codigos', 0); // o código vale uma vez só
    }

    public function test_aceita_o_email_no_lugar_do_usuario(): void
    {
        $this->coordenador('coord', 'coord@faa.edu.br');

        $this->pedirCodigo('coord@faa.edu.br');

        $this->post('/login/codigo/verificar', ['codigo' => $this->ultimoCodigo()])->assertRedirect(route('coordenador.painel'));
        $this->assertAuthenticated('admin');
    }

    public function test_codigo_errado_e_o_limite_de_tentativas(): void
    {
        $this->coordenador();
        $this->pedirCodigo();
        $certo = $this->ultimoCodigo();
        $errado = $certo === '000000' ? '111111' : '000000';

        foreach (range(1, 3) as $_) {
            $this->post('/login/codigo/verificar', ['codigo' => $errado])->assertSessionHasErrors('codigo');
        }

        // Depois de 3 erros o código antigo morre, mesmo o certo.
        $this->post('/login/codigo/verificar', ['codigo' => $certo])->assertSessionHasErrors('codigo');
        $this->assertGuest('admin');
    }

    public function test_codigo_expirado_nao_entra(): void
    {
        $this->coordenador();
        $this->pedirCodigo();
        LoginCodigo::query()->update(['expira_em' => now()->subMinute()]);

        $this->post('/login/codigo/verificar', ['codigo' => $this->ultimoCodigo()])->assertSessionHasErrors('codigo');
        $this->assertGuest('admin');
    }

    public function test_um_novo_codigo_invalida_o_anterior(): void
    {
        $this->coordenador();
        $this->pedirCodigo();
        $primeiro = $this->ultimoCodigo();

        // Pedido refeito (nova sessão de login): só o último código vale.
        $this->pedirCodigo();
        $segundo = $this->ultimoCodigo();

        if ($primeiro !== $segundo) {
            $this->post('/login/codigo/verificar', ['codigo' => $primeiro])->assertSessionHasErrors('codigo');
            $this->assertGuest('admin');
        }
        $this->post('/login/codigo/verificar', ['codigo' => $segundo])->assertRedirect(route('coordenador.painel'));
    }

    public function test_a_resposta_nao_revela_se_o_usuario_existe(): void
    {
        $this->coordenador();

        $mensagem = 'Se houver um coordenador ou reitor com esse usuário ou e-mail, enviamos um código de acesso para o e-mail cadastrado. Ele vale por 10 minutos.';

        $existente = $this->post('/login/codigo', ['identificador' => 'coord']);
        $existente->assertRedirect(route('login.codigo.form'))->assertSessionHas('status', $mensagem);
        $this->flushSession();
        $inexistente = $this->post('/login/codigo', ['identificador' => 'fulano-que-nao-existe']);
        $inexistente->assertRedirect(route('login.codigo.form'))->assertSessionHas('status', $mensagem);

        $this->assertCount(1, $this->enviados, 'só o usuário real recebe e-mail');

        $this->flushSession();
        $this->post('/login/codigo', ['identificador' => 'fulano-que-nao-existe']);
        $this->post('/login/codigo/verificar', ['codigo' => '123456'])
            ->assertSessionHasErrors(['codigo' => 'Código inválido ou expirado. Confira o código ou peça um novo.']);
    }

    public function test_administrador_nao_entra_por_codigo(): void
    {
        Admin::create(['username' => 'adm', 'email' => 'adm@faa.edu.br', 'password_hash' => Hash::make('senha-do-admin-123'), 'role' => Admin::ROLE_ADMIN]);

        $this->pedirCodigo('adm');

        $this->assertEmpty($this->enviados, 'administrador não recebe código: a senha é obrigatória para ele');
        $this->post('/login/codigo/verificar', ['codigo' => '123456'])->assertSessionHasErrors('codigo');
        $this->assertGuest('admin');
    }

    public function test_coordenador_sem_email_nao_recebe_codigo(): void
    {
        $this->coordenador('sememail', null);

        $this->pedirCodigo('sememail');

        $this->assertEmpty($this->enviados);
    }

    public function test_sem_smtp_ativado_avisa_que_o_acesso_por_codigo_nao_esta_disponivel(): void
    {
        Configuracao::definir('smtp_ativo', '0');
        $this->coordenador();

        $this->post('/login/codigo', ['identificador' => 'coord'])->assertSessionHasErrors('identificador');

        $this->assertEmpty($this->enviados);
    }

    public function test_pedidos_demais_de_codigo_para_o_mesmo_usuario_sao_barrados(): void
    {
        $this->coordenador();

        foreach (range(1, 3) as $_) {
            $this->pedirCodigo();
        }
        $this->post('/login/codigo', ['identificador' => 'coord'])->assertSessionHasErrors('identificador');

        $this->assertCount(3, $this->enviados, 'o 4º pedido não manda e-mail');
    }

    public function test_reenvio_tem_espera_minima(): void
    {
        $this->coordenador();
        $this->pedirCodigo();

        $this->post('/login/codigo/reenviar')->assertSessionHasErrors('codigo');
        $this->assertCount(1, $this->enviados);

        $this->withSession(['login_codigo_ultimo_envio' => now()->subMinutes(2)->timestamp]);
        LoginCodigo::query()->update(['criado_em' => now()->subMinutes(3)]);
        $this->post('/login/codigo/reenviar')->assertSessionHas('status');
        $this->assertCount(2, $this->enviados);
        $this->assertStringContainsString('[Reenvio]', $this->enviados[1][1]);
    }

    public function test_pagina_de_login_tem_as_duas_abas(): void
    {
        $this->get('/login')->assertOk()->assertSee('Administrador')->assertSee('Coordenação / Reitoria')->assertSee('name="password"', false);
        $this->get('/login?modo=codigo')->assertOk()->assertSee('Enviar código')->assertDontSee('name="password"', false);
    }

    public function test_cria_coordenador_sem_senha_mas_com_email(): void
    {
        $admin = Admin::create(['username' => 'adm', 'password_hash' => Hash::make('x'), 'role' => Admin::ROLE_ADMIN]);
        \App\Models\Curso::create(['nome' => 'DIREITO']);

        $this->actingAs($admin, 'admin')->post('/usuarios', [
            'papel' => 'coordenador', 'username' => 'novo', 'email' => 'novo@faa.edu.br', 'cursos' => ['DIREITO'],
        ])->assertRedirect(route('usuarios.index', ['aba' => 'coordenadores']));

        $novo = Admin::where('username', 'novo')->firstOrFail();
        $this->assertNotEmpty($novo->password_hash, 'password_hash é NOT NULL no schema legado');

        // Login por senha não passa (a senha é um valor aleatório que ninguém conhece)...
        $this->post('/logout');
        $this->post('/login', ['username' => 'novo', 'password' => ''])->assertSessionHasErrors();
        // ...mas o código por e-mail passa.
        $this->pedirCodigo('novo');
        $this->post('/login/codigo/verificar', ['codigo' => $this->ultimoCodigo()])->assertRedirect(route('coordenador.painel'));
    }

    public function test_senha_continua_obrigatoria_para_administrador_e_email_para_coordenador(): void
    {
        $admin = Admin::create(['username' => 'adm', 'password_hash' => Hash::make('x'), 'role' => Admin::ROLE_ADMIN]);
        \App\Models\Curso::create(['nome' => 'DIREITO']);

        $this->actingAs($admin, 'admin')->post('/usuarios', ['papel' => 'administrador', 'username' => 'outro-adm'])
            ->assertSessionHasErrors('password');

        $this->actingAs($admin, 'admin')->post('/usuarios', ['papel' => 'coordenador', 'username' => 'c2', 'cursos' => ['DIREITO']])
            ->assertSessionHasErrors('email');
    }

    public function test_coordenador_com_senha_cadastrada_tambem_pode_entrar_por_senha(): void
    {
        $this->coordenador('comsenha', 'comsenha@faa.edu.br', 'minha-senha-bem-longa-1');

        $this->post('/login', ['username' => 'comsenha', 'password' => 'minha-senha-bem-longa-1'])
            ->assertRedirect(route('coordenador.painel'));
    }

    public function test_aba_do_coordenador_oferece_o_botao_entrar_com_senha(): void
    {
        $this->get('/login?modo=codigo')
            ->assertOk()
            ->assertSee('Entrar com senha')
            ->assertSee(route('login', ['modo' => 'coordenador']), false);
    }

    public function test_aba_do_coordenador_no_modo_senha_tem_usuario_senha_e_volta_para_o_codigo(): void
    {
        $this->get('/login?modo=coordenador')
            ->assertOk()
            ->assertSee('name="password"', false)
            ->assertSee('Entrar no meu painel')
            ->assertSee('Receber código por e-mail')
            ->assertSee(route('login', ['modo' => 'codigo']), false)
            ->assertDontSee('Enviar código');
    }

    public function test_modo_senha_do_coordenador_mantem_a_aba_coordenador_ativa(): void
    {
        $html = $this->get('/login?modo=coordenador')->getContent();

        // A aba "Coordenador" é a selecionada (aria-selected="true") e a de administrador não.
        $this->assertMatchesRegularExpression('/aria-selected="false"[^>]*>\s*Administrador/s', $html);
        $this->assertMatchesRegularExpression('/aria-selected="true"[^>]*>\s*Coordenação \/ Reitoria/s', $html);
    }

    public function test_aba_do_administrador_nao_mostra_o_botao_do_coordenador(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('Entrar com senha')->assertDontSee('Receber código por e-mail');
    }

    public function test_coordenador_que_entra_por_senha_vai_para_o_painel_e_nao_para_a_lista_do_admin(): void
    {
        $this->coordenador('comsenha', 'comsenha@faa.edu.br', 'minha-senha-bem-longa-1');

        $this->post('/login', ['username' => 'comsenha', 'password' => 'minha-senha-bem-longa-1'])
            ->assertRedirect(route('coordenador.painel'));

        $this->assertAuthenticated('admin');
        $this->get(route('coordenador.painel'))->assertOk();
    }

    public function test_senha_errada_do_coordenador_volta_para_a_aba_do_coordenador(): void
    {
        $this->coordenador('comsenha', 'comsenha@faa.edu.br', 'minha-senha-bem-longa-1');

        $this->from('/login?modo=coordenador')
            ->post('/login', ['username' => 'comsenha', 'password' => 'errada'])
            ->assertRedirect('/login?modo=coordenador')
            ->assertSessionHasErrors('username');

        $this->assertGuest('admin');
    }

    public function test_coordenador_sem_senha_nao_entra_por_senha_e_a_mensagem_nao_revela_isso(): void
    {
        $this->coordenador('semsenha', 'semsenha@faa.edu.br');

        $this->post('/login', ['username' => 'semsenha', 'password' => 'qualquer-coisa'])
            ->assertSessionHasErrors(['username' => 'Usuário ou senha inválidos.']);

        $this->assertGuest('admin');
    }

    // ---------------- destino do e-mail de 2FA do aluno ----------------

    private function aluno(): Aluno
    {
        return Aluno::create([
            'ra' => '67482', 'nome' => 'Sofia', 'cpf' => '12345678909', 'data_nascimento' => '2007-09-05',
            'email' => 'sofia.pessoal@gmail.com',
        ]);
    }

    private function consultarComoAluno(): void
    {
        $this->post('/portal/consultar', ['cpf' => '123.456.789-09', 'data_nascimento' => '05/09/2007'])->assertOk();
    }

    public function test_codigo_do_aluno_vai_para_o_email_pessoal_por_padrao(): void
    {
        $this->aluno();

        $this->consultarComoAluno();

        $this->assertSame('sofia.pessoal@gmail.com', $this->enviados[0][0]);
    }

    public function test_codigo_do_aluno_pode_ir_para_o_email_academico(): void
    {
        $this->aluno();
        Configuracao::definir('email_destino_2fa', 'academico');

        $this->consultarComoAluno();

        $this->assertSame('67482@somos.unifaa.edu.br', $this->enviados[0][0]);
        $this->assertNotEmpty(VerificacaoEmail::all());
    }

    public function test_email_academico_dispensa_o_pessoal_e_o_pessoal_exige_cadastro(): void
    {
        $aluno = $this->aluno();
        $aluno->update(['email' => null]);

        // Pessoal escolhido e aluno sem e-mail: bloqueia com aviso.
        $this->post('/portal/consultar', ['cpf' => '123.456.789-09', 'data_nascimento' => '05/09/2007'])
            ->assertSessionHasErrors('cpf');
        $this->assertEmpty($this->enviados);

        // Acadêmico escolhido: funciona mesmo sem e-mail pessoal.
        Configuracao::definir('email_destino_2fa', 'academico');
        $this->consultarComoAluno();
        $this->assertSame('67482@somos.unifaa.edu.br', $this->enviados[0][0]);
    }

    public function test_reenvio_do_codigo_do_aluno_respeita_o_destino_escolhido(): void
    {
        $this->aluno();
        Configuracao::definir('email_destino_2fa', 'academico');
        $this->consultarComoAluno();
        VerificacaoEmail::query()->update(['criado_em' => now()->subMinutes(5)]);

        $this->post('/portal/reenviar', ['cpf' => '12345678909'])->assertOk();

        $this->assertCount(2, $this->enviados);
        $this->assertSame('67482@somos.unifaa.edu.br', $this->enviados[1][0]);
    }

    public function test_tela_de_configuracao_salva_o_destino_do_email_do_aluno(): void
    {
        $admin = Admin::create(['username' => 'adm', 'password_hash' => Hash::make('x'), 'role' => Admin::ROLE_ADMIN]);

        $this->actingAs($admin, 'admin')->get('/sistema/portal')
            ->assertOk()
            ->assertSee('E-mail pessoal')
            ->assertSee('E-mail acadêmico')
            ->assertSee('RA@somos.unifaa.edu.br');

        $this->actingAs($admin, 'admin')->put('/sistema/portal/smtp', ['smtp_ativo' => '1', 'email_destino_2fa' => 'academico'])
            ->assertRedirect();
        $this->assertSame('academico', Configuracao::valor('email_destino_2fa'));

        $this->actingAs($admin, 'admin')->get('/sistema/portal')->assertSee('value="academico" class="mt-1" checked', false);

        $this->actingAs($admin, 'admin')->put('/sistema/portal/smtp', ['smtp_ativo' => '1', 'email_destino_2fa' => 'qualquer-coisa'])
            ->assertSessionHasErrors('email_destino_2fa');
    }
}
