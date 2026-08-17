<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ConfiguracaoSistema;
use App\Services\Backup\BackupService;
use App\Services\Update\GithubReleaseClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConfiguracaoTest extends TestCase
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

    public function test_guest_nao_acessa_configuracoes(): void
    {
        $this->admin();

        $this->get('/sistema/configuracoes')->assertRedirect(route('login'));
    }

    public function test_admin_ve_valores_padrao(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')->get('/sistema/configuracoes');

        $response->assertOk();
        $response->assertSee(config('sistema.repositorio'));
    }

    public function test_admin_atualiza_configuracoes(): void
    {
        $this->actingAs($this->admin(), 'admin')->post('/sistema/configuracoes', [
            'atualizacao_repositorio' => 'minhaorg/meurepo',
            'backup_manter_ultimos' => 3,
        ])->assertRedirect(route('sistema.configuracoes.index'));

        $this->assertSame('minhaorg/meurepo', ConfiguracaoSistema::valor('atualizacao_repositorio'));
        $this->assertSame('3', ConfiguracaoSistema::valor('backup_manter_ultimos'));
    }

    public function test_rejeita_repositorio_em_formato_invalido(): void
    {
        $this->actingAs($this->admin(), 'admin')->post('/sistema/configuracoes', [
            'atualizacao_repositorio' => 'sem-barra',
            'backup_manter_ultimos' => 5,
        ])->assertSessionHasErrors('atualizacao_repositorio');
    }

    public function test_rejeita_retencao_fora_do_intervalo(): void
    {
        $this->actingAs($this->admin(), 'admin')->post('/sistema/configuracoes', [
            'atualizacao_repositorio' => 'org/repo',
            'backup_manter_ultimos' => 0,
        ])->assertSessionHasErrors('backup_manter_ultimos');
    }

    public function test_retencao_configurada_e_respeitada_pelos_backups(): void
    {
        ConfiguracaoSistema::definir('backup_manter_ultimos', '2');

        $service = app(BackupService::class);
        for ($i = 0; $i < 4; $i++) {
            $service->gerar();
            usleep(1_100_000);
        }

        $this->assertCount(2, File::files(storage_path('app/backups')));
    }

    public function test_atualizador_usa_repositorio_configurado(): void
    {
        ConfiguracaoSistema::definir('atualizacao_repositorio', 'minhaorg/meurepo');

        Http::fake([
            'api.github.com/repos/minhaorg/meurepo/*' => Http::response(['tag_name' => 'v1.0.0', 'body' => '', 'zipball_url' => 'https://x/zip']),
        ]);

        app(GithubReleaseClient::class)->ultimaRelease();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'minhaorg/meurepo'));
    }
}
