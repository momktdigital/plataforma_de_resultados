<?php

namespace App\Services\Update;

use App\Services\Backup\BackupService;
use FilesystemIterator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Atualiza a aplicação a partir da última Release pública do GitHub.
 *
 * Fluxo: gera backup → modo de manutenção → baixa e extrai o pacote →
 * substitui os arquivos (preservando `.env` e `storage/`) → composer install
 * → migrations → grava a nova versão → sai da manutenção. Se algo falhar
 * depois que os arquivos já começaram a ser substituídos, tenta restaurar a
 * aplicação a partir do backup que acabou de gerar.
 */
class UpdateService
{
    private const IGNORAR_NA_COPIA = ['.env', 'storage', 'vendor', '.git', 'bootstrap/cache'];

    private readonly string $destino;

    /**
     * @param  string|null  $destino  Raiz da aplicação a atualizar. Só existe como
     *                                parâmetro para os testes conseguirem apontar
     *                                para um diretório temporário em vez da
     *                                aplicação de verdade — em produção é sempre
     *                                `base_path()`.
     */
    public function __construct(
        private readonly GithubReleaseClient $github,
        private readonly BackupService $backupService,
        private readonly bool $executarComposer = true,
        ?string $destino = null,
    ) {
        $this->destino = $destino ?? base_path();
    }

    public function versaoAtual(): string
    {
        return trim(File::get($this->destino.'/VERSION'));
    }

    /** @return array{versao: string, notas: string, zip_url: string}|null */
    public function verificarAtualizacao(): ?array
    {
        $release = $this->github->ultimaRelease();

        if ($release === null) {
            return null;
        }

        $versaoRemota = ltrim($release['tag'], 'vV');

        if (version_compare($versaoRemota, $this->versaoAtual(), '<=')) {
            return null;
        }

        return ['versao' => $versaoRemota, 'notas' => $release['notas'], 'zip_url' => $release['zip_url']];
    }

    /** @return array{status: string, versao?: string, mensagens: array<int, string>} */
    public function atualizar(): array
    {
        $disponivel = $this->verificarAtualizacao();

        if ($disponivel === null) {
            return ['status' => 'ja_atualizado', 'mensagens' => ['Nenhuma atualização disponível.']];
        }

        $mensagens = [];
        $arquivosSubstituidos = false;
        $caminhoBackup = null;
        $zipTemp = null;
        $pastaExtraida = null;

        try {
            $caminhoBackup = $this->backupService->gerar();
            $mensagens[] = "Backup gerado: {$caminhoBackup}";

            Artisan::call('down', ['--retry' => 60]);
            $mensagens[] = 'Modo de manutenção ativado.';

            $zipTemp = $this->baixarZip($disponivel['zip_url']);
            $mensagens[] = 'Pacote da nova versão baixado.';

            $pastaExtraida = $this->extrairZip($zipTemp);
            $origem = $this->localizarSubpasta($pastaExtraida);
            $mensagens[] = 'Pacote extraído.';

            $arquivosSubstituidos = true;
            $this->copiarArquivos($origem, $this->destino);
            $mensagens[] = 'Arquivos da aplicação atualizados.';

            if ($this->executarComposer) {
                $this->rodarComposer();
                $mensagens[] = 'Dependências atualizadas (composer install).';
            }

            Artisan::call('migrate', ['--force' => true]);
            $mensagens[] = 'Migrations executadas.';

            File::put($this->destino.'/VERSION', $disponivel['versao']."\n");
            $mensagens[] = "Versão atualizada para {$disponivel['versao']}.";

            Artisan::call('optimize:clear');

            if (function_exists('opcache_reset')) {
                opcache_reset();
            }

            Artisan::call('up');
            $mensagens[] = 'Modo de manutenção desativado.';

            return ['status' => 'atualizado', 'versao' => $disponivel['versao'], 'mensagens' => $mensagens];
        } catch (Throwable $e) {
            $mensagens[] = 'ERRO: '.$e->getMessage();
            $mensagens = array_merge($mensagens, $this->tentarRecuperar($arquivosSubstituidos, $caminhoBackup));

            return ['status' => 'erro', 'mensagens' => $mensagens];
        } finally {
            if ($zipTemp) {
                @unlink($zipTemp);
            }
            if ($pastaExtraida) {
                File::deleteDirectory($pastaExtraida);
            }
        }
    }

    /** @return array<int, string> */
    private function tentarRecuperar(bool $arquivosSubstituidos, ?string $caminhoBackup): array
    {
        if (! $arquivosSubstituidos || ! $caminhoBackup) {
            Artisan::call('up');

            return ['Modo de manutenção desativado (nenhum arquivo da aplicação chegou a ser alterado).'];
        }

        try {
            $this->restaurarArquivosDoBackup($caminhoBackup);
            Artisan::call('up');

            return [
                'Rollback automático aplicado a partir do backup gerado no início desta atualização.',
                'Modo de manutenção desativado.',
            ];
        } catch (Throwable $e) {
            return [
                'Rollback automático FALHOU: '.$e->getMessage(),
                "O sistema pode ter ficado em modo de manutenção. Restaure manualmente a partir de: {$caminhoBackup}",
            ];
        }
    }

    private function baixarZip(string $url): string
    {
        $destino = tempnam(sys_get_temp_dir(), 'atualizacao_').'.zip';

        $resposta = Http::withHeaders(['User-Agent' => 'avaliacoes-updater'])
            ->timeout(180)
            ->sink($destino)
            ->get($url);

        if ($resposta->failed()) {
            throw new RuntimeException('Falha ao baixar o pacote de atualização.');
        }

        return $destino;
    }

    private function extrairZip(string $zipPath): string
    {
        $destino = sys_get_temp_dir().'/atualizacao_extraida_'.uniqid();

        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Não foi possível abrir o pacote baixado.');
        }

        $zip->extractTo($destino);
        $zip->close();

        return $destino;
    }

    private function localizarSubpasta(string $pastaExtraida): string
    {
        $entradas = array_values(array_diff(scandir($pastaExtraida) ?: [], ['.', '..']));

        if (count($entradas) !== 1 || ! is_dir($pastaExtraida.'/'.$entradas[0])) {
            throw new RuntimeException('Formato inesperado do pacote de atualização.');
        }

        $raizExtraida = $pastaExtraida.'/'.$entradas[0];
        $subpasta = config('sistema.subpasta');
        $origem = $subpasta === '' ? $raizExtraida : $raizExtraida.'/'.$subpasta;

        if (! is_dir($origem)) {
            throw new RuntimeException('Pasta "'.$subpasta.'" não encontrada no pacote baixado.');
        }

        return $origem;
    }

    private function copiarArquivos(string $origem, string $destino): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($origem, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $arquivo) {
            $relativo = ltrim(substr($arquivo->getPathname(), strlen($origem)), '/');

            if ($this->deveIgnorar($relativo)) {
                continue;
            }

            $destinoArquivo = $destino.'/'.$relativo;

            if ($arquivo->isDir()) {
                File::ensureDirectoryExists($destinoArquivo);
            } else {
                File::ensureDirectoryExists(dirname($destinoArquivo));
                File::copy($arquivo->getPathname(), $destinoArquivo);
            }
        }
    }

    private function deveIgnorar(string $relativo): bool
    {
        foreach (self::IGNORAR_NA_COPIA as $ignorado) {
            if ($relativo === $ignorado || str_starts_with($relativo, $ignorado.'/')) {
                return true;
            }
        }

        return false;
    }

    private function rodarComposer(): void
    {
        $resultado = Process::path($this->destino)->timeout(300)->run([
            'composer', 'install', '--no-dev', '--optimize-autoloader', '--no-interaction',
        ]);

        if (! $resultado->successful()) {
            throw new RuntimeException('composer install falhou: '.$resultado->errorOutput());
        }
    }

    private function restaurarArquivosDoBackup(string $caminhoBackupZip): void
    {
        $zip = new ZipArchive;

        if ($zip->open($caminhoBackupZip) !== true) {
            throw new RuntimeException('Não foi possível abrir o backup para o rollback.');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nome = $zip->getNameIndex($i);

            if ($nome === false || ! str_starts_with($nome, 'app/') || $nome === 'app/') {
                continue;
            }

            $relativo = substr($nome, strlen('app/'));
            $destino = $this->destino.'/'.$relativo;

            if (str_ends_with($nome, '/')) {
                File::ensureDirectoryExists($destino);

                continue;
            }

            File::ensureDirectoryExists(dirname($destino));
            File::put($destino, $zip->getFromIndex($i));
        }

        $zip->close();
    }
}
