<?php

namespace App\Support;

/**
 * Faixas de cor únicas pro percentual de acerto em toda a UI do aluno (anel
 * de progresso de cada avaliação, barra de "Desempenho por categoria") —
 * um só lugar pra não ter dois componentes decidindo o limiar de forma
 * diferente. Faixas pedidas: verde ≥60%, amarelo <60% (sem vermelho — o
 * aluno nunca vê um resultado em vermelho).
 */
final class CorDesempenho
{
    private const LIMIAR_VERDE = 60.0;

    /** Cor hex — pra atributos SVG (stroke/fill), que não aceitam classe Tailwind. */
    public static function hex(?float $percentual): string
    {
        return match (true) {
            $percentual === null => '#94a3b8', // slate-400: sem dado
            $percentual >= self::LIMIAR_VERDE => '#10b981', // emerald-500
            default => '#f59e0b', // amber-500
        };
    }

    /** Classe Tailwind de background — pra barras/pills. */
    public static function classeBg(?float $percentual): string
    {
        return match (true) {
            $percentual === null => 'bg-slate-300',
            $percentual >= self::LIMIAR_VERDE => 'bg-emerald-500',
            default => 'bg-amber-500',
        };
    }

    /** Classe Tailwind de cor de texto. */
    public static function classeTexto(?float $percentual): string
    {
        return match (true) {
            $percentual === null => 'text-slate-400',
            $percentual >= self::LIMIAR_VERDE => 'text-emerald-600',
            default => 'text-amber-600',
        };
    }
}
