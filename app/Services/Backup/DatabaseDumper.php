<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use PDO;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Gera um dump SQL do banco configurado. Em MySQL, prefere o binário
 * `mysqldump` (mais rápido e completo); se não estiver disponível, ou se a
 * conexão for SQLite (ambiente de desenvolvimento/teste), cai para um dump
 * feito em PHP puro usando a própria conexão PDO já aberta pela aplicação.
 */
class DatabaseDumper implements DatabaseDumperContract
{
    public function dumpToFile(string $destino): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' && $this->mysqldumpDisponivel()) {
            $this->dumpComMysqldump(config('database.connections.mysql'), $destino);

            return;
        }

        match ($driver) {
            'mysql' => $this->dumpMysqlEmPhpPuro($destino),
            'sqlite' => $this->dumpSqliteEmPhpPuro($destino),
            default => throw new RuntimeException("Dump não suportado para o driver '{$driver}'."),
        };
    }

    private function mysqldumpDisponivel(): bool
    {
        return (new ExecutableFinder)->find('mysqldump') !== null;
    }

    /** @param  array<string, mixed>  $config */
    private function dumpComMysqldump(array $config, string $destino): void
    {
        $resultado = Process::timeout(300)
            ->env(['MYSQL_PWD' => (string) $config['password']])
            ->run([
                'mysqldump',
                '--host='.$config['host'],
                '--port='.$config['port'],
                '--user='.$config['username'],
                '--single-transaction',
                '--routines',
                '--result-file='.$destino,
                $config['database'],
            ]);

        if (! $resultado->successful()) {
            throw new RuntimeException('Falha ao executar mysqldump: '.$resultado->errorOutput());
        }
    }

    private function dumpMysqlEmPhpPuro(string $destino): void
    {
        $handle = fopen($destino, 'w');

        fwrite($handle, "-- Dump gerado em PHP puro (mysqldump indisponível no servidor)\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        $tabelas = collect(DB::select('SHOW TABLES'))
            ->map(fn ($linha) => array_values((array) $linha)[0]);

        $pdo = DB::connection()->getPdo();

        foreach ($tabelas as $tabela) {
            $criacao = array_values((array) DB::select("SHOW CREATE TABLE `{$tabela}`")[0]);
            fwrite($handle, "DROP TABLE IF EXISTS `{$tabela}`;\n{$criacao[1]};\n\n");

            $this->escreverInserts($handle, $tabela, $pdo, fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v));
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);
    }

    private function dumpSqliteEmPhpPuro(string $destino): void
    {
        $handle = fopen($destino, 'w');

        fwrite($handle, "-- Dump gerado em PHP puro (conexão SQLite)\n");
        fwrite($handle, "PRAGMA foreign_keys=OFF;\n\n");

        $pdo = DB::connection()->getPdo();

        $tabelas = DB::select(
            "SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
        );

        foreach ($tabelas as $tabela) {
            fwrite($handle, "DROP TABLE IF EXISTS \"{$tabela->name}\";\n{$tabela->sql};\n\n");

            $this->escreverInserts($handle, $tabela->name, $pdo, fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v));
        }

        fwrite($handle, "PRAGMA foreign_keys=ON;\n");
        fclose($handle);
    }

    private function escreverInserts($handle, string $tabela, PDO $pdo, callable $formatar): void
    {
        foreach (DB::table($tabela)->get() as $linha) {
            $valores = array_map($formatar, (array) $linha);

            fwrite($handle, "INSERT INTO \"{$tabela}\" VALUES (".implode(', ', $valores).");\n");
        }

        fwrite($handle, "\n");
    }
}
