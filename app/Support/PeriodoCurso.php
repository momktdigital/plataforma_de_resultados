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
    public static function ordinal(?string $periodo): ?int
    {
        if ($periodo === null) {
            return null;
        }

        if (preg_match('/^\s*p?(\d{1,2})\s*[º°o]?\s*(per[ií]odo\b.*)?$/iu', $periodo, $m) !== 1) {
            return null;
        }

        $n = (int) $m[1];

        return $n >= 1 && $n <= 20 ? $n : null;
    }

    public static function rotulo(int $ordinal): string
    {
        return $ordinal.'º período';
    }
}
