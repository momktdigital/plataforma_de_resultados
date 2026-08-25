<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Services\Backup\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/backups'));

        parent::tearDown();
    }

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    public function test_gera_backup_com_banco_e_arquivos(): void
    {
        $caminho = app(BackupService::class)->gerar();

        $this->assertFileExists($caminho);

        $zip = new ZipArchive;
        $zip->open($caminho);

        $this->assertNotFalse($zip->locateName('database.sql'));
        $this->assertNotFalse($zip->locateName('app/composer.json'));
        $this->assertFalse($zip->locateName('app/vendor/autoload.php'), 'vendor/ não deveria estar no backup');

        $zip->close();
    }

    public function test_dump_do_banco_grava_todas_as_linhas_de_uma_tabela_grande(): void
    {
        // Regressão: escreverInserts() usava DB::table()->get(), que carrega
        // a tabela inteira na memória do PHP de uma vez — numa tabela com
        // volume real (respostas, resultado_metricas...) isso estoura o
        // memory_limit e derruba o backup com um 500. O dump agora usa um
        // cursor do PDO (sem buffer), então este teste garante que as 300
        // linhas continuam todas presentes no SQL gerado.
        for ($i = 0; $i < 300; $i++) {
            Admin::create(['username' => "admin{$i}", 'password_hash' => bcrypt('x')]);
        }

        $caminho = app(BackupService::class)->gerar();

        $zip = new ZipArchive;
        $zip->open($caminho);
        $sql = $zip->getFromName('database.sql');
        $zip->close();

        $this->assertSame(300, substr_count($sql, 'INSERT INTO `admins`'));
        $this->assertStringContainsString('admin299', $sql);
    }

    public function test_mantem_apenas_os_5_backups_mais_recentes(): void
    {
        $service = app(BackupService::class);

        for ($i = 0; $i < 7; $i++) {
            $service->gerar();
            usleep(1_100_000); // garante nomes de arquivo (por segundo) distintos
        }

        $this->assertCount(5, File::files(storage_path('app/backups')));
    }

    public function test_admin_pode_gerar_e_baixar_backup_pela_interface(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post('/sistema/backups')
            ->assertRedirect(route('sistema.backups.index'));

        $arquivo = File::files(storage_path('app/backups'))[0]->getFilename();

        $this->actingAs($admin, 'admin')
            ->get("/sistema/backups/{$arquivo}/download")
            ->assertOk();
    }

    public function test_download_rejeita_nome_de_arquivo_fora_do_padrao(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get('/sistema/backups/../../.env/download')
            ->assertNotFound();
    }

    public function test_falha_ao_gerar_backup_mostra_erro_em_vez_de_500(): void
    {
        $this->mock(BackupService::class)
            ->shouldReceive('gerar')
            ->andThrow(new \RuntimeException('mysqldump indisponível e SHOW TABLES falhou.'));

        $this->actingAs($this->admin(), 'admin')
            ->post('/sistema/backups')
            ->assertRedirect(route('sistema.backups.index'))
            ->assertSessionHasErrors('backup');
    }

    public function test_guest_nao_acessa_backups(): void
    {
        $this->admin();

        $this->get('/sistema/backups')->assertRedirect(route('login'));
    }
}
