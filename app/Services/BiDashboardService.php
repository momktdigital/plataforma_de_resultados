<?php

namespace App\Services;

use App\Models\Avaliacao;
use App\Models\Resposta;
use App\Support\AlunoVinculoResolver;
use App\Support\Anulacao;
use App\Support\FiltroDemografico;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Painel de BI por Avaliação — equivalente ao dashboard de admin/index.php
 * (histograma de distribuição de acertos, radar por matéria), recalculado a
 * partir do schema novo (`respostas`/`questao_matrizes`) em vez do JSON por
 * aluno do sistema legado.
 *
 * Cada agregação (nota por respondente, desempenho por disciplina) é feita
 * em SQL (JOIN + SUM(CASE...)/COUNT agrupados) — a versão anterior trazia
 * toda linha de `respostas` da avaliação pra um Collection do PHP e varria essa
 * coleção várias vezes (uma vez por questão com matriz, pra cada
 * disciplina), o que não escala: numa avaliação com 167 mil respostas isso
 * travava a página (mesma classe de problema corrigida em
 * EstatisticaErroService).
 */
class BiDashboardService
{
    public function __construct(
        private readonly AlunoVinculoResolver $alunoResolver = new AlunoVinculoResolver,
    ) {}

    public function gerar(Avaliacao $avaliacao, string $periodo = '', ?FiltroDemografico $filtro = null, bool $incluirAusentes = false): array
    {
        $gabaritos = Anulacao::excluirDistribuidas(
            $avaliacao->questoes()
                ->whereNotNull('gabarito')
                ->where('gabarito', '!=', '')
        )->get(['id', 'numero', 'gabarito'])
            ->keyBy('numero');

        if ($gabaritos->isEmpty()) {
            return ['semGabarito' => true];
        }

        $chaves = $filtro !== null
            ? $this->alunoResolver->chavesFiltradas($avaliacao->codigo, $periodo, $filtro, $avaliacao->data_avaliacao)
            : null;

        $semResposta = Resposta::semRespostaSql('respostas.resposta');

        $porRespondente = Resposta::query()
            ->join('questoes', function ($join) use ($avaliacao) {
                Anulacao::excluirDistribuidas(
                    $join->on('questoes.numero', '=', 'respostas.questao_numero')
                        ->where('questoes.avaliacao_codigo', $avaliacao->codigo)
                        ->whereNull('questoes.deleted_at')
                        ->whereNotNull('questoes.gabarito')
                        ->where('questoes.gabarito', '!=', ''),
                    'questoes.anulada_modo',
                );
            })
            ->where('respostas.avaliacao_codigo', $avaliacao->codigo)
            ->when($periodo !== '', fn ($query) => $query->where('respostas.periodo', $periodo))
            ->when($chaves !== null, fn ($query) => $query->whereIn('respostas.aluno_chave', $chaves))
            ->selectRaw('respostas.aluno_chave as aluno_chave, respostas.periodo as periodo')
            ->selectRaw('MAX(respostas.ra) as ra, MAX(respostas.cpf) as cpf')
            // Total por RESPONDENTE (não fixo pra avaliação inteira) — mesmo
            // motivo do ResumoResultadoService: um banco de questões
            // aleatório (Avalia Pro) dá uma quantidade de questões diferente
            // por aluno.
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN '.Anulacao::condicaoAcertoSql('respostas.resposta', 'questoes.gabarito', 'questoes.anulada_modo', 'respostas.correta').' THEN 1 ELSE 0 END) as acertos')
            ->selectRaw("SUM(CASE WHEN NOT {$semResposta} THEN 1 ELSE 0 END) as respondidas")
            ->groupBy('respostas.aluno_chave', 'respostas.periodo')
            ->get();

        if ($porRespondente->isEmpty()) {
            return ['semRespostas' => true];
        }

        $porRespondente = $porRespondente
            ->map(function ($linha) {
                $total = (int) $linha->total;
                $acertos = (int) $linha->acertos;

                return [
                    'ra' => $linha->ra,
                    'cpf' => $linha->cpf,
                    'periodo' => $linha->periodo,
                    'acertos' => $acertos,
                    'total' => $total,
                    'percentual' => $total > 0 ? round($acertos / $total * 100, 1) : 0.0,
                    'ausente' => (int) $linha->respondidas === 0,
                ];
            })
            ->when(! $incluirAusentes, fn (Collection $c) => $c->reject(fn ($r) => $r['ausente'])->values());

        if ($porRespondente->isEmpty()) {
            return ['semRespostas' => true];
        }

        $histograma = array_fill(0, 10, 0);
        foreach ($porRespondente as $r) {
            $indice = (int) min(9, floor($r['percentual'] / 10));
            $histograma[$indice]++;
        }

        return [
            'totalRespondentes' => $porRespondente->count(),
            'histograma' => $histograma,
            'radar' => $this->mediaPorDisciplina($avaliacao, $periodo),
        ];
    }

    /**
     * Nunca recebe o filtro de turma/demografia: "Desempenho médio por
     * disciplina" não está na lista de visuais que o filtro afeta (ver texto
     * na view bi.blade.php) — só "Distribuição de acertos" (o histograma).
     *
     * @return array<string, float>
     */
    private function mediaPorDisciplina(Avaliacao $avaliacao, string $periodo): array
    {
        // Pares (questão, disciplina) distintos primeiro — uma questão com
        // duas linhas de matriz para a MESMA disciplina (períodos/códigos
        // diferentes) não deve contar a resposta duas vezes.
        $paresQuestaoDisciplina = Anulacao::excluirDistribuidas(
            DB::table('questao_matrizes as m')
                ->join('questoes as q', 'q.id', '=', 'm.questao_id')
                ->where('q.avaliacao_codigo', $avaliacao->codigo)
                ->whereNull('q.deleted_at')
                ->whereNotNull('q.gabarito')
                ->where('q.gabarito', '!=', '')
                ->whereNotNull('m.disciplina'),
            'q.anulada_modo',
        )->select('q.numero as numero', 'm.disciplina as disciplina')
            ->distinct()
            ->get();

        if ($paresQuestaoDisciplina->isEmpty()) {
            return [];
        }

        $statsPorQuestao = Resposta::query()
            ->join('questoes', function ($join) use ($avaliacao) {
                $join->on('questoes.numero', '=', 'respostas.questao_numero')
                    ->where('questoes.avaliacao_codigo', $avaliacao->codigo)
                    ->whereNull('questoes.deleted_at');
            })
            ->where('respostas.avaliacao_codigo', $avaliacao->codigo)
            ->when($periodo !== '', fn ($query) => $query->where('respostas.periodo', $periodo))
            ->whereIn('respostas.questao_numero', $paresQuestaoDisciplina->pluck('numero')->unique())
            ->selectRaw('respostas.questao_numero as numero')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN '.Anulacao::condicaoAcertoSql('respostas.resposta', 'questoes.gabarito', 'questoes.anulada_modo', 'respostas.correta').' THEN 1 ELSE 0 END) as acertos')
            ->groupBy('respostas.questao_numero')
            ->get()
            ->keyBy('numero');

        $acumulado = [];
        foreach ($paresQuestaoDisciplina as $par) {
            $stat = $statsPorQuestao->get($par->numero);
            if ($stat === null) {
                continue;
            }

            $acumulado[$par->disciplina] ??= ['acertos' => 0, 'total' => 0];
            $acumulado[$par->disciplina]['acertos'] += (int) $stat->acertos;
            $acumulado[$par->disciplina]['total'] += (int) $stat->total;
        }

        $radar = [];
        foreach ($acumulado as $disciplina => $s) {
            $radar[$disciplina] = $s['total'] > 0 ? round($s['acertos'] / $s['total'] * 100, 1) : 0.0;
        }

        return $radar;
    }
}
