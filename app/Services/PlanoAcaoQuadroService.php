<?php

namespace App\Services;

use App\Models\PlanoAcao;
use App\Support\NomeCurso;
use Illuminate\Support\Collection;

/**
 * Quadro dos planos de ação por curso: quantos em cada situação, como anda a execução das ações e quanto tempo a análise leva.
 * Só números — serve ao colaborador (visão geral da fila) e à reitoria, que NUNCA vê o texto de um plano.
 *
 * Trabalha sobre os planos já carregados (com `acoes` e `eventos`): são centenas no máximo, e agregar em PHP evita repetir
 * a regra de "ação atrasada" e "plano parado" em SQL.
 */
class PlanoAcaoQuadroService
{
    /**
     * @param  Collection<int, PlanoAcao>  $planos  só os já enviados (rascunho não entra), com `acoes` e `eventos`
     * @return array<int, array{curso: string, total: int, em_analise: int, ajustes: int, em_execucao: int, concluidos: int, recusados: int, cancelados: int, acoes: int, acoes_concluidas: int, acoes_atrasadas: int, pct_acoes: ?int, dias_analise: ?float, parados: int}>
     */
    public function porCurso(Collection $planos): array
    {
        $linhas = [];

        foreach ($planos->groupBy(fn (PlanoAcao $p) => NomeCurso::chave($p->curso)) as $grupo) {
            $acoes = 0;
            $concluidas = 0;
            $atrasadas = 0;
            foreach ($grupo as $p) {
                if (in_array($p->status, [PlanoAcao::APROVADO, PlanoAcao::CONCLUIDO], true)) {
                    $progresso = $p->progresso();
                    $acoes += $progresso['total'];
                    $concluidas += $progresso['concluidas'];
                    $atrasadas += $progresso['atrasadas'];
                }
            }

            $tempos = $grupo->filter(fn (PlanoAcao $p) => $p->enviado_em !== null && $p->decidido_em !== null)->map(fn (PlanoAcao $p) => $p->enviado_em->diffInDays($p->decidido_em));
            $contar = fn (string $status) => $grupo->where('status', $status)->count();

            $linhas[] = [
                'curso' => (string) $grupo->first()->curso,
                'total' => $grupo->count(),
                'em_analise' => $contar(PlanoAcao::EM_ANALISE),
                'ajustes' => $contar(PlanoAcao::AJUSTES),
                'em_execucao' => $contar(PlanoAcao::APROVADO),
                'concluidos' => $contar(PlanoAcao::CONCLUIDO),
                'recusados' => $contar(PlanoAcao::RECUSADO),
                'cancelados' => $contar(PlanoAcao::CANCELADO),
                'acoes' => $acoes,
                'acoes_concluidas' => $concluidas,
                'acoes_atrasadas' => $atrasadas,
                'pct_acoes' => $acoes > 0 ? (int) round($concluidas / $acoes * 100) : null,
                'dias_analise' => $tempos->isNotEmpty() ? round($tempos->avg(), 1) : null,
                'parados' => $grupo->filter(fn (PlanoAcao $p) => $p->estaParado())->count(),
            ];
        }

        usort($linhas, fn ($a, $b) => strnatcasecmp(NomeCurso::chave($a['curso']), NomeCurso::chave($b['curso'])));

        return $linhas;
    }

    /**
     * Os totais do quadro, mais a taxa de aprovação (dos planos já decididos, quantos foram aprovados).
     *
     * @param  array<int, array<string, mixed>>  $linhas  saída de porCurso()
     * @return array<string, mixed>
     */
    public function totais(array $linhas, Collection $planos): array
    {
        $soma = fn (string $chave) => array_sum(array_column($linhas, $chave));
        $decididos = $planos->filter(fn (PlanoAcao $p) => $p->decidido_em !== null);
        $aprovados = $decididos->filter(fn (PlanoAcao $p) => in_array($p->status, [PlanoAcao::APROVADO, PlanoAcao::CONCLUIDO], true) || $p->eventos->contains(fn ($e) => $e->tipo === 'aprovado'))->count();
        $tempos = $planos->filter(fn (PlanoAcao $p) => $p->enviado_em !== null && $p->decidido_em !== null)->map(fn (PlanoAcao $p) => $p->enviado_em->diffInDays($p->decidido_em));

        return [
            'planos' => $soma('total'),
            'em_analise' => $soma('em_analise'),
            'ajustes' => $soma('ajustes'),
            'em_execucao' => $soma('em_execucao'),
            'concluidos' => $soma('concluidos'),
            'recusados' => $soma('recusados'),
            'acoes' => $soma('acoes'),
            'acoes_concluidas' => $soma('acoes_concluidas'),
            'acoes_atrasadas' => $soma('acoes_atrasadas'),
            'parados' => $soma('parados'),
            'pct_acoes' => $soma('acoes') > 0 ? (int) round($soma('acoes_concluidas') / $soma('acoes') * 100) : null,
            'dias_analise' => $tempos->isNotEmpty() ? round($tempos->avg(), 1) : null,
            'taxa_aprovacao' => $decididos->count() > 0 ? (int) round($aprovados / $decididos->count() * 100) : null,
        ];
    }
}
