<?php

namespace App\Support;

/**
 * "Período do curso" (1º, 2º... 12º) como vem das planilhas — `5º`, `5°`,
 * `5º PERÍODO`, `5`, `P5` e `12° PERÍODO DE MEDICINA` são o mesmo período.
 * Qualquer outra coisa (vazio, "2026/1" digitado na coluna errada...) não é
 * período do curso e devolve null.
 */
final class PeriodoCurso
{
    /** Maior período aceito — o mesmo teto de ordinal(). */
    public const MAXIMO = 20;

    public static function ordinal(?string $periodo): ?int
    {
        if ($periodo === null) {
            return null;
        }

        if (preg_match('/^\s*p?(\d{1,2})\s*[º°o]?\s*(per[ií]odo\b.*)?$/iu', $periodo, $m) !== 1) {
            return null;
        }

        $n = (int) $m[1];

        return $n >= 1 && $n <= self::MAXIMO ? $n : null;
    }

    /**
     * A questão (meta `questoes.periodo_minimo`) é de um período À FRENTE do aluno? Sem meta ou sem período
     * reconhecível não há como dizer — e então ela vale para todos, não fica de fora.
     */
    public static function aFrente(?int $periodoDoAluno, ?int $periodoMinimo): bool
    {
        return $periodoDoAluno !== null && $periodoMinimo !== null && $periodoMinimo > $periodoDoAluno;
    }

    /**
     * A fração do que cabe no período que vale como META: o aluno do 1º período numa prova de 100 questões em que só 10 cabem
     * nele não precisa acertar 10 (100% do que cabe), e sim 6 (60% do que cabe) — 6% da prova. Quando a prova não traz o período
     * das questões, tudo cabe em todos e a meta é a de sempre, 60% da prova.
     */
    public const FRACAO_DA_META = 0.6;

    /**
     * A meta (em % da prova inteira) de quem tem `$esperadas` questões, de `$total`, ao alcance do período dele: 60% da fatia que
     * cabe no período (FRACAO_DA_META). 10 de 100 → 6,0%; todas (100 de 100) → 60,0%.
     */
    public static function metaDoPeriodo(int|float $esperadas, int|float $total): float
    {
        return $total > 0 ? round($esperadas / $total * 100 * self::FRACAO_DA_META, 1) : 0.0;
    }

    public static function rotulo(int $ordinal): string
    {
        return $ordinal.'º período';
    }
}
