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

    public static function rotulo(int $ordinal): string
    {
        return $ordinal.'º período';
    }
}
