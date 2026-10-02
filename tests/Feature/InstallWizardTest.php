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

    /**
     * Regressão (auditoria de segurança): numa falha do banco o sistema se achava "não instalado" e
     * reabria o wizard — dava para apontar o app para um banco do atacante e reescrever o `.env`.
     */
    public function test_banco_fora_do_ar_num_sistema_ja_instalado_nao_reabre_o_wizard(): void
    {
        $marcador = sys_get_temp_dir().'/instalado_queda_'.uniqid();   // ainda não existe: nasce ao confirmar o sistema instalado
        config(['app.instalado_marcador' => $marcador]);

        try {
            Admin::create(['username' => 'adm', 'password_hash' => bcrypt('x')]);
            $this->get('/login')->assertOk();   // confirma "instalado" e grava o marcador
            $this->assertStringContainsString('instalado em', file_get_contents($marcador));

            // Simula o MySQL fora do ar: a verificação de instalação lança exceção.
            InstallStatus::limpar();
            Schema::shouldReceive('hasTable')->with('admins')->andThrow(new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused'));

            $this->assertSame(InstallStatus::INDISPONIVEL, InstallStatus::estado());
            $this->get('/instalar')->assertStatus(503);
            $this->post('/instalar/banco', ['host' => 'banco-do-atacante.exemplo', 'porta' => 3306, 'banco' => 'x', 'usuario' => 'u'])->assertStatus(503);
            $this->get('/login')->assertStatus(503);
        } finally {
            Schema::clearResolvedInstance('db.schema');
            @unlink($marcador);
        }
    }

    public function test_instalacao_nova_sem_marcador_continua_abrindo_o_wizard(): void
    {
        $marcador = sys_get_temp_dir().'/instalado_inexistente_'.uniqid();
        config(['app.instalado_marcador' => $marcador]);

        $this->assertSame(InstallStatus::PENDENTE, InstallStatus::estado());
        $this->get('/instalar')->assertOk();
        $this->assertFileDoesNotExist($marcador);
    }

    public function test_banco_sem_administrador_mas_com_marcador_nao_reabre_o_wizard(): void
    {
        $marcador = tempnam(sys_get_temp_dir(), 'instalado_');
        config(['app.instalado_marcador' => $marcador]);

        try {
            // Já foi instalado (marcador), mas a tabela de admins está vazia — por exemplo, app apontado para outro banco.
            $this->assertSame(InstallStatus::INDISPONIVEL, InstallStatus::estado());
            $this->get('/instalar')->assertStatus(503);
        } finally {
            @unlink($marcador);
        }
    }

    public function test_concluir_o_wizard_grava_o_marcador(): void
    {
        $marcador = sys_get_temp_dir().'/instalado_wizard_'.uniqid();
        config(['app.instalado_marcador' => $marcador]);

        try {
            $this->post('/instalar/admin', ['username' => 'novo', 'password' => 'senha-bem-forte', 'password_confirmation' => 'senha-bem-forte'])->assertOk();

            $this->assertFileExists($marcador);
        } finally {
            @unlink($marcador);
        }
    }

    public function test_banco_nao_ecoa_a_mensagem_do_driver_e_valida_o_formato(): void
    {
        $resposta = $this->post('/instalar/banco', ['host' => '127.0.0.1', 'porta' => 1, 'banco' => 'x', 'usuario' => 'u', 'senha' => 's']);
        $resposta->assertSessionHasErrors('banco');
        $this->assertStringNotContainsString('SQLSTATE', session('errors')->first('banco'));
        $this->assertStringNotContainsString('refused', strtolower(session('errors')->first('banco')));

        // Host/banco/usuário só aceitam caracteres seguros (vão para o .env e para a string do PDO).
        $this->post('/instalar/banco', ['host' => "x
APP_KEY=y", 'porta' => 3306, 'banco' => 'x', 'usuario' => 'u'])->assertSessionHasErrors('host');
        $this->post('/instalar/banco', ['host' => 'ok', 'porta' => 3306, 'banco' => 'a;b', 'usuario' => 'u'])->assertSessionHasErrors('banco');
        $this->post('/instalar/banco', ['host' => 'ok', 'porta' => 99999, 'banco' => 'x', 'usuario' => 'u'])->assertSessionHasErrors('porta');
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
