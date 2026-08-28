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
        $zip->addFromString('momktdigital-resultados_di-abcdef1/VERSION', $versao."\n");
        $zip->addFromString('momktdigital-resultados_di-abcdef1/marcador.txt', 'arquivo novo da versão '.$versao);
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
        $this->assertTrue(collect($resultado['mensagens'])->contains(fn ($m) => str_contains($m, 'Rollback automático de arquivos aplicado')));
        // composer falha ANTES do migrate rodar — nada a dizer sobre migrations.
        $this->assertFalse(collect($resultado['mensagens'])->contains(fn ($m) => str_contains($m, 'migration')));
        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_falha_apos_migrar_reverte_as_migrations_antes_de_restaurar_os_arquivos(): void
    {
        // Não dá pra forçar uma falha real depois do migrate sem depender de
        // uma migration de verdade nova (fora do escopo deste teste) — testa
        // a lógica de recuperação diretamente: com migracoesExecutadas=true,
        // o rollback de banco (migrate:rollback) precisa ser tentado ANTES do
        // rollback de arquivos, e o resultado precisa dizer isso — ao
        // contrário do cenário acima (falha antes do migrate), onde nada
        // sobre migrations aparece.
        $destino = $this->criarDestinoFalso('1.0.0');
        File::ensureDirectoryExists(storage_path('app/backups'));
        $caminhoBackup = storage_path('app/backups/backup_teste.zip');
        $zip = new ZipArchive;
        $zip->open($caminhoBackup, ZipArchive::CREATE);
        $zip->addFromString('app/VERSION', "1.0.0\n");
        $zip->close();

        $service = $this->service($destino);
        $metodo = new \ReflectionMethod($service, 'tentarRecuperar');
        $metodo->setAccessible(true);

        $mensagens = $metodo->invoke($service, true, true, $caminhoBackup);

        $this->assertTrue(
            collect($mensagens)->contains(fn ($m) => str_contains($m, 'Migrations desta atualização revertidas') || str_contains($m, 'FALHA ao reverter as migrations')),
            'Esperava alguma mensagem sobre a tentativa de reverter as migrations.'
        );
        $indiceMigrations = collect($mensagens)->search(fn ($m) => str_contains($m, 'migration') || str_contains($m, 'Migrations'));
        $indiceArquivos = collect($mensagens)->search(fn ($m) => str_contains($m, 'arquivos'));
        $this->assertLessThan($indiceArquivos, $indiceMigrations, 'O rollback de banco precisa ser tentado antes do de arquivos.');

        @unlink($caminhoBackup);
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

    public function test_baixar_para_confirmacao_so_baixa_e_calcula_o_hash_sem_aplicar_nada(): void
    {
        $destino = $this->criarDestinoFalso('1.0.0');
        $zip = $this->criarPacoteFalso('1.1.0');
        $this->fakeGithub('v1.1.0', $zip);

        $pacote = $this->service($destino)->baixarParaConfirmacao();
        $this->diretoriosTemporarios[] = $pacote['zip_path'];

        $this->assertSame('1.1.0', $pacote['versao']);
        $this->assertSame(hash_file('sha256', $pacote['zip_path']), $pacote['sha256']);
        // Nada da aplicação foi tocado ainda — nem o VERSION, nem manutenção.
        $this->assertSame("1.0.0\n", File::get($destino.'/VERSION'));
        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_aplicar_confirmado_com_hash_correto_aplica_a_atualizacao(): void
    {
        $destino = $this->criarDestinoFalso('1.0.0');
        $zip = $this->criarPacoteFalso('1.1.0');
        $this->fakeGithub('v1.1.0', $zip);

        $service = $this->service($destino);
        $pacote = $service->baixarParaConfirmacao();

        $resultado = $service->aplicarConfirmado($pacote['zip_path'], $pacote['sha256'], $pacote['versao']);

        $this->assertSame('atualizado', $resultado['status']);
        $this->assertSame("1.1.0\n", File::get($destino.'/VERSION'));
        $this->assertFileExists($destino.'/marcador.txt');
        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_aplicar_confirmado_rejeita_hash_que_nao_bate_sem_tocar_a_aplicacao(): void
    {
        // Simula o pacote baixado ter sido trocado/corrompido entre a tela
        // de confirmação e o clique em aplicar — o hash mostrado ao admin
        // não é mais o do arquivo em disco.
        $destino = $this->criarDestinoFalso('1.0.0');
        $zip = $this->criarPacoteFalso('1.1.0');
        $this->fakeGithub('v1.1.0', $zip);

        $service = $this->service($destino);
        $pacote = $service->baixarParaConfirmacao();
        $this->diretoriosTemporarios[] = $pacote['zip_path'];

        $resultado = $service->aplicarConfirmado($pacote['zip_path'], 'hash-errado', $pacote['versao']);

        $this->assertSame('erro', $resultado['status']);
        $this->assertSame("1.0.0\n", File::get($destino.'/VERSION'));
        $this->assertFalse(app()->isDownForMaintenance());
    }
}
