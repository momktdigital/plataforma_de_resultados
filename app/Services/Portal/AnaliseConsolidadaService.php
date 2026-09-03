<?php

namespace App\Services\Portal;

use App\Models\Aluno;
use App\Support\Anulacao;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Análises que cruzam VÁRIAS avaliações do aluno num mesmo período letivo
 * (dificuldade, habilidades, Bloom/Miller, comparativo com turma, áreas
 * mais divergentes) — diferente de RelatorioAlunoService, que sempre parte
 * da Collection de respostas de UMA avaliação já carregada em memória, aqui
 * a base pode somar dezenas de avaliações × questões, então a agregação
 * sempre acontece em SQL (DB::table + JOIN + GROUP BY + SUM(CASE...)),
 * nunca varrendo `respostas` inteira em PHP — mesmo espírito de
 * RelatorioAdminService/BiDashboardService.
 */
class AnaliseConsolidadaService
{
    /**
     * "Você errou mais questões fáceis do que difíceis?" — % de acerto do
     * aluno por dificuldade pedagógica, somando todas as avaliações do
     * período informado. Equivalente individual de
     * RelatorioAdminService::curvaDificuldade() (que soma a turma inteira).
     *
     * @param  array<int, int>  $avaliacaoCodigos
     * @return array<string, array{label: string, percentual: float, respostas: int}>
     */
    public function curvaDificuldadePedagogica(Aluno $aluno, array $avaliacaoCodigos): array
    {
        $ordem = ['facil' => 'Fácil', 'medio' => 'Médio', 'dificil' => 'Difícil'];
        $linhas = $this->mediaPorCampoAgregado($aluno, $avaliacaoCodigos, 'dificuldade_pedagogica')->keyBy('campo');

        $resultado = [];
        foreach ($ordem as $chave => $label) {
            $linha = $linhas->get($chave);
            if ($linha === null || (int) $linha->total === 0) {
                continue;
            }

            $resultado[$chave] = [
                'label' => $label,
                'percentual' => round((int) $linha->acertos / (int) $linha->total * 100, 1),
                'respostas' => (int) $linha->total,
            ];
        }

        return $resultado;
    }

    /**
     * Dispersão dificuldade TRI x acerto, ponto a ponto — pensada pra um
     * gráfico de dispersão (scatter), já que dificuldade TRI é uma escala
     * contínua, sem faixas fixas de fácil/médio/difícil como a pedagógica.
     *
     * @param  array<int, int>  $avaliacaoCodigos
     * @return array<int, array{dificuldade_tri: float, acertou: bool}>
     */
    public function dispersaoTri(Aluno $aluno, array $avaliacaoCodigos): array
    {
        if (empty($avaliacaoCodigos)) {
            return [];
        }

        return DB::table('respostas as r')
            ->join('questoes as q', function ($join) use ($avaliacaoCodigos) {
                $join->on('q.numero', '=', 'r.questao_numero')
                    ->on('q.avaliacao_codigo', '=', 'r.avaliacao_codigo')
                    ->whereIn('q.avaliacao_codigo', $avaliacaoCodigos)
                    ->whereNull('q.deleted_at')
                    ->whereNotNull('q.gabarito')->where('q.gabarito', '!=', '')
                    ->whereNotNull('q.dificuldade_tri');
            })
            ->whereIn('r.avaliacao_codigo', $avaliacaoCodigos)
            ->where(fn ($q) => $this->porAluno($q, $aluno))
            ->select('q.dificuldade_tri', 'r.resposta', 'q.gabarito', 'q.anulada_modo')
            ->get()
            ->filter(fn ($l) => ! Anulacao::distribuida($l->anulada_modo))
            ->map(fn ($l) => [
                'dificuldade_tri' => (float) $l->dificuldade_tri,
                'acertou' => Anulacao::acertou($l->resposta, $l->gabarito, $l->anulada_modo),
            ])
            ->values()
            ->all();
    }

    /**
     * % de acerto por habilidade, somando todas as avaliações do período —
     * ordenado do pior pro melhor, pra funcionar como "ranking de
     * habilidades a reforçar" (equivalente individual do
     * heatmap_habilidade_turma do admin, que compara turmas entre si em vez
     * de habilidades de um único aluno).
     *
     * @param  array<int, int>  $avaliacaoCodigos
     * @return array<string, float> habilidade => percentual
     */
    public function coberturaHabilidade(Aluno $aluno, array $avaliacaoCodigos): array
    {
        return $this->percentualPorCampo($aluno, $avaliacaoCodigos, 'habilidade')
            ->sort()
            ->all();
    }

    /** @param  array<int, int>  $avaliacaoCodigos
     * @return array<string, float> */
    public function desempenhoBloomConsolidado(Aluno $aluno, array $avaliacaoCodigos): array
    {
        return $this->percentualPorCampo($aluno, $avaliacaoCodigos, 'bloom_nivel')->all();
    }

    /** @param  array<int, int>  $avaliacaoCodigos
     * @return array<string, float> */
    public function desempenhoMillerConsolidado(Aluno $aluno, array $avaliacaoCodigos): array
    {
        return $this->percentualPorCampo($aluno, $avaliacaoCodigos, 'miller_nivel')->all();
    }

    /**
     * Áreas onde o aluno mais fica atrás da turma: % de acerto do aluno por
     * área (somando todas as avaliações do período) comparado ao % médio de
     * acerto da turma inteira na MESMA área — só entram áreas onde o aluno
     * fica pelo menos $diferencaMinima pontos abaixo da turma (uma diferença
     * de 1-2 pontos é ruído de amostra pequena, não divergência real),
     * ordenadas pela maior diferença primeiro. Trocado de "por tema" pra
     * "por área" porque tema é granular demais pra virar um sinal acionável
     * (a lista virava uma dúzia de linhas de 1 ocorrência cada); área
     * generaliza o suficiente pra apontar "onde estudar" sem perder a
     * comparação direta aluno×turma.
     *
     * @param  array<int, int>  $avaliacaoCodigos
     * @return array<int, array{area: string, percentualAluno: float, percentualTurma: float, diferenca: float}>
     */
    public function areasDivergentesDaTurma(Aluno $aluno, array $avaliacaoCodigos, float $diferencaMinima = 10.0, int $limite = 8): array
    {
        if (empty($avaliacaoCodigos)) {
            return [];
        }

        $doAluno = $this->mediaPorCampoAgregado($aluno, $avaliacaoCodigos, 'area')
            ->filter(fn ($l) => (int) $l->total > 0)
            ->keyBy('campo');

        $daTurma = $this->mediaPorCampoAgregado(null, $avaliacaoCodigos, 'area')
            ->filter(fn ($l) => (int) $l->total > 0)
            ->keyBy('campo');

        $resultado = [];
        foreach ($doAluno as $area => $linhaAluno) {
            $linhaTurma = $daTurma->get($area);
            if ($linhaTurma === null) {
                continue;
            }

            $percentualAluno = round((int) $linhaAluno->acertos / (int) $linhaAluno->total * 100, 1);
            $percentualTurma = round((int) $linhaTurma->acertos / (int) $linhaTurma->total * 100, 1);
            $diferenca = round($percentualTurma - $percentualAluno, 1);

            if ($diferenca < $diferencaMinima) {
                continue;
            }

            $resultado[] = [
                'area' => $area,
                'percentualAluno' => $percentualAluno,
                'percentualTurma' => $percentualTurma,
                'diferenca' => $diferenca,
            ];
        }

        usort($resultado, fn ($a, $b) => $b['diferenca'] <=> $a['diferenca']);

        return array_slice($resultado, 0, $limite);
    }

    /** @param  array<int, int>  $avaliacaoCodigos
     * @return Collection<string, float> */
    private function percentualPorCampo(Aluno $aluno, array $avaliacaoCodigos, string $campo): Collection
    {
        return $this->mediaPorCampoAgregado($aluno, $avaliacaoCodigos, $campo)
            ->filter(fn ($l) => (int) $l->total > 0)
            ->mapWithKeys(fn ($l) => [$l->campo => round((int) $l->acertos / (int) $l->total * 100, 1)]);
    }

    /**
     * Soma acertos/total agrupado por um campo direto de `questoes`
     * (dificuldade_pedagogica, habilidade, bloom_nivel, miller_nivel, area),
     * somando todas as avaliações informadas — mesmo padrão de
     * RelatorioAdminService::mediaPorCampoDireto(). $aluno null soma a TURMA
     * inteira (todos os respondentes, sem filtrar por um aluno) — usado por
     * areasDivergentesDaTurma() pra comparar o aluno contra a turma na mesma
     * agregação, sem duplicar a query.
     *
     * @param  array<int, int>  $avaliacaoCodigos
     * @return Collection<int, object{campo: string, total: int, acertos: int}>
     */
    private function mediaPorCampoAgregado(?Aluno $aluno, array $avaliacaoCodigos, string $campo): Collection
    {
        if (empty($avaliacaoCodigos)) {
            return collect();
        }

        $query = DB::table('respostas as r')
            ->join('questoes as q', function ($join) use ($avaliacaoCodigos, $campo) {
                Anulacao::excluirDistribuidas(
                    $join->on('q.numero', '=', 'r.questao_numero')
                        ->on('q.avaliacao_codigo', '=', 'r.avaliacao_codigo')
                        ->whereIn('q.avaliacao_codigo', $avaliacaoCodigos)
                        ->whereNull('q.deleted_at')
                        ->whereNotNull('q.gabarito')->where('q.gabarito', '!=', '')
                        ->whereNotNull("q.{$campo}")->where("q.{$campo}", '!=', ''),
                    'q.anulada_modo',
                );
            })
            ->whereIn('r.avaliacao_codigo', $avaliacaoCodigos);

        if ($aluno !== null) {
            $query->where(fn ($q) => $this->porAluno($q, $aluno));
        }

        return $query
            ->groupBy("q.{$campo}")
            ->selectRaw("q.{$campo} as campo")
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN '.Anulacao::condicaoAcertoSql('r.resposta', 'q.gabarito', 'q.anulada_modo').' THEN 1 ELSE 0 END) as acertos')
            ->get();
    }

    /** Mesmo critério de identidade usado em ResultadoConsultaService::porAluno() — nunca casar por RA/CPF vazios. */
    private function porAluno(BuilderContract $query, Aluno $aluno): BuilderContract
    {
        if (! $aluno->ra && ! $aluno->cpf) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($aluno) {
            if ($aluno->ra) {
                $q->where('r.ra', $aluno->ra);
            }
            if ($aluno->cpf) {
                $q->orWhere('r.cpf', $aluno->cpf);
            }
        });
    }
}
