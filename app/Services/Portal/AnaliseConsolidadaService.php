<?php

namespace App\Services\Portal;

use App\Models\Aluno;
use App\Support\Anulacao;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Análises que cruzam VÁRIAS avaliações do aluno num mesmo período letivo
 * (dificuldade, habilidades, Bloom/Miller, comparativo com turma, questões
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
     * Áreas/temas onde o aluno mais diverge da turma: questões que ele
     * ERROU e que a turma, no agregado, ACERTOU acima de $limiarTurmaAcerto
     * — sinal de erro conceitual específico, não só "prova difícil pra
     * todo mundo". Agrupado por área+tema (não por questão isolada, que não
     * se repete entre avaliações diferentes) e ordenado pela quantidade de
     * ocorrências.
     *
     * @param  array<int, int>  $avaliacaoCodigos
     * @return array<int, array{area: string, tema: string, ocorrencias: int, errosTurmaMedia: float}>
     */
    public function questoesDivergentesDaTurma(Aluno $aluno, array $avaliacaoCodigos, float $limiarTurmaAcerto = 60.0, int $limite = 8): array
    {
        if (empty($avaliacaoCodigos)) {
            return [];
        }

        // Passo 1: taxa de acerto da TURMA (todos os respondentes) por
        // questão — feito uma vez em SQL pra não precisar de N consultas
        // (uma por avaliação), igual RelatorioAlunoService::comparativoQuestao()
        // faz pra uma avaliação só.
        $taxasPorQuestao = DB::table('respostas as r')
            ->join('questoes as q', function ($join) use ($avaliacaoCodigos) {
                Anulacao::excluirDistribuidas(
                    $join->on('q.numero', '=', 'r.questao_numero')
                        ->on('q.avaliacao_codigo', '=', 'r.avaliacao_codigo')
                        ->whereIn('q.avaliacao_codigo', $avaliacaoCodigos)
                        ->whereNull('q.deleted_at')
                        ->whereNotNull('q.gabarito')->where('q.gabarito', '!=', ''),
                    'q.anulada_modo',
                );
            })
            ->whereIn('r.avaliacao_codigo', $avaliacaoCodigos)
            ->groupBy('r.avaliacao_codigo', 'r.questao_numero')
            ->selectRaw('r.avaliacao_codigo as avaliacao_codigo, r.questao_numero as numero')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN '.Anulacao::condicaoAcertoSql('r.resposta', 'q.gabarito', 'q.anulada_modo').' THEN 1 ELSE 0 END) as acertos')
            ->get()
            ->keyBy(fn ($l) => "{$l->avaliacao_codigo}:{$l->numero}");

        // Passo 2: respostas DESTE aluno, com área/tema, pra cruzar com as
        // taxas calculadas acima.
        $respostasDoAluno = DB::table('respostas as r')
            ->join('questoes as q', function ($join) use ($avaliacaoCodigos) {
                $join->on('q.numero', '=', 'r.questao_numero')
                    ->on('q.avaliacao_codigo', '=', 'r.avaliacao_codigo')
                    ->whereIn('q.avaliacao_codigo', $avaliacaoCodigos)
                    ->whereNull('q.deleted_at')
                    ->whereNotNull('q.gabarito')->where('q.gabarito', '!=', '')
                    ->whereNotNull('q.area')->where('q.area', '!=', '')
                    ->whereNotNull('q.tema')->where('q.tema', '!=', '');
            })
            ->whereIn('r.avaliacao_codigo', $avaliacaoCodigos)
            ->where(fn ($q) => $this->porAluno($q, $aluno))
            ->select('r.avaliacao_codigo', 'r.questao_numero', 'r.resposta', 'q.gabarito', 'q.area', 'q.tema', 'q.anulada_modo')
            ->get();

        $acumulado = [];
        foreach ($respostasDoAluno as $resposta) {
            if (Anulacao::distribuida($resposta->anulada_modo)) {
                continue;
            }
            if (Anulacao::acertou($resposta->resposta, $resposta->gabarito, $resposta->anulada_modo)) {
                continue;
            }

            $taxa = $taxasPorQuestao->get("{$resposta->avaliacao_codigo}:{$resposta->questao_numero}");
            $taxaAcertoTurma = ($taxa !== null && (int) $taxa->total > 0)
                ? round((int) $taxa->acertos / (int) $taxa->total * 100, 1)
                : 0.0;

            if ($taxaAcertoTurma < $limiarTurmaAcerto) {
                continue;
            }

            // Nº absoluto de respondentes que erraram a questão (não %): a
            // coluna ao lado já é "vezes que VOCÊ errou" (uma contagem), e
            // misturar contagem com taxa percentual obriga o aluno a
            // converter um dos dois números de cabeça pra comparar.
            $errosTurma = $taxa !== null ? max(0, (int) $taxa->total - (int) $taxa->acertos) : 0;

            $chave = "{$resposta->area}|{$resposta->tema}";
            $acumulado[$chave] ??= ['area' => $resposta->area, 'tema' => $resposta->tema, 'ocorrencias' => 0, 'somaErrosTurma' => 0];
            $acumulado[$chave]['ocorrencias']++;
            $acumulado[$chave]['somaErrosTurma'] += $errosTurma;
        }

        $resultado = array_map(fn ($a) => [
            'area' => $a['area'],
            'tema' => $a['tema'],
            'ocorrencias' => $a['ocorrencias'],
            'errosTurmaMedia' => round($a['somaErrosTurma'] / $a['ocorrencias'], 1),
        ], array_values($acumulado));

        usort($resultado, fn ($a, $b) => $b['ocorrencias'] <=> $a['ocorrencias']);

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
     * Soma acertos/total do aluno, agrupado por um campo direto de
     * `questoes` (dificuldade_pedagogica, habilidade, bloom_nivel,
     * miller_nivel), somando todas as avaliações informadas — mesmo padrão
     * de RelatorioAdminService::mediaPorCampoDireto(), mas filtrando por UM
     * aluno em vez de por uma avaliação inteira.
     *
     * @param  array<int, int>  $avaliacaoCodigos
     * @return Collection<int, object{campo: string, total: int, acertos: int}>
     */
    private function mediaPorCampoAgregado(Aluno $aluno, array $avaliacaoCodigos, string $campo): Collection
    {
        if (empty($avaliacaoCodigos)) {
            return collect();
        }

        return DB::table('respostas as r')
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
            ->whereIn('r.avaliacao_codigo', $avaliacaoCodigos)
            ->where(fn ($q) => $this->porAluno($q, $aluno))
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
