<?php

namespace App\Services;

use App\Models\Avaliacao;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Como esta prova foi comparada com outra(s)?" — o painel BI é sempre sobre
 * UMA avaliação; este serviço é o único ponto que olha para várias ao mesmo
 * tempo lado a lado.
 *
 * Sempre compara a PROVA INTEIRA (sem o filtro de período/turma/demografia
 * da tela): período de uma avaliação não tem relação com período de outra, e
 * misturar "comparação entre provas" com "filtro dentro de uma prova" deixa
 * o número ambíguo sobre o que está sendo comparado.
 *
 * Limitado a MAX_COMPARACOES avaliações comparadas, além da avaliação base —
 * não é escolha arbitrária: a paleta categórica validada (ver
 * _viz.blade.php) só tem 3 tons e cada avaliação comparada precisa manter a
 * MESMA cor em todo gráfico do painel (base = serie1, sempre).
 */
class ComparacaoAvaliacoesService
{
    /** @see class doc — número de avaliações comparadas além da base, travado pela paleta de 3 tons. */
    public const MAX_COMPARACOES = 2;

    public function __construct(
        private readonly PsicometriaService $psicometriaService,
        private readonly RelatorioAdminService $relatorioService,
    ) {}

    /**
     * Avaliações elegíveis para comparar com $atual: qualquer outra (de
     * qualquer categoria) que já tenha resultado importado.
     *
     * @return Collection<int, array{codigo: int, nome: string, data: ?string}>
     */
    public function opcoesDisponiveis(Avaliacao $atual): Collection
    {
        return DB::table('avaliacoes as av')
            ->join('resultado_resumos as rr', 'rr.avaliacao_codigo', '=', 'av.codigo')
            ->where('av.codigo', '!=', $atual->codigo)
            ->whereNull('av.deleted_at')
            ->groupBy('av.codigo', 'av.nome', 'av.data_avaliacao')
            ->selectRaw('av.codigo as codigo, av.nome as nome, av.data_avaliacao as data')
            ->orderByDesc('av.data_avaliacao')
            ->get()
            ->map(fn ($l) => [
                'codigo' => (int) $l->codigo,
                'nome' => $l->nome ?? "Avaliação #{$l->codigo}",
                'data' => $l->data,
            ]);
    }

    /**
     * @param  array<int, int>  $codigosComparar  já vem de fora sem garantia de tamanho/validade
     * @return array{
     *   avaliacoes: array<int, array{codigo: int, nome: string, data: ?string, resumo: ?array, mediaPorArea: array<string, float>}>,
     *   areas: array<int, string>
     * }|null
     */
    public function comparar(Avaliacao $base, array $codigosComparar): ?array
    {
        // A base nunca é uma das comparadas (ainda que o usuário mande o
        // próprio código no seletor) e o limite de tons decide quantas
        // sobrevivem — as excedentes são descartadas, não é erro.
        $codigos = array_slice(
            array_values(array_unique(array_filter(
                array_map('intval', $codigosComparar),
                fn (int $codigo) => $codigo !== $base->codigo,
            ))),
            0,
            self::MAX_COMPARACOES,
        );

        if ($codigos === []) {
            return null;
        }

        $comparadas = Avaliacao::whereIn('codigo', $codigos)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('codigo');

        // Preserva a ordem escolhida no seletor (não a ordem do banco) —
        // é essa ordem que define qual cor (serie2, serie3...) cada uma leva.
        $ordenadas = collect($codigos)
            ->map(fn (int $codigo) => $comparadas->get($codigo))
            ->filter()
            ->values();

        if ($ordenadas->isEmpty()) {
            return null;
        }

        $avaliacoes = collect([$base])->concat($ordenadas)
            ->map(fn (Avaliacao $avaliacao) => $this->resumoDe($avaliacao))
            ->values()
            ->all();

        $areas = collect($avaliacoes)
            ->flatMap(fn ($a) => array_keys($a['mediaPorArea']))
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'avaliacoes' => $avaliacoes,
            'areas' => $areas,
        ];
    }

    /** @return array{codigo: int, nome: string, data: ?string, resumo: ?array, mediaPorArea: array<string, float>} */
    private function resumoDe(Avaliacao $avaliacao): array
    {
        $psicometria = $this->psicometriaService->analisar($avaliacao);

        return [
            'codigo' => $avaliacao->codigo,
            'nome' => $avaliacao->nome ?? "Avaliação #{$avaliacao->codigo}",
            'data' => $avaliacao->data_avaliacao?->format('Y-m-d'),
            'resumo' => $psicometria === null ? null : [
                'respondentes' => $psicometria['respondentes'],
                'media' => $psicometria['media'],
                'mediana' => $psicometria['mediana'],
                'desvio' => $psicometria['desvio'],
                'kr20' => $psicometria['kr20'],
            ],
            'mediaPorArea' => $this->relatorioService->mediaPorArea($avaliacao),
        ];
    }
}
