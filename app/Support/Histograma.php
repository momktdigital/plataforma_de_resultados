<?php

namespace App\Support;

/**
 * Estatística sobre um histograma de percentuais de acerto: `[décimos de ponto => quantidade]` (59,9% → 599).
 *
 * É o que permite ao painel da reitoria calcular média, mediana, quartis, faixas e patamares de proficiência de
 * dezenas de milhares de resultados SEM trazê-los para o PHP: `resultado_resumos.percentual` tem uma casa decimal
 * (no máximo 1001 valores possíveis), então um `GROUP BY percentual` no banco devolve, por curso e período, no
 * máximo ~1000 linhas — e daí sai tudo de forma exata (nada de aproximar por faixas de 1 ponto, que classificaria
 * 59,9% como 60%).
 *
 * Funções puras, sem Laravel: ver tests/Unit/HistogramaTest.php.
 */
final class Histograma
{
    /** Soma `$quantidade` ocorrências do percentual ao histograma. */
    public static function adicionar(array &$histograma, float|int|string $percentual, int $quantidade = 1): void
    {
        $chave = (int) round(((float) $percentual) * 10);
        $histograma[$chave] = ($histograma[$chave] ?? 0) + $quantidade;
    }

    /** @param array<int, int> $a  @param array<int, int> $b @return array<int, int> */
    public static function mesclar(array $a, array $b): array
    {
        foreach ($b as $chave => $quantidade) {
            $a[$chave] = ($a[$chave] ?? 0) + $quantidade;
        }

        return $a;
    }

    /** @param array<int, int> $h */
    public static function n(array $h): int
    {
        return (int) array_sum($h);
    }

    /** @param array<int, int> $h */
    public static function soma(array $h): float
    {
        $soma = 0;
        foreach ($h as $chave => $quantidade) {
            $soma += $chave * $quantidade;
        }

        return $soma / 10;
    }

    /** @param array<int, int> $h */
    public static function media(array $h): ?float
    {
        $n = self::n($h);

        return $n > 0 ? self::soma($h) / $n : null;
    }

    /** @param array<int, int> $h */
    public static function minimo(array $h): ?float
    {
        $h = array_filter($h);

        return $h === [] ? null : min(array_keys($h)) / 10;
    }

    /** @param array<int, int> $h */
    public static function maximo(array $h): ?float
    {
        $h = array_filter($h);

        return $h === [] ? null : max(array_keys($h)) / 10;
    }

    /**
     * Quantil `$p` (0 a 1) com interpolação linear entre as duas observações vizinhas (a mesma definição da
     * mediana/percentil das planilhas — PERCENTIL.INC). Mediana = quantil(0.5).
     *
     * @param  array<int, int>  $h
     */
    public static function quantil(array $h, float $p): ?float
    {
        $n = self::n($h);
        if ($n === 0) {
            return null;
        }

        $posicao = ($n - 1) * max(0.0, min(1.0, $p));
        $baixo = (int) floor($posicao);
        $alto = (int) ceil($posicao);
        $valorBaixo = $valorAlto = null;

        ksort($h);
        $acumulado = 0;
        foreach ($h as $chave => $quantidade) {
            if ($quantidade <= 0) {
                continue;
            }
            $acumulado += $quantidade;
            // a observação de índice i (0-based) é a primeira cujo acumulado passa de i
            if ($valorBaixo === null && $acumulado > $baixo) {
                $valorBaixo = $chave;
            }
            if ($acumulado > $alto) {
                $valorAlto = $chave;
                break;
            }
        }

        return ($valorBaixo + ($posicao - $baixo) * ($valorAlto - $valorBaixo)) / 10;
    }

    /**
     * Quantidades por balde de `$largura` pontos de percentual, de 0 a 100: [0–5), [5–10)... [95–100]. O 100% cai no
     * último balde (não cria um balde só para ele).
     *
     * @param  array<int, int>  $h
     * @return array<int, int>
     */
    public static function baldes(array $h, int $largura = 5): array
    {
        $quantos = (int) ceil(100 / $largura);
        $baldes = array_fill(0, $quantos, 0);
        foreach ($h as $chave => $quantidade) {
            $indice = min($quantos - 1, max(0, intdiv($chave, $largura * 10)));
            $baldes[$indice] += $quantidade;
        }

        return $baldes;
    }

    /** Quantos têm percentual IGUAL OU ACIMA do corte. @param array<int, int> $h */
    public static function contarAcima(array $h, float $corte): int
    {
        $limite = (int) round($corte * 10);
        $total = 0;
        foreach ($h as $chave => $quantidade) {
            if ($chave >= $limite) {
                $total += $quantidade;
            }
        }

        return $total;
    }

    /** Quantos têm percentual em [$de, $ate) — `$ate` null = sem teto. @param array<int, int> $h */
    public static function contarEntre(array $h, float $de, ?float $ate): int
    {
        $inicio = (int) round($de * 10);
        $fim = $ate === null ? null : (int) round($ate * 10);
        $total = 0;
        foreach ($h as $chave => $quantidade) {
            if ($chave >= $inicio && ($fim === null || $chave < $fim)) {
                $total += $quantidade;
            }
        }

        return $total;
    }
}
