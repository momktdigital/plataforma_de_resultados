<?php

namespace App\Services;

use App\Models\Admin;
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
     * Avaliações elegíveis para comparar com $atual: outras da MESMA categoria
     * que já tenham resultado importado. Comparar avaliações de categorias
     * diferentes (provas distintas, dificuldade e escala diferentes) não diz
     * nada — sem categoria não há com quem comparar.
     *
     * @return Collection<int, array{codigo: int, nome: string, data: ?string}>
     */
    public function opcoesDisponiveis(Avaliacao $atual, ?Admin $usuario = null): Collection
    {
        // Coordenador só compara com avaliações que ele mesmo pode ver.
        $permitidas = $usuario?->ehCoordenador() ? Avaliacao::visivelPara($usuario)->pluck('codigo')->all() : null;

        if ($atual->categoria_id === null) {
            return collect();
        }

        return DB::table('avaliacoes as av')
            ->join('resultado_resumos as rr', 'rr.avaliacao_codigo', '=', 'av.codigo')
            ->where('av.codigo', '!=', $atual->codigo)
            ->where('av.categoria_id', $atual->categoria_id)
            ->whereNull('av.deleted_at')
            ->when($permitidas !== null, fn ($q) => $q->whereIn('av.codigo', $permitidas))
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
    public function comparar(Avaliacao $base, array $codigosComparar, ?Admin $usuario = null): ?array
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

        if ($codigos === [] || $base->categoria_id === null) {
            return null;
        }

        $comparadas = Avaliacao::whereIn('codigo', $codigos)
            ->where('categoria_id', $base->categoria_id)
            ->when($usuario !== null, fn ($q) => $q->visivelPara($usuario))
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
            ->map(fn (Avaliacao $avaliacao) => $this->resumoDe($avaliacao, $usuario?->ehCoordenador() ? $usuario->cursos() : null))
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
    private function resumoDe(Avaliacao $avaliacao, ?array $cursos = null): array
    {
        $psicometriaService = $this->psicometriaService->paraCursos($cursos);
        $relatorioService = $this->relatorioService->paraCursos($cursos);
        $psicometria = $psicometriaService->analisar($avaliacao);

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
            'mediaPorArea' => $relatorioService->mediaPorArea($avaliacao),
        ];
    }
}
