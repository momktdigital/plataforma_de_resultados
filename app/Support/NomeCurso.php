<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Nomes de curso vêm de planilha digitada à mão, então o mesmo curso aparece
 * com grafias diferentes ("ADMINISTRACAO" / "ADMINISTRAÇÃO", caixa, espaços
 * duplicados). Para o sistema, duas grafias que só diferem em acento, caixa ou
 * espaçamento SÃO o mesmo curso — tudo que compara curso passa por aqui.
 *
 * No MySQL as colunas `alunos.curso` já comparam sem acento (collation _ci),
 * mas o SQLite dos testes e qualquer comparação em PHP não — por isso a
 * normalização é explícita e `variantes()` expande o nome para todas as
 * grafias realmente gravadas em `alunos`, valendo igual nos dois bancos.
 */
final class NomeCurso
{
    /** Chave de comparação: sem acento, maiúscula, espaços colapsados. */
    public static function chave(?string $nome): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', Str::ascii((string) $nome))));
    }

    public static function mesmo(?string $a, ?string $b): bool
    {
        return self::chave($a) === self::chave($b);
    }

    /** @param iterable<int, string> $lista */
    public static function estaEm(?string $nome, iterable $lista): bool
    {
        $chave = self::chave($nome);
        foreach ($lista as $item) {
            if (self::chave($item) === $chave) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove repetidos (pela chave), escolhendo como grafia canônica a mais
     * acentuada — "ADMINISTRAÇÃO" ganha de "ADMINISTRACAO" — e, no empate, a
     * primeira em ordem alfabética. Resultado em ordem alfabética natural.
     *
     * @param  iterable<int, string>  $nomes
     * @return array<int, string>
     */
    public static function unicos(iterable $nomes): array
    {
        $grupos = [];
        foreach ($nomes as $nome) {
            $nome = trim((string) preg_replace('/\s+/u', ' ', (string) $nome));
            if ($nome === '') {
                continue;
            }
            $grupos[self::chave($nome)][] = $nome;
        }

        $canonicos = [];
        foreach ($grupos as $chave => $variantes) {
            usort($variantes, function (string $a, string $b) {
                // Mais caracteres fora do ASCII (acentos) primeiro.
                return [self::acentos($b), $a] <=> [self::acentos($a), $b];
            });
            $canonicos[$chave] = $variantes[0];
        }

        uksort($canonicos, fn ($a, $b) => strnatcasecmp($a, $b));

        return array_values($canonicos);
    }

    /**
     * Mantém a ordem recebida e descarta só as repetições (pela chave) — pra
     * gravar o que o usuário marcou sem duplicar grafias.
     *
     * @param  iterable<int, string>  $nomes
     * @return array<int, string>
     */
    public static function semRepetidos(iterable $nomes): array
    {
        $vistos = [];
        $resultado = [];
        foreach ($nomes as $nome) {
            $nome = trim((string) preg_replace('/\s+/u', ' ', (string) $nome));
            if ($nome === '' || isset($vistos[self::chave($nome)])) {
                continue;
            }
            $vistos[self::chave($nome)] = true;
            $resultado[] = $nome;
        }

        return $resultado;
    }

    /**
     * Os nomes informados MAIS toda grafia gravada em `alunos.curso` /
     * `aluno_matriculas.curso` que seja
     * o mesmo curso — pra usar em WHERE ... IN (...) sem depender da
     * collation do banco.
     *
     * @param  array<int, string>  $cursos
     * @return array<int, string>
     */
    public static function variantes(array $cursos): array
    {
        if ($cursos === []) {
            return [];
        }

        $chaves = array_map([self::class, 'chave'], $cursos);

        // Grafias gravadas no cadastro atual E no histórico de matrículas.
        // Duas consultas, nunca UNION (collations diferentes entre `alunos` e as tabelas novas).
        $gravados = DB::table('alunos')->whereNotNull('curso')->where('curso', '!=', '')->distinct()->pluck('curso')
            ->merge(DB::table('aluno_matriculas')->whereNotNull('curso')->where('curso', '!=', '')->distinct()->pluck('curso'))
            ->filter(fn ($c) => in_array(self::chave($c), $chaves, true))
            ->all();

        return array_values(array_unique([...$cursos, ...$gravados]));
    }

    private static function acentos(string $nome): int
    {
        return (int) preg_match_all('/[^\x00-\x7F]/u', $nome);
    }
}
