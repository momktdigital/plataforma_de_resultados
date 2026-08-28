<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Configuracao;
use App\Models\RedefinicaoSenha;
use App\Services\Portal\SmtpEmailSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery\MockInterface;
use Tests\TestCase;

class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function ativarSmtp(): void
    {
        Configuracao::definir('smtp_ativo', '1');
    }

    public function test_formulario_e_acessivel(): void
    {
        Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);

        $this->get('/esqueci-senha')->assertOk();
    }

    public function test_solicitar_para_admin_com_email_envia_link(): void
    {
        $this->ativarSmtp();
        $admin = Admin::create(['username' => 'coordenador', 'email' => 'coordenador@example.com', 'password_hash' => bcrypt('x')]);

        $this->mock(SmtpEmailSender::class, function (MockInterface $mock) {
            $mock->shouldReceive('enviar')
                ->once()
                ->withArgs(fn ($destinatario, $assunto) => $destinatario === 'coordenador@example.com' && str_contains($assunto, 'Redefinição'));
        });

        $response = $this->post('/esqueci-senha', ['username' => 'coordenador']);

        $response->assertRedirect(route('login'));
        $this->assertDatabaseCount('redefinicoes_senha', 1);
        $this->assertNotSame('', RedefinicaoSenha::firstOrFail()->token_hash);
    }

    public function test_solicitar_para_usuario_inexistente_nao_revela_isso(): void
    {
        $this->ativarSmtp();
        Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);

        $response = $this->post('/esqueci-senha', ['username' => 'nao-existe']);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');
        $this->assertDatabaseCount('redefinicoes_senha', 0);
    }

    public function test_solicitar_para_admin_sem_email_nao_envia_nada(): void
    {
        $this->ativarSmtp();
        Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);

        $this->mock(SmtpEmailSender::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('enviar');
        });

        $this->post('/esqueci-senha', ['username' => 'coordenador'])
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('redefinicoes_senha', 0);
    }

    public function test_solicitar_sem_smtp_configurado_nao_envia_nada(): void
    {
        Admin::create(['username' => 'coordenador', 'email' => 'coordenador@example.com', 'password_hash' => bcrypt('x')]);

        $this->mock(SmtpEmailSender::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('enviar');
        });

        $this->post('/esqueci-senha', ['username' => 'coordenador'])
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('redefinicoes_senha', 0);
    }

    public function test_link_com_token_valido_permite_redefinir(): void
    {
        $admin = Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('senha-antiga')]);
        $token = 'token-de-teste-1234567890';
        RedefinicaoSenha::create([
            'admin_id' => $admin->id,
            'token_hash' => hash('sha256', $token),
            'expira_em' => now()->addHour(),
        ]);

        $this->get("/redefinir-senha/{$token}")->assertOk();

        $response = $this->post("/redefinir-senha/{$token}", [
            'password' => 'senha-nova-123',
            'password_confirmation' => 'senha-nova-123',
        ]);

        $response->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('senha-nova-123', $admin->fresh()->password_hash));
        $this->assertDatabaseCount('redefinicoes_senha', 0);
        $this->assertDatabaseHas('atividades', [
            'acao' => 'administrador.senha_redefinida_via_email',
            'alvo_id' => (string) $admin->id,
        ]);
    }

    public function test_rejeita_senha_curta_demais_ao_redefinir(): void
    {
        $admin = Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('senha-antiga')]);
        $token = 'token-de-teste-1234567890';
        RedefinicaoSenha::create([
            'admin_id' => $admin->id,
            'token_hash' => hash('sha256', $token),
            'expira_em' => now()->addHour(),
        ]);

        $response = $this->post("/redefinir-senha/{$token}", [
            'password' => 'curta123',
            'password_confirmation' => 'curta123',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('senha-antiga', $admin->fresh()->password_hash));
    }

    public function test_token_expirado_e_rejeitado(): void
    {
        $admin = Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('senha-antiga')]);
        $token = 'token-expirado';
        RedefinicaoSenha::create([
            'admin_id' => $admin->id,
            'token_hash' => hash('sha256', $token),
            'expira_em' => now()->subMinute(),
        ]);

        $response = $this->get("/redefinir-senha/{$token}");

        $response->assertRedirect(route('senha.esqueci'));
        $response->assertSessionHasErrors('token');
    }

    public function test_token_invalido_e_rejeitado(): void
    {
        Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);

        $response = $this->post('/redefinir-senha/token-que-nao-existe', [
            'password' => 'senha-nova-123',
            'password_confirmation' => 'senha-nova-123',
        ]);

        $response->assertRedirect(route('senha.esqueci'));
        $response->assertSessionHasErrors('token');
    }

    public function test_solicitar_novo_link_invalida_o_anterior(): void
    {
        $this->ativarSmtp();
        $admin = Admin::create(['username' => 'coordenador', 'email' => 'coordenador@example.com', 'password_hash' => bcrypt('x')]);

        $this->mock(SmtpEmailSender::class, function (MockInterface $mock) {
            $mock->shouldReceive('enviar')->twice();
        });

        $this->post('/esqueci-senha', ['username' => 'coordenador']);
        $this->assertDatabaseCount('redefinicoes_senha', 1);

        $this->post('/esqueci-senha', ['username' => 'coordenador']);
        $this->assertDatabaseCount('redefinicoes_senha', 1);
    }
}
