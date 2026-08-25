<?php

namespace App\Services\Backup;

use App\Models\ConfiguracaoSistema;
use FilesystemIterator;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/**
 * Gera um .zip com uma cópia completa do sistema: dump do banco
 * (`database.sql`) + todos os arquivos da aplicação (exceto o que é
 * reproduzível via `composer install`/`npm install` ou específico do
 * ambiente atual). Pensado para restaurar em outro servidor.
 */
class BackupService
{
    private const MANTER_ULTIMOS_PADRAO = 5;

    /** Diretórios/arquivos (relativos à raiz da aplicação) fora do backup. */
    private const EXCLUIR = [
        'vendor',
        'node_modules',
        '.git',
        'storage/app/backups',
        'storage/framework/cache',
        'storage/framework/sessions',
        'storage/framework/testing',
        'storage/framework/views',
        'storage/logs',
        'bootstrap/cache',
    ];

    public function __construct(private readonly DatabaseDumperContract $dumper = new DatabaseDumper) {}

    /** Gera o backup e devolve o caminho completo do .zip criado. */
    public function gerar(): string
    {
        $pastaBackups = storage_path('app/backups');
        File::ensureDirectoryExists($pastaBackups);

        $nomeArquivo = 'backup-'.now()->format('Y-m-d_His').'.zip';
        $caminhoZip = $pastaBackups.'/'.$nomeArquivo;

        $dumpSql = tempnam(sys_get_temp_dir(), 'db_dump_').'.sql';
        $this->dumper->dumpToFile($dumpSql);

        $zip = new ZipArchive;
        if ($zip->open($caminhoZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Não foi possível criar o arquivo de backup em {$caminhoZip}.");
        }

        $zip->addFile($dumpSql, 'database.sql');
        $this->adicionarArquivosDaAplicacao($zip);
        $zip->close();

        @unlink($dumpSql);

        $this->removerBackupsAntigos($pastaBackups);

        return $caminhoZip;
    }

    private function adicionarArquivosDaAplicacao(ZipArchive $zip): void
    {
        $raiz = base_path();
        $excluidos = array_map(fn ($p) => $raiz.'/'.$p, self::EXCLUIR);

        // CATCH_GET_CHILD: sem essa flag, uma única subpasta sem permissão de
        // leitura (comum em symlinks/junctions do Windows, ex.: public/storage)
        // derruba o backup inteiro com uma UnexpectedValueException — com ela,
        // aquela subpasta é só pulada.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
            RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        foreach ($iterator as $arquivo) {
            $caminho = $arquivo->getPathname();

            foreach ($excluidos as $excluido) {
                if ($caminho === $excluido || str_starts_with($caminho, $excluido.'/')) {
                    continue 2;
                }
            }

            $caminhoRelativo = 'app/'.ltrim(substr($caminho, strlen($raiz)), '/');

            if ($arquivo->isDir()) {
                $zip->addEmptyDir($caminhoRelativo);
            } else {
                $zip->addFile($caminho, $caminhoRelativo);
            }
        }
    }

    private function removerBackupsAntigos(string $pasta): void
    {
        $manter = (int) ConfiguracaoSistema::valor('backup_manter_ultimos', (string) self::MANTER_ULTIMOS_PADRAO);

        $arquivos = collect(File::files($pasta))
            ->sortByDesc(fn ($arquivo) => $arquivo->getMTime())
            ->values();

        $arquivos->slice(max($manter, 0))->each(fn ($arquivo) => File::delete($arquivo->getPathname()));
    }
}
