<?php

namespace App\Services\Portal;

use App\Models\Aluno;
use App\Models\Categoria;
use App\Models\Prova;
use App\Models\Resposta;
use App\Models\ResultadoMetrica;
use App\Models\ResultadoResumo;
use Illuminate\Support\Collection;

/**
 * Busca os resultados de um aluno no schema novo (respostas/
 * resultado_metricas/questoes) para exibição no portal público —
 * equivalente à consulta por RA em api/consulta.php, mas contra
 * `provas`/`questoes` em vez do JSON de `resultados`/`gabaritos`.
 */
class ResultadoConsultaService
{
    /**
     * Boletim (lista de cards) — só precisa de acertos/total/percentual por
     * prova, então lê de `resultado_resumos` (App\Services\ResumoResultadoService)
     * em vez de escanear `respostas`, que cresce um por aluno×prova×questão.
     *
     * @return array<int, array{prova: Prova, periodo: string, acertos: int, total: int, percentual: ?float}>
     */
    public function buscarPorAluno(Aluno $aluno): array
    {
        return ResultadoResumo::with('prova.categoria')
            ->where(fn ($q) => $this->porAluno($q, $aluno))
            ->get()
            ->filter(fn (ResultadoResumo $resumo) => $resumo->prova !== null)
            ->map(fn (ResultadoResumo $resumo) => [
                'prova' => $resumo->prova,
                'periodo' => $resumo->periodo,
                'acertos' => $resumo->acertos,
                'total' => $resumo->total,
                'percentual' => $resumo->percentual !== null ? (float) $resumo->percentual : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Busca o resultado de uma única prova/período, verificando que ela
     * realmente pertence ao aluno informado — usado pela tela de detalhe que
     * abre em nova aba, então a "posse" do resultado precisa ser conferida
     * aqui (nunca confiar que o código da prova na URL já é do aluno certo).
     *
     * @return array{prova: Prova, periodo: string, respostas: Collection, gabaritos: Collection, acertos: int, total: int, percentual: ?float, metricas: Collection}|null
     */
    public function buscarUmaProva(Aluno $aluno, int $provaCodigo, string $periodo): ?array
    {
        $prova = Prova::with('categoria')->find($provaCodigo);
        if ($prova === null) {
            return null;
        }

        $respostas = Resposta::where('prova_codigo', $provaCodigo)
            ->where('periodo', $periodo)
            ->where(fn ($q) => $this->porAluno($q, $aluno))
            ->orderBy('questao_numero')
            ->get();

        if ($respostas->isEmpty()) {
            return null;
        }

        return $this->montarResultado($aluno, $prova, $periodo, $respostas);
    }

    /** @return array{prova: Prova, periodo: string, respostas: Collection, gabaritos: Collection, acertos: int, total: int, percentual: ?float, metricas: Collection} */
    private function montarResultado(Aluno $aluno, Prova $prova, string $periodo, Collection $respostas): array
    {
        $gabaritos = $prova->questoes()->whereNotNull('gabarito')->where('gabarito', '!=', '')->pluck('gabarito', 'numero');

        // Acertos/total vêm do resumo pré-calculado (mesma fonte usada no
        // boletim, pra nunca mostrar um número diferente na tela de detalhe).
        // Fallback calculado na hora só existe pra não quebrar se por algum
        // motivo o resumo ainda não tiver sido gerado para esta prova.
        $resumo = ResultadoResumo::where('prova_codigo', $prova->codigo)
            ->where('periodo', $periodo)
            ->where(fn ($q) => $this->porAluno($q, $aluno))
            ->first();

        if ($resumo !== null) {
            $acertos = $resumo->acertos;
            $total = $resumo->total;
            $percentual = $resumo->percentual !== null ? (float) $resumo->percentual : null;
        } else {
            $acertos = $respostas->filter(
                fn ($r) => $gabaritos->has($r->questao_numero) && $r->resposta === $gabaritos[$r->questao_numero]
            )->count();
            $total = $gabaritos->count();
            $percentual = $total > 0 ? round($acertos / $total * 100, 1) : null;
        }

        $metricas = ResultadoMetrica::where('prova_codigo', $prova->codigo)
            ->where('periodo', $periodo)
            ->where(fn ($q) => $this->porAluno($q, $aluno))
            ->get();

        return [
            'prova' => $prova,
            'periodo' => $periodo,
            'respostas' => $respostas,
            'gabaritos' => $gabaritos,
            'acertos' => $acertos,
            'total' => $total,
            'percentual' => $percentual,
            'metricas' => $metricas,
        ];
    }

    /**
     * Agrupa os resultados de buscarPorAluno() na árvore de categorias
     * (categoria → subcategorias → provas), para o boletim mostrar "clique
     * na categoria para ver as provas dentro dela, por data" em vez de uma
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
        $porCategoria = collect($resultados)->groupBy(fn ($r) => $r['prova']->categoria_id);

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

    /** Chave de ordenação: data da prova (quando cadastrada) ou o código dela, mais recente primeiro. */
    private function chaveOrdenacao(array $resultado): string
    {
        $data = $resultado['prova']->data_prova?->format('Y-m-d') ?? '0000-00-00';

        return sprintf('%s-%010d', $data, $resultado['prova']->codigo);
    }

    private function porAluno($query, Aluno $aluno): void
    {
        $query->where('ra', $aluno->ra);

        if ($aluno->cpf) {
            $query->orWhere('cpf', $aluno->cpf);
        }
    }
}
