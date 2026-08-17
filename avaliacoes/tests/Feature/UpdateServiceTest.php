<?php

namespace Tests\Feature;

use App\Services\Backup\BackupService;
use App\Services\Update\GithubReleaseClient;
use App\Services\Update\UpdateService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;
use ZipArchive;

class UpdateServiceTest extends TestCase
{
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

    private function criarDestinoFalso(string $versao): string
    {
        $dir = sys_get_temp_dir().'/destino_teste_'.uniqid();
        File::ensureDirectoryExists($dir);
        File::put($dir.'/VERSION', $versao."\n");
        $this->diretoriosTemporarios[] = $dir;

        return $dir;
    }

    /** Monta um .zip no mesmo formato de um zipball de Release do GitHub. */
    private function criarPacoteFalso(string $versao): string
    {
        $zipPath = sys_get_temp_dir().'/pacote_'.uniqid().'.zip';
        $this->diretoriosTemporarios[] = $zipPath; // arquivo, mas deleteDirectory ignora se não existir mais

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('momktdigital-resultados_di-abcdef1/avaliacoes/VERSION', $versao."\n");
        $zip->addFromString('momktdigital-resultados_di-abcdef1/avaliacoes/marcador.txt', 'arquivo novo da versão '.$versao);
        $zip->addFromString('momktdigital-resultados_di-abcdef1/README.md', 'não faz parte da subpasta avaliacoes');
        $zip->close();

        return $zipPath;
    }

    private function service(string $destino, bool $executarComposer = false): UpdateService
    {
        return new UpdateService(
            app(GithubReleaseClient::class),
            app(BackupService::class),
            $executarComposer,
            $destino,
        );
    }

    private function fakeGithub(string $tag, string $zipPath): void
    {
        Http::fake([
            'api.github.com/repos/*/releases/latest' => Http::response([
                'tag_name' => $tag,
                'body' => 'notas da versão',
                'zipball_url' => 'https://codeload.example/zip',
            ]),
            'codeload.example/zip' => Http::response(File::get($zipPath)),
        ]);
    }

    public function test_nenhuma_atualizacao_quando_ja_esta_na_ultima_versao(): void
    {
        $destino = $this->criarDestinoFalso('1.0.0');
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.0.0', 'body' => '', 'zipball_url' => 'x'])]);

        $disponivel = $this->service($destino)->verificarAtualizacao();

        $this->assertNull($disponivel);
    }

    public function test_detecta_versao_nova_disponivel(): void
    {
        $destino = $this->criarDestinoFalso('1.0.0');
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.2.0', 'body' => 'melhorias', 'zipball_url' => 'https://x/zip'])]);

        $disponivel = $this->service($destino)->verificarAtualizacao();

        $this->assertSame('1.2.0', $disponivel['versao']);
        $this->assertSame('melhorias', $disponivel['notas']);
    }

    public function test_atualizar_com_sucesso_substitui_arquivos_e_versao(): void
    {
        $destino = $this->criarDestinoFalso('1.0.0');
        $zip = $this->criarPacoteFalso('1.1.0');
        $this->fakeGithub('v1.1.0', $zip);

        $resultado = $this->service($destino)->atualizar();

        $this->assertSame('atualizado', $resultado['status']);
        $this->assertSame('1.1.0', $resultado['versao']);
        $this->assertSame("1.1.0\n", File::get($destino.'/VERSION'));
        $this->assertFileExists($destino.'/marcador.txt');
        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_falha_apos_copiar_arquivos_reverte_a_partir_do_backup(): void
    {
        $destino = $this->criarDestinoFalso('1.0.0');
        $zip = $this->criarPacoteFalso('1.1.0');
        $this->fakeGithub('v1.1.0', $zip);

        Process::fake(['*composer*' => Process::result(errorOutput: 'composer quebrou', exitCode: 1)]);

        $resultado = $this->service($destino, executarComposer: true)->atualizar();

        $this->assertSame('erro', $resultado['status']);
        $this->assertStringContainsString('composer quebrou', implode(' ', $resultado['mensagens']));
        $this->assertTrue(collect($resultado['mensagens'])->contains(fn ($m) => str_contains($m, 'Rollback automático aplicado')));
        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_comando_check_nao_aplica_a_atualizacao(): void
    {
        // Não mexe no VERSION real da aplicação — só confirma que ele
        // continua igual depois do --check (comando roda com o destino
        // padrão, que é a própria aplicação).
        $versaoOriginal = File::get(base_path('VERSION'));
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v9999.0.0', 'body' => '', 'zipball_url' => 'https://x/zip'])]);

        $this->artisan('sistema:atualizar --check')
            ->expectsOutputToContain('Nova versão disponível: 9999.0.0')
            ->assertExitCode(0);

        $this->assertSame($versaoOriginal, File::get(base_path('VERSION')));
    }
}
