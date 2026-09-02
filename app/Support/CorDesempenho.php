<?php

namespace App\Support;

/**
 * Faixas de cor únicas pro percentual de acerto em toda a UI do aluno (anel
 * de progresso de cada avaliação, barra de "Desempenho por categoria") —
 * um só lugar pra não ter dois componentes decidindo o limiar de forma
 * diferente. Faixas pedidas: verde ≥60%, amarelo 40-59%, vermelho <40%.
 */
final class CorDesempenho
{
    private const LIMIAR_VERDE = 60.0;

    private const LIMIAR_AMARELO = 40.0;

    /** Cor hex — pra atributos SVG (stroke/fill), que não aceitam classe Tailwind. */
    public static function hex(?float $percentual): string
    {
        return match (true) {
            $percentual === null => '#94a3b8', // slate-400: sem dado
            $percentual >= self::LIMIAR_VERDE => '#10b981', // emerald-500
            $percentual >= self::LIMIAR_AMARELO => '#f59e0b', // amber-500
            default => '#ef4444', // red-500
        };
    }

    /** Classe Tailwind de background — pra barras/pills. */
    public static function classeBg(?float $percentual): string
    {
        return match (true) {
            $percentual === null => 'bg-slate-300',
            $percentual >= self::LIMIAR_VERDE => 'bg-emerald-500',
            $percentual >= self::LIMIAR_AMARELO => 'bg-amber-500',
            default => 'bg-red-500',
        };
    }

    /** Classe Tailwind de cor de texto. */
    public static function classeTexto(?float $percentual): string
    {
        return match (true) {
            $percentual === null => 'text-slate-400',
            $percentual >= self::LIMIAR_VERDE => 'text-emerald-600',
            $percentual >= self::LIMIAR_AMARELO => 'text-amber-600',
            default => 'text-red-600',
        };
    }
}
