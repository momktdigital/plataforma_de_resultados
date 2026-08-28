<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureNotInstalled;
use App\Models\Admin;
use App\Support\InstallStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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

    public function test_formulario_de_banco_e_acessivel(): void
    {
        $this->get('/instalar/banco')->assertOk();
    }

    public function test_conexao_de_banco_invalida_retorna_erro_sem_gravar_env(): void
    {
        // Sem servidor MySQL disponível no ambiente de teste — cobre o
        // caminho de erro (a parte não coberta antes: testarEGravarBanco()
        // grava .env sem nenhum teste, mesmo sendo o passo mais destrutivo
        // do instalador). host 127.0.0.1 numa porta que ninguém escuta falha
        // rápido, sem depender do timeout de 5s do PDO.
        $envAntes = file_get_contents(base_path('.env'));

        $response = $this->post('/instalar/banco', [
            'host' => '127.0.0.1',
            'porta' => 1,
            'banco' => 'inexistente',
            'usuario' => 'root',
            'senha' => '',
        ]);

        $response->assertSessionHasErrors('banco');
        $this->assertSame($envAntes, file_get_contents(base_path('.env')));
    }

    public function test_etapa_de_migracao_pede_confirmacao_antes_de_rodar(): void
    {
        // GET só mostra a confirmação — não roda migrate (rota com efeito
        // colateral em GET seria alcançável por crawler/bot de preview).
        $response = $this->get('/instalar/migrar');

        $response->assertOk();
        $response->assertSee(route('instalar.migrar.store'), false);
    }

    public function test_confirmar_migracao_roda_as_migrations(): void
    {
        $response = $this->post('/instalar/migrar');

        $response->assertOk();
        $this->assertTrue(Schema::hasTable('admins'));
    }
}
