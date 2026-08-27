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
 * Fluxo: gera backup → modo de manutenção → extrai o pacote já baixado →
 * substitui os arquivos (preservando `.env` e `storage/`) → composer install
 * → migrations → grava a nova versão → sai da manutenção. Se algo falhar
 * depois que os arquivos já começaram a ser substituídos, tenta restaurar a
 * aplicação a partir do backup que acabou de gerar.
 *
 * Dois caminhos de entrada: atualizar() (baixa e aplica tudo de uma vez —
 * usado por `sistema:atualizar`, um comando de CLI, onde quem já tem acesso
 * ao servidor pra rodar o comando não ganha nada de novo automatizando isso)
 * e o par baixarParaConfirmacao()/aplicarConfirmado() (usado pelo painel
 * admin — baixa e mostra tag+hash pro admin conferir manualmente ANTES de
 * qualquer arquivo da aplicação ser tocado, já que ali um clique acidental
 * ou uma sessão de admin comprometida não deveria bastar pra baixar e
 * aplicar código de um repositório potencialmente comprometido sem nenhuma
 * checagem fora de banda).
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

        try {
            $zipTemp = $this->baixarZip($disponivel['zip_url']);
        } catch (Throwable $e) {
            return ['status' => 'erro', 'mensagens' => ['ERRO: '.$e->getMessage()]];
        }

        return $this->aplicarZip($zipTemp, $disponivel['versao'], ['Pacote da nova versão baixado.']);
    }

    /**
     * Baixa o pacote da versão disponível e calcula o SHA-256 sem tocar em
     * nenhum arquivo da aplicação — usado pelo painel admin
     * (Sistema\AtualizacaoController), que exige o admin conferir e
     * confirmar manualmente a tag/hash antes de aplicarConfirmado() rodar de
     * verdade. As releases deste repositório não publicam assinatura GPG pra
     * verificar automaticamente; a confirmação humana é o que evita que um
     * clique em "atualizar" vire execução de código totalmente automática
     * caso o repositório (ou uma dependência do build) seja comprometido.
     *
     * @return array{versao: string, notas: string, zip_path: string, sha256: string}
     */
    public function baixarParaConfirmacao(): array
    {
        $disponivel = $this->verificarAtualizacao();

        if ($disponivel === null) {
            throw new RuntimeException('Nenhuma atualização disponível.');
        }

        $zipTemp = $this->baixarZip($disponivel['zip_url']);

        return [
            'versao' => $disponivel['versao'],
            'notas' => $disponivel['notas'],
            'zip_path' => $zipTemp,
            'sha256' => hash_file('sha256', $zipTemp),
        ];
    }

    /**
     * Aplica um pacote já baixado por baixarParaConfirmacao(). Reconfere o
     * SHA-256 aqui — o zip temporário pode ter sido trocado ou apagado entre
     * a tela de confirmação e este clique — pra garantir que o que é
     * aplicado é exatamente o que foi mostrado ao admin.
     *
     * @return array{status: string, versao?: string, mensagens: array<int, string>}
     */
    public function aplicarConfirmado(string $zipPath, string $shaConfirmado, string $versao): array
    {
        if (! File::exists($zipPath) || ! hash_equals($shaConfirmado, (string) hash_file('sha256', $zipPath))) {
            return [
                'status' => 'erro',
                'mensagens' => ['O pacote baixado não é mais o mesmo que foi confirmado (ou expirou) — solicite a atualização novamente.'],
            ];
        }

        return $this->aplicarZip($zipPath, $versao, ["Pacote confirmado pelo admin (SHA-256 {$shaConfirmado})."]);
    }

    /**
     * @param  array<int, string>  $mensagens  mensagens já acumuladas antes deste ponto (ex.: "pacote baixado"/"confirmado pelo admin").
     * @return array{status: string, versao?: string, mensagens: array<int, string>}
     */
    private function aplicarZip(string $zipTemp, string $versao, array $mensagens): array
    {
        $arquivosSubstituidos = false;
        $caminhoBackup = null;
        $pastaExtraida = null;

        try {
            $caminhoBackup = $this->backupService->gerar();
            $mensagens[] = "Backup gerado: {$caminhoBackup}";

            Artisan::call('down', ['--retry' => 60]);
            $mensagens[] = 'Modo de manutenção ativado.';

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

            File::put($this->destino.'/VERSION', $versao."\n");
            $mensagens[] = "Versão atualizada para {$versao}.";

            Artisan::call('optimize:clear');

            if (function_exists('opcache_reset')) {
                opcache_reset();
            }

            Artisan::call('up');
            $mensagens[] = 'Modo de manutenção desativado.';

            return ['status' => 'atualizado', 'versao' => $versao, 'mensagens' => $mensagens];
        } catch (Throwable $e) {
            $mensagens[] = 'ERRO: '.$e->getMessage();
            $mensagens = array_merge($mensagens, $this->tentarRecuperar($arquivosSubstituidos, $caminhoBackup));

            return ['status' => 'erro', 'mensagens' => $mensagens];
        } finally {
            @unlink($zipTemp);
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
