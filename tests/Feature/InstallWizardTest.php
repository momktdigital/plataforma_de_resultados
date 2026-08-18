<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureNotInstalled;
use App\Models\Admin;
use App\Support\InstallStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_wizard_e_acessivel_quando_nao_ha_admin(): void
    {
        $this->get('/instalar')->assertOk();
    }

    public function test_wizard_fica_bloqueado_depois_de_instalado(): void
    {
        Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);

        $this->get('/instalar')->assertRedirect(route('login'));
        $this->get('/instalar/banco')->assertRedirect(route('login'));
    }

    public function test_criar_admin_conclui_a_instalacao(): void
    {
        $this->assertDatabaseCount('admins', 0);

        $response = $this->post('/instalar/admin', [
            'username' => 'coordenador',
            'password' => 'senha-bem-forte',
            'password_confirmation' => 'senha-bem-forte',
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('admins', 1);
        $this->assertTrue(InstallStatus::instalado());
    }

    public function test_nao_cria_admin_com_usuario_duplicado(): void
    {
        Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);

        // Simula reabrir a etapa antes de outra aba já ter concluído a instalação
        // (o teste chama o endpoint diretamente, sem o middleware de bloqueio).
        $response = $this->withoutMiddleware(EnsureNotInstalled::class)
            ->post('/instalar/admin', [
                'username' => 'coordenador',
                'password' => 'outrasenha123',
                'password_confirmation' => 'outrasenha123',
            ]);

        $response->assertSessionHasErrors('username');
        $this->assertDatabaseCount('admins', 1);
    }
}
