<?php

namespace App\Services;

use App\Models\PlanoAcao;
use App\Support\NomeCurso;
use Illuminate\Support\Collection;

/**
 * Quadro dos planos de ação por curso: quantos em cada situação e como anda a execução das ações.
 * Só números — serve ao colaborador (visão geral da fila) e à reitoria, que NUNCA vê o texto de um plano.
 *
 * Trabalha sobre os planos já carregados (com `acoes` e `eventos`): são centenas no máximo, e agregar em PHP evita repetir
 * a regra de "ação atrasada" e "plano parado" em SQL.
 */
class PlanoAcaoQuadroService
{
    /**
     * @param  Collection<int, PlanoAcao>  $planos  só os já publicados (rascunho não entra), com `acoes` e `eventos`
     * @return array<int, array{curso: string, total: int, em_execucao: int, concluidos: int, cancelados: int, acoes: int, acoes_concluidas: int, acoes_atrasadas: int, pct_acoes: ?int, parados: int}>
     */
    public function porCurso(Collection $planos): array
    {
        $linhas = [];

        foreach ($planos->groupBy(fn (PlanoAcao $p) => NomeCurso::chave($p->curso)) as $grupo) {
            $acoes = 0;
            $concluidas = 0;
            $atrasadas = 0;
            foreach ($grupo as $p) {
                if (in_array($p->status, [PlanoAcao::EM_EXECUCAO, PlanoAcao::CONCLUIDO], true)) {
                    $progresso = $p->progresso();
                    $acoes += $progresso['total'];
                    $concluidas += $progresso['concluidas'];
                    $atrasadas += $progresso['atrasadas'];
                }
            }

            $contar = fn (string $status) => $grupo->where('status', $status)->count();

            $linhas[] = [
                'curso' => (string) $grupo->first()->curso,
                'total' => $grupo->count(),
                'em_execucao' => $contar(PlanoAcao::EM_EXECUCAO),
                'concluidos' => $contar(PlanoAcao::CONCLUIDO),
                'cancelados' => $contar(PlanoAcao::CANCELADO),
                'acoes' => $acoes,
                'acoes_concluidas' => $concluidas,
                'acoes_atrasadas' => $atrasadas,
                'pct_acoes' => $acoes > 0 ? (int) round($concluidas / $acoes * 100) : null,
                'parados' => $grupo->filter(fn (PlanoAcao $p) => $p->estaParado())->count(),
            ];
        }

        usort($linhas, fn ($a, $b) => strnatcasecmp(NomeCurso::chave($a['curso']), NomeCurso::chave($b['curso'])));

        return $linhas;
    }

    /**
     * Os totais do quadro.
     *
     * @param  array<int, array<string, mixed>>  $linhas  saída de porCurso()
     * @return array<string, mixed>
     */
    public function totais(array $linhas): array
    {
        $soma = fn (string $chave) => array_sum(array_column($linhas, $chave));

        return [
            'planos' => $soma('total'),
            'em_execucao' => $soma('em_execucao'),
            'concluidos' => $soma('concluidos'),
            'cancelados' => $soma('cancelados'),
            'acoes' => $soma('acoes'),
            'acoes_concluidas' => $soma('acoes_concluidas'),
            'acoes_atrasadas' => $soma('acoes_atrasadas'),
            'parados' => $soma('parados'),
            'pct_acoes' => $soma('acoes') > 0 ? (int) round($soma('acoes_concluidas') / $soma('acoes') * 100) : null,
        ];
    }
}
