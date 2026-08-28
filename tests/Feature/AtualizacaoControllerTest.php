<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Services\Backup\BackupService;
use App\Services\Update\GithubReleaseClient;
use App\Services\Update\UpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

/**
 * UpdateServiceTest cobre o serviço direto — este arquivo cobre a rota/
 * controller de verdade (App\Http\Controllers\Sistema\AtualizacaoController),
 * garantindo que a autenticação de admin está amarrada no endpoint de maior
 * raio de explosão do sistema (baixa e aplica código de fora) e que o fluxo
 * de confirmação manual de tag/hash (ver UpdateService::aplicarConfirmado())
 * está corretamente ligado do HTTP até o serviço.
 */
class AtualizacaoControllerTest extends TestCase
{
    use RefreshDatabase;

    private array $diretoriosTemporarios = [];

    protected function tearDown(): void
    {
        Artisan::call('up');
        File::deleteDirectory(storage_path('app/backups'));

        foreach ($this->diretoriosTemporarios as $dir) {
            File::deleteDirectory($dir);
        }

        parent::tearDown();
    }

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    /**
     * Substitui o UpdateService resolvido pelo container por um apontando
     * pra um diretório descartável — os testes de admin autenticado abaixo
     * passam pela ROTA de verdade, então sem isso estariam extraindo pacote
     * e rodando composer/migrate em cima da própria aplicação.
     */
    private function usarDestinoFalso(string $versao): string
    {
        $destino = sys_get_temp_dir().'/destino_controller_'.uniqid();
        File::ensureDirectoryExists($destino);
        File::put($destino.'/VERSION', $versao."\n");
        $this->diretoriosTemporarios[] = $destino;

        $this->app->instance(UpdateService::class, new UpdateService(
            $this->app->make(GithubReleaseClient::class),
            $this->app->make(BackupService::class),
            executarComposer: false,
            destino: $destino,
        ));

        return $destino;
    }

    private function criarPacoteFalso(string $versao): string
    {
        $zipPath = sys_get_temp_dir().'/pacote_controller_'.uniqid().'.zip';
        $this->diretoriosTemporarios[] = $zipPath;

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('repo-abcdef1/VERSION', $versao."\n");
        $zip->close();

        return $zipPath;
    }

    private function fakeGithub(string $tag, string $zipPath): void
    {
        Http::fake([
            'api.github.com/repos/*/releases/latest' => Http::response([
                'tag_name' => $tag,
                'body' => 'notas',
                'zipball_url' => 'https://codeload.example/zip',
            ]),
            'codeload.example/zip' => Http::response(File::get($zipPath)),
        ]);
    }

    public function test_guest_nao_acessa_nenhuma_rota_de_atualizacao(): void
    {
        $this->admin();

        $this->get('/sistema/atualizacao')->assertRedirect(route('login'));
        $this->post('/sistema/atualizacao/verificar')->assertRedirect(route('login'));
        $this->post('/sistema/atualizacao')->assertRedirect(route('login'));
    }

    public function test_admin_ve_tela_sem_atualizacao_disponivel(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v0.0.1', 'body' => '', 'zipball_url' => 'x'])]);

        $response = $this->actingAs($this->admin(), 'admin')->get('/sistema/atualizacao');

        $response->assertOk();
        $response->assertSee('Nenhuma atualização disponível');
    }

    public function test_admin_baixa_o_pacote_e_ve_o_hash_para_confirmar(): void
    {
        $this->usarDestinoFalso('1.0.0');
        $zip = $this->criarPacoteFalso('1.1.0');
        $this->fakeGithub('v1.1.0', $zip);

        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post('/sistema/atualizacao/verificar')
            ->assertRedirect(route('sistema.atualizacao.index'));

        $pendente = session('atualizacao_pendente');
        $this->assertNotNull($pendente);
        $this->diretoriosTemporarios[] = $pendente['zip_path'];

        $response = $this->actingAs($admin, 'admin')->get('/sistema/atualizacao');

        $response->assertOk();
        $response->assertSee('1.1.0');
        $response->assertSee($pendente['sha256']);
    }

    public function test_store_sem_ter_baixado_antes_retorna_erro(): void
    {
        $this->usarDestinoFalso('1.0.0');

        $this->actingAs($this->admin(), 'admin')
            ->post('/sistema/atualizacao', ['versao_confirmada' => '1.1.0'])
            ->assertRedirect(route('sistema.atualizacao.index'))
            ->assertSessionHasErrors('atualizacao');
    }

    public function test_store_com_versao_digitada_errada_nao_aplica(): void
    {
        $destino = $this->usarDestinoFalso('1.0.0');
        $zip = $this->criarPacoteFalso('1.1.0');
        $this->fakeGithub('v1.1.0', $zip);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post('/sistema/atualizacao/verificar');

        $this->actingAs($admin, 'admin')
            ->post('/sistema/atualizacao', ['versao_confirmada' => '9.9.9'])
            ->assertSessionHasErrors('versao_confirmada');

        $this->assertSame("1.0.0\n", File::get($destino.'/VERSION'));
        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_store_com_versao_confirmada_corretamente_aplica_a_atualizacao(): void
    {
        $destino = $this->usarDestinoFalso('1.0.0');
        $zip = $this->criarPacoteFalso('1.1.0');
        $this->fakeGithub('v1.1.0', $zip);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post('/sistema/atualizacao/verificar');

        $response = $this->actingAs($admin, 'admin')
            ->post('/sistema/atualizacao', ['versao_confirmada' => '1.1.0']);

        $response->assertOk();
        $this->assertSame("1.1.0\n", File::get($destino.'/VERSION'));
        $this->assertFalse(app()->isDownForMaintenance());
    }
}
