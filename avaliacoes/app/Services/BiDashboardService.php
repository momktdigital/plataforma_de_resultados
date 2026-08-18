<?php

namespace App\Services;

use App\Models\Prova;
use App\Models\QuestaoMatriz;

/**
 * Painel de BI por Prova — equivalente ao dashboard de admin/index.php
 * (histograma de distribuição de acertos, radar por matéria, Top 5),
 * recalculado a partir do schema novo (`respostas`/`questao_matrizes`) em
 * vez do JSON por aluno do sistema legado.
 */
class BiDashboardService
{
    public function gerar(Prova $prova, string $periodo = ''): array
    {
        $gabaritos = $prova->questoes()
            ->whereNotNull('gabarito')
            ->where('gabarito', '!=', '')
            ->get(['id', 'numero', 'gabarito'])
            ->keyBy('numero');

        if ($gabaritos->isEmpty()) {
            return ['semGabarito' => true];
        }

        $respostas = $prova->resultados()
            ->whereIn('questao_numero', $gabaritos->keys())
            ->when($periodo !== '', fn ($query) => $query->where('periodo', $periodo))
            ->get(['aluno_chave', 'ra', 'cpf', 'periodo', 'questao_numero', 'resposta']);

        if ($respostas->isEmpty()) {
            return ['semRespostas' => true];
        }

        $totalQuestoes = $gabaritos->count();

        $porRespondente = $respostas->groupBy(fn ($r) => $r->aluno_chave.'|'.$r->periodo)
            ->map(function ($grupo) use ($gabaritos, $totalQuestoes) {
                $acertos = $grupo->filter(
                    fn ($r) => $gabaritos->has($r->questao_numero)
                        && $r->resposta === $gabaritos[$r->questao_numero]->gabarito
                )->count();

                return [
                    'ra' => $grupo->first()->ra,
                    'cpf' => $grupo->first()->cpf,
                    'periodo' => $grupo->first()->periodo,
                    'acertos' => $acertos,
                    'total' => $totalQuestoes,
                    'percentual' => $totalQuestoes > 0 ? round($acertos / $totalQuestoes * 100, 1) : 0.0,
                ];
            })
            ->values();

        $histograma = array_fill(0, 10, 0);
        foreach ($porRespondente as $r) {
            $indice = (int) min(9, floor($r['percentual'] / 10));
            $histograma[$indice]++;
        }

        $top5 = $porRespondente->sortByDesc('percentual')->take(5)->values()->all();

        return [
            'totalRespondentes' => $porRespondente->count(),
            'histograma' => $histograma,
            'top5' => $top5,
            'radar' => $this->mediaPorDisciplina($gabaritos, $respostas),
        ];
    }

    /** @return array<string, float> */
    private function mediaPorDisciplina($gabaritos, $respostas): array
    {
        $matrizesPorQuestao = QuestaoMatriz::whereIn('questao_id', $gabaritos->pluck('id'))
            ->whereNotNull('disciplina')
            ->get()
            ->groupBy('questao_id');

        if ($matrizesPorQuestao->isEmpty()) {
            return [];
        }

        $numeroPorQuestaoId = $gabaritos->pluck('numero', 'id');

        $stats = [];
        foreach ($matrizesPorQuestao as $questaoId => $matrizes) {
            $numero = $numeroPorQuestaoId[$questaoId] ?? null;
            if ($numero === null) {
                continue;
            }

            $gabaritoCorreto = $gabaritos[$numero]->gabarito;
            $respostasQuestao = $respostas->where('questao_numero', $numero);
            $totalQ = $respostasQuestao->count();
            $acertosQ = $respostasQuestao->where('resposta', $gabaritoCorreto)->count();

            foreach ($matrizes->pluck('disciplina')->unique() as $disciplina) {
                $stats[$disciplina] ??= ['acertos' => 0, 'total' => 0];
                $stats[$disciplina]['acertos'] += $acertosQ;
                $stats[$disciplina]['total'] += $totalQ;
            }
        }

        $radar = [];
        foreach ($stats as $disciplina => $s) {
            $radar[$disciplina] = $s['total'] > 0 ? round($s['acertos'] / $s['total'] * 100, 1) : 0.0;
        }

        return $radar;
    }
}
