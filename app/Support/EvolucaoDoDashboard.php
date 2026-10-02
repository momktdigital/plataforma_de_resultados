<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Preparo, para as views do Dashboard, dos períodos do curso que entram no gráfico "Evolução da média na
 * categoria". O bloco de HTML e o script do gráfico precisam do MESMO conjunto — por isso mora aqui, e não num
 * `@php` de uma das duas views (variáveis de um @include não chegam ao outro).
 */
final class EvolucaoDoDashboard
{
    /**
     * Só os períodos que têm ao menos uma turma com resultado nas avaliações da categoria.
     *
     * @param  array<int, array{rotulo: string, turmas: array<string, array<int, array<string, mixed>>>}>|null  $evolucaoPorPeriodo
     * @return Collection<int, array{rotulo: string, turmas: array<string, array<int, array<string, mixed>>>}>
     */
    public static function periodosComEvolucao(?array $evolucaoPorPeriodo): Collection
    {
        return collect($evolucaoPorPeriodo ?? [])
            ->map(fn ($periodo) => [...$periodo, 'turmas' => collect($periodo['turmas'])->filter(fn ($pontos) => count($pontos) >= 1)->all()])
            ->filter(fn ($periodo) => $periodo['turmas'] !== []);
    }
}
