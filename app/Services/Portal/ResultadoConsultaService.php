<?php

namespace App\Services\Portal;

use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\Resposta;
use App\Models\ResultadoMetrica;
use App\Models\ResultadoResumo;
use App\Support\Anulacao;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Busca os resultados de um aluno no schema novo (respostas/
 * resultado_metricas/questoes) para exibição no portal público —
 * equivalente à consulta por RA em api/consulta.php, mas contra
 * `avaliações`/`questoes` em vez do JSON de `resultados`/`gabaritos`.
 */
class ResultadoConsultaService
{
    /**
     * Boletim (lista de cards) — só precisa de acertos/total/percentual por
     * avaliação, então lê de `resultado_resumos` (App\Services\ResumoResultadoService)
     * em vez de escanear `respostas`, que cresce um por aluno×avaliação×questão.
     *
     * @return array<int, array{avaliação: Avaliação, periodo: string, periodo_letivo: string, acertos: int, total: int, percentual: ?float}>
     */
    public function buscarPorAluno(Aluno $aluno): array
    {
        return ResultadoResumo::with('avaliacao.categoria')
            ->where(fn ($q) => $this->porAluno($q, $aluno))
            ->get()
            ->filter(fn (ResultadoResumo $resumo) => $resumo->avaliacao !== null)
            ->map(fn (ResultadoResumo $resumo) => [
                'avaliacao' => $resumo->avaliacao,
                'periodo' => $resumo->periodo,
                'periodo_letivo' => $this->periodoLetivo($resumo->avaliacao->data_avaliacao),
                'acertos' => $resumo->acertos,
                'total' => $resumo->total,
                'percentual' => $resumo->percentual !== null ? (float) $resumo->percentual : null,
            ])
            ->values()
            ->all();
    }

    /**
     * "Período letivo" (ex.: "2026/2") é diferente de "período" (`periodo`
     * em respostas/resultado_metricas/resultado_resumos, ex.: "5º") — este
     * último é o período do CURSO do aluno, não o semestre letivo. Nenhuma
     * tabela guarda o período letivo de uma avaliação diretamente (só
     * `alunos.periodo_letivo`, que é um valor único por aluno, sempre o mais
     * recente da matrícula — não dá pra usar pra classificar avaliações
     * antigas), então derivamos do semestre da data da avaliação:
     * jan–jun = "/1", jul–dez = "/2". Sem data cadastrada, não dá pra
     * classificar — some do filtro (mas continua aparecendo em "Todos").
     */
    private function periodoLetivo(?Carbon $dataAvaliacao): string
    {
        if ($dataAvaliacao === null) {
            return '';
        }

        $semestre = $dataAvaliacao->month <= 6 ? 1 : 2;

        return "{$dataAvaliacao->year}/{$semestre}";
    }

    /**
     * Busca o resultado de uma única avaliação/período, verificando que ela
     * realmente pertence ao aluno informado — usado pela tela de detalhe que
     * abre em nova aba, então a "posse" do resultado precisa ser conferida
     * aqui (nunca confiar que o código da avaliação na URL já é do aluno certo).
     *
     * @return array{avaliação: Avaliação, periodo: string, respostas: Collection, gabaritos: Collection, acertos: int, total: int, percentual: ?float, metricas: Collection}|null
     */
    public function buscarUmaAvaliacao(Aluno $aluno, int $avaliacaoCodigo, string $periodo): ?array
    {
        $avaliacao = Avaliacao::with('categoria')->find($avaliacaoCodigo);
        if ($avaliacao === null) {
            return null;
        }

        $respostas = Resposta::where('avaliacao_codigo', $avaliacaoCodigo)
            ->where('periodo', $periodo)
            ->where(fn ($q) => $this->porAluno($q, $aluno))
            ->orderBy('questao_numero')
            ->get();

        if ($respostas->isEmpty()) {
            return null;
        }

        return $this->montarResultado($aluno, $avaliacao, $periodo, $respostas);
    }

    /** @return array{avaliacao: Avaliacao, periodo: string, respostas: Collection, gabaritos: Collection, anuladas: Collection, questoesMeta: Collection, acertos: int, total: int, percentual: ?float, metricas: Collection} */
    private function montarResultado(Aluno $aluno, Avaliacao $avaliacao, string $periodo, Collection $respostas): array
    {
        // 'anuladas' guarda o anulada_modo de TODA questão anulada (mesmo as
        // distribuir_pontuacao) — usado pela view só pra marcar o "*" no
        // detalhamento das respostas. 'gabaritos' já sai sem as
        // distribuir_pontuacao: elas não contam mais na prova, então o
        // detalhamento trata a questão como sem gabarito (cinza), igual já
        // acontece hoje quando a questão não tem gabarito cadastrado.
        // 'questoesMeta' (área/tema por número) alimenta o popup de detalhe
        // da questão no "Detalhamento das respostas".
        $questoesComGabarito = $avaliacao->questoes()->whereNotNull('gabarito')->where('gabarito', '!=', '')->get(['numero', 'gabarito', 'area', 'tema', 'anulada_modo']);
        $anuladas = $questoesComGabarito->pluck('anulada_modo', 'numero')->filter();
        $questoesMeta = $questoesComGabarito->keyBy('numero')->map(fn ($q) => ['area' => $q->area, 'tema' => $q->tema]);
        $gabaritos = $questoesComGabarito
            ->filter(fn ($q) => ! Anulacao::distribuida($q->anulada_modo))
            ->pluck('gabarito', 'numero');

        // Acertos/total vêm do resumo pré-calculado (mesma fonte usada no
        // boletim, pra nunca mostrar um número diferente na tela de detalhe).
        // Fallback calculado na hora só existe pra não quebrar se por algum
        // motivo o resumo ainda não tiver sido gerado para esta avaliação.
        $resumo = ResultadoResumo::where('avaliacao_codigo', $avaliacao->codigo)
            ->where('periodo', $periodo)
            ->where(fn ($q) => $this->porAluno($q, $aluno))
            ->first();

        if ($resumo !== null) {
            $acertos = $resumo->acertos;
            $total = $resumo->total;
            $percentual = $resumo->percentual !== null ? (float) $resumo->percentual : null;
        } else {
            $acertos = $respostas->filter(
                fn ($r) => $gabaritos->has($r->questao_numero)
                    && Anulacao::acertou($r->resposta, $gabaritos[$r->questao_numero], $anuladas->get($r->questao_numero))
            )->count();
            $total = $gabaritos->count();
            $percentual = $total > 0 ? round($acertos / $total * 100, 1) : null;
        }

        $metricas = ResultadoMetrica::where('avaliacao_codigo', $avaliacao->codigo)
            ->where('periodo', $periodo)
            ->where(fn ($q) => $this->porAluno($q, $aluno))
            ->get();

        return [
            'avaliacao' => $avaliacao,
            'periodo' => $periodo,
            'respostas' => $respostas,
            'gabaritos' => $gabaritos,
            'anuladas' => $anuladas,
            'questoesMeta' => $questoesMeta,
            'acertos' => $acertos,
            'total' => $total,
            'percentual' => $percentual,
            'metricas' => $metricas,
        ];
    }

    /**
     * Agrupa os resultados de buscarPorAluno() na árvore de categorias
     * (categoria → subcategorias → avaliações), para o boletim mostrar "clique
     * na categoria para ver as avaliações dentro dela, por data" em vez de uma
     * lista plana. Só entram na árvore as categorias que o aluno realmente
     * tem resultado (direto ou em alguma subcategoria) — o resto da
     * taxonomia cadastrada pelo admin fica de fora.
     *
     * @param  array<int, array<string, mixed>>  $resultados
     * @return array{arvore: array, semCategoria: array}
     */
    public function montarArvore(array $resultados): array
    {
        $todasCategorias = Categoria::all()->keyBy('id');
        $porCategoria = collect($resultados)->groupBy(fn ($r) => $r['avaliacao']->categoria_id);

        $semCategoria = ($porCategoria->get(null) ?? collect())
            ->sortByDesc(fn ($r) => $this->chaveOrdenacao($r))
            ->values()->all();

        $idsRelevantes = [];
        foreach ($porCategoria->keys()->filter() as $id) {
            $atual = $todasCategorias->get($id);
            while ($atual !== null) {
                $idsRelevantes[$atual->id] = true;
                $atual = $atual->categoria_pai_id ? $todasCategorias->get($atual->categoria_pai_id) : null;
            }
        }

        $construirNo = function (int $categoriaId) use (&$construirNo, $todasCategorias, $idsRelevantes, $porCategoria): array {
            $filhos = $todasCategorias
                ->filter(fn ($c) => $c->categoria_pai_id === $categoriaId && isset($idsRelevantes[$c->id]))
                ->sortBy('nome');

            return [
                'categoria' => $todasCategorias->get($categoriaId),
                'resultados' => ($porCategoria->get($categoriaId) ?? collect())
                    ->sortByDesc(fn ($r) => $this->chaveOrdenacao($r))->values()->all(),
                'subcategorias' => $filhos->map(fn ($c) => $construirNo($c->id))->values()->all(),
            ];
        };

        $raizes = $todasCategorias
            ->filter(fn ($c) => $c->categoria_pai_id === null && isset($idsRelevantes[$c->id]))
            ->sortBy('nome');

        return [
            'arvore' => $raizes->map(fn ($c) => $construirNo($c->id))->values()->all(),
            'semCategoria' => $semCategoria,
        ];
    }

    /** Chave de ordenação: data da avaliacao (quando cadastrada) ou o código dela, mais recente primeiro. */
    private function chaveOrdenacao(array $resultado): string
    {
        $data = $resultado['avaliacao']->data_avaliacao?->format('Y-m-d') ?? '0000-00-00';

        return sprintf('%s-%010d', $data, $resultado['avaliacao']->codigo);
    }

    /**
     * Série cronológica (mais antiga primeiro) de percentuais, pro gráfico de
     * evolução no topo do boletim — só entram avaliações com data e
     * percentual cadastrados, senão a ordem/posição no eixo X não tem sentido.
     *
     * @param  array<int, array<string, mixed>>  $resultados
     * @return array<int, array{nome: string, percentual: float, data: string}>
     */
    public function evolucaoGeral(array $resultados): array
    {
        return collect($resultados)
            ->filter(fn ($r) => $r['percentual'] !== null && $r['avaliacao']->data_avaliacao !== null)
            ->sortBy(fn ($r) => $r['avaliacao']->data_avaliacao->format('Y-m-d'))
            ->map(fn ($r) => [
                'nome' => $r['avaliacao']->nome ?? "Avaliação #{$r['avaliacao']->codigo}",
                'percentual' => $r['percentual'],
                'data' => $r['avaliacao']->data_avaliacao->format('d/m/Y'),
            ])
            ->values()
            ->all();
    }

    /**
     * Média de desempenho por categoria de topo (raiz da árvore de
     * montarArvore()), somando recursivamente os resultados de todas as
     * subcategorias — usado no resumo visual do boletim.
     *
     * @param  array  $arvore  saída de montarArvore()['arvore']
     * @return array<int, array{nome: string, media: float, quantidade: int}>
     */
    public function resumoPorCategoria(array $arvore): array
    {
        $coletarPercentuais = function (array $no) use (&$coletarPercentuais): array {
            $percentuais = collect($no['resultados'])->pluck('percentual')->filter(fn ($p) => $p !== null)->all();

            foreach ($no['subcategorias'] as $sub) {
                $percentuais = [...$percentuais, ...$coletarPercentuais($sub)];
            }

            return $percentuais;
        };

        $resumo = [];
        foreach ($arvore as $no) {
            $percentuais = $coletarPercentuais($no);
            if (empty($percentuais)) {
                continue;
            }

            $resumo[] = [
                'nome' => $no['categoria']->nome,
                'media' => round(array_sum($percentuais) / count($percentuais), 1),
                'quantidade' => count($percentuais),
            ];
        }

        usort($resumo, fn ($a, $b) => $b['media'] <=> $a['media']);

        return $resumo;
    }

    private function porAluno($query, Aluno $aluno): void
    {
        $query->where('ra', $aluno->ra);

        if ($aluno->cpf) {
            $query->orWhere('cpf', $aluno->cpf);
        }
    }
}
