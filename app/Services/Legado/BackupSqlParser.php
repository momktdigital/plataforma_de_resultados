<?php

namespace App\Services\Legado;

use Generator;
use RuntimeException;

/**
 * Lê um arquivo .sql gerado pelo backup manual da aplicação legada
 * (`admin/backup.php`) e extrai só as linhas das tabelas `gabaritos` e
 * `resultados` — o resto do dump (schema, outras tabelas) é ignorado.
 *
 * **Nunca executa o SQL do arquivo.** Isso é proposital: um backup poderia
 * conter `DROP TABLE`/`CREATE DATABASE`/etc. e não há razão para essa
 * importação ter permissão de rodar comandos arbitrários contra o banco —
 * ela só entende dados (`INSERT INTO ... VALUES (...);`) e devolve objetos
 * PHP para o `LegadoImportador` processar do mesmo jeito que os dados lidos
 * direto do banco.
 *
 * Assume o formato exato produzido por `admin/backup.php`: um `INSERT INTO`
 * por linha, sempre com a lista de colunas explícita — não é um parser de
 * SQL genérico.
 */
class BackupSqlParser
{
    private const TABELAS_RELEVANTES = ['gabaritos', 'resultados'];

    /** @return Generator<int, array{tabela: string, linha: object}> */
    public function linhas(string $caminhoArquivo): Generator
    {
        $handle = fopen($caminhoArquivo, 'r');

        if ($handle === false) {
            throw new RuntimeException('Não foi possível abrir o arquivo de backup.');
        }

        try {
            while (($linhaTexto = fgets($handle)) !== false) {
                $linhaTexto = trim($linhaTexto);

                if ($linhaTexto === '' || ! str_starts_with($linhaTexto, 'INSERT INTO')) {
                    continue;
                }

                $registro = $this->interpretarInsert($linhaTexto);

                if ($registro !== null) {
                    yield $registro;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return array{tabela: string, linha: object}|null */
    private function interpretarInsert(string $statement): ?array
    {
        $padrao = '/^INSERT INTO `('.implode('|', self::TABELAS_RELEVANTES).')`\s*\(([^)]*)\)\s*VALUES\s*\((.*)\)\s*;$/is';

        if (preg_match($padrao, $statement, $m) !== 1) {
            return null;
        }

        [, $tabela, $listaColunas, $listaValores] = $m;

        $colunas = array_map(
            fn ($col) => trim(trim($col), '`'),
            explode(',', $listaColunas),
        );

        $valores = $this->dividirValoresDeTopo($listaValores);

        if (count($colunas) !== count($valores)) {
            throw new RuntimeException(
                "Linha de backup malformada para `{$tabela}`: número de colunas e valores não bate."
            );
        }

        return ['tabela' => $tabela, 'linha' => (object) array_combine($colunas, $valores)];
    }

    /** @return array<int, string|null> */
    private function dividirValoresDeTopo(string $conteudo): array
    {
        $valores = [];
        $atual = '';
        $dentroString = false;
        $tamanho = strlen($conteudo);

        for ($i = 0; $i < $tamanho; $i++) {
            $char = $conteudo[$i];

            if ($dentroString) {
                if ($char === '\\' && $i + 1 < $tamanho) {
                    $atual .= $char.$conteudo[$i + 1];
                    $i++;

                    continue;
                }

                if ($char === "'") {
                    $dentroString = false;
                }

                $atual .= $char;

                continue;
            }

            if ($char === "'") {
                $dentroString = true;
                $atual .= $char;

                continue;
            }

            if ($char === ',') {
                $valores[] = $this->converterToken(trim($atual));
                $atual = '';

                continue;
            }

            $atual .= $char;
        }

        if (trim($atual) !== '') {
            $valores[] = $this->converterToken(trim($atual));
        }

        return $valores;
    }

    private function converterToken(string $token): ?string
    {
        if (strcasecmp($token, 'NULL') === 0) {
            return null;
        }

        if (preg_match('/^\'(.*)\'$/s', $token, $m) === 1) {
            return $this->desescapar($m[1]);
        }

        return $token;
    }

    private function desescapar(string $valor): string
    {
        return preg_replace_callback('/\\\\(.)/', function ($m) {
            return match ($m[1]) {
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                '0' => "\0",
                'Z' => "\x1A",
                default => $m[1],
            };
        }, $valor) ?? $valor;
    }
}
