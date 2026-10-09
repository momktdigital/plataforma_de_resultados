<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\Resposta;
use App\Support\Anulacao;
use App\Support\CacheDeAnalise;
use App\Support\NomeCurso;
use App\Support\PeriodoCurso;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Painel do coordenador: o equivalente, para o CURSO, do boletim do aluno
 * (PortalController::resultados) — desempenho dos alunos do(s) curso(s) do
 * coordenador, filtrado por período letivo, com insights em texto.
 *
 * TUDO É ANALISADO POR CATEGORIA DE AVALIAÇÃO, nunca misturando categorias:
 * avaliações de categorias diferentes são provas diferentes (conteúdo,
 * dificuldade, escala), então médias, comparações com a avaliação anterior,
 * evolução, desempenho por área e por período do curso só fazem sentido
 * dentro de uma mesma categoria. A "avaliação anterior" é a anterior DA MESMA
 * CATEGORIA, mesmo que de outro período letivo — igual ao boletim do aluno
 * (ver Portal\InsightService). Os únicos números globais são contagem de
 * avaliações e presença, que não dependem da prova.
 *
 * Mesma regra de escala do resto do sistema (ver CLAUDE.md): tudo é agregado
 * em SQL sobre `resultado_resumos` (uma linha por aluno×avaliação×período —
 * pequena) e, só onde é inevitável (presença e desempenho por área), sobre
 * `respostas`, sempre restrita aos alunos do curso e às avaliações em jogo.
 *
 * AUSENTES: aluno cuja prova inteira ficou em branco (mesma definição de
 * PsicometriaService::presenca()). Ficam FORA das médias, dos "abaixo de 60%"
 * e do desempenho por área — contam só em "presença". Sem isso, um curso com
 * 20% de faltantes pareceria 20% pior do que realmente foi.
 *
 * O curso de cada resultado é `resultado_resumos.curso` — o curso em que o
 * aluno estava QUANDO fez a prova (CursoDoResultadoService), não o curso
 * atual dele: um aluno transferido continua nos números do curso antigo nas
 * provas de antes da transferência. Resultado sem curso conhecido (aluno que
 * não está na matrícula) não entra aqui.
 */
class CoordenadorDashboardService
{
    /** Mesmo corte de cor do portal do aluno (App\Support\CorDesempenho). */
    public const LIMIAR_ADEQUADO = 60.0;

    /** Variação (pontos percentuais) entre avaliações pra virar insight — abaixo disso é ruído. */
    private const LIMIAR_VARIACAO = 3.0;

    /** Fração de alunos abaixo de 60% que já merece alerta. */
    private const LIMIAR_ALERTA_ABAIXO = 0.4;

    /** Áreas com menos respostas que isto não entram no ranking (amostra pequena demais). */
    private const MINIMO_RESPOSTAS_AREA = 30;

    /** Respostas mínimas numa célula (item × período do curso) para ela aparecer na aba "por período". */
    private const MINIMO_RESPOSTAS_CELULA = 10;

    /** Mínimo esperado (% de acerto) de quem não tem período reconhecível — o mesmo padrão do boletim do aluno. */
    public const MINIMO_PADRAO = 60.0;

    /** Períodos do curso com menos presentes que isto não entram no insight de melhor/pior. */
    private const MINIMO_PRESENTES_PERIODO = 10;

    /**
     * Onde o coordenador está olhando: cursos em foco, a lista de avaliações desses cursos (com período letivo e
     * categoria) e o semestre escolhido. Compartilhado por todas as telas do painel (visão geral, alunos,
     * desempenho, ficha do aluno), para que todas concordem sobre o recorte.
     *
     * Sem `semCurso`/`semResultados`, traz também: `variantes` (grafias do curso), `avaliacoes` (todas, de todos os
     * períodos), `doPeriodo` (as do semestre escolhido), `periodosDisponiveis` e `periodoSelecionado`.
     *
     * @return array<string, mixed>
     */
    public function escopo(Admin $coordenador, string $cursoSelecionado = '', ?string $periodoLetivo = null): array
    {
        $meusCursos = $coordenador->cursos();
        $cursos = $meusCursos;
        foreach ($meusCursos as $curso) {
            if ($cursoSelecionado !== '' && NomeCurso::mesmo($curso, $cursoSelecionado)) {
                $cursos = [$curso];
                break;
            }
        }

        $base = [
            'meusCursos' => $meusCursos,
            'cursosEmFoco' => $cursos,
            'cursoSelecionado' => count($cursos) === 1 && count($meusCursos) > 1 ? $cursos[0] : '',
        ];

        if ($cursos === []) {
            return [...$base, 'semCurso' => true];
        }

        $variantes = NomeCurso::variantes($cursos);
        $avaliacoes = $this->avaliacoesDoCurso($variantes);

        if ($avaliacoes->isEmpty()) {
            return [...$base, 'variantes' => $variantes, 'semResultados' => true];
        }

        $periodos = $avaliacoes->pluck('periodoLetivo')->filter()->unique()->sortDesc()->values()->all();
        $periodoSelecionado = $periodoLetivo ?? ($periodos[0] ?? '');
        if ($periodoSelecionado !== '' && ! in_array($periodoSelecionado, $periodos, true)) {
            $periodoSelecionado = $periodos[0] ?? '';
        }

        return [
            ...$base,
            'variantes' => $variantes,
            'avaliacoes' => $avaliacoes,
            'periodosDisponiveis' => $periodos,
            'periodoSelecionado' => $periodoSelecionado,
            'doPeriodo' => $periodoSelecionado === ''
                ? $avaliacoes
                : $avaliacoes->filter(fn ($a) => $a['periodoLetivo'] === $periodoSelecionado)->values(),
        ];
    }

    /**
     * @param  ?array<string, mixed>  $escopo  saída já calculada de escopo() (evita repetir as consultas)
     * @param  array{detalhado?: bool, categoria?: string, periodo_curso?: int|string|null, estrito?: bool, campos?: bool}  $opcoes  só a tela de desempenho usa:
     *                                                                                                  `categoria` ('' = todas, '0' = sem categoria, ou o id) e `periodo_curso` (ordinal) filtram o painel;
     *                                                                                                  `detalhado` liga o que é mais caro (alunos dentro do esperado, Bloom, tema e as abas por período)
     * @return array<string, mixed>
     */
    public function gerar(Admin $coordenador, string $cursoSelecionado = '', ?string $periodoLetivo = null, ?array $escopo = null, array $opcoes = []): array
    {
        $escopo ??= $this->escopo($coordenador, $cursoSelecionado, $periodoLetivo);

        if (! empty($escopo['semCurso']) || ! empty($escopo['semResultados'])) {
            return array_diff_key($escopo, ['variantes' => 1]);
        }

        $cursos = $escopo['cursosEmFoco'];
        $variantes = $escopo['variantes'];
        $avaliacoes = $escopo['avaliacoes'];
        $periodos = $escopo['periodosDisponiveis'];
        $periodoSelecionado = $escopo['periodoSelecionado'];
        $doPeriodo = $escopo['doPeriodo'];
        $base = array_intersect_key($escopo, array_flip(['meusCursos', 'cursosEmFoco', 'cursoSelecionado']));

        $detalhado = (bool) ($opcoes['detalhado'] ?? false);
        // estrito: um filtro que não existe neste período letivo NÃO é ignorado (a comparação entre semestres precisa do vazio,
        // senão o semestre sem aquela categoria/período do curso mostraria tudo).
        $estrito = (bool) ($opcoes['estrito'] ?? false);
        $filtroCategoria = (string) ($opcoes['categoria'] ?? '');
        $filtroPeriodoCurso = isset($opcoes['periodo_curso']) && $opcoes['periodo_curso'] !== '' ? (int) $opcoes['periodo_curso'] : null;
        $nomesCategoria = $this->nomesDeCategoria();

        // Categorias que existem no período letivo (antes de filtrar), para o seletor da tela.
        $categoriasDisponiveis = $doPeriodo
            ->map(fn ($a) => (int) ($a['categoriaId'] ?? 0))
            ->unique()
            ->map(fn ($id) => ['id' => $id, 'nome' => $nomesCategoria[$id] ?? 'Sem categoria'])
            ->sortBy(fn ($c) => $c['id'] === 0 ? 'zzz' : mb_strtolower($c['nome']))
            ->values()
            ->all();
        // Categoria que não existe no período letivo escolhido é ignorada (em vez de esvaziar a tela).
        if (! $estrito && $filtroCategoria !== '' && ! in_array((int) $filtroCategoria, array_column($categoriasDisponiveis, 'id'), true)) {
            $filtroCategoria = '';
        }
        if ($filtroCategoria !== '') {
            $doPeriodo = $doPeriodo->filter(fn ($a) => (int) ($a['categoriaId'] ?? 0) === (int) $filtroCategoria)->values();
        }

        // Histórico das categorias presentes no período: inclui períodos
        // anteriores, que é de onde vem a "avaliação anterior" de cada uma.
        $categoriasNoPeriodo = $doPeriodo->pluck('categoriaId')->filter()->unique()->all();
        $historico = $avaliacoes->filter(fn ($a) => in_array($a['categoriaId'], $categoriasNoPeriodo, true))->values();

        $codigosPeriodo = $doPeriodo->pluck('codigo')->all();
        $linhas = $this->estatisticas($variantes, $cursos, $doPeriodo->pluck('codigo')->merge($historico->pluck('codigo'))->unique()->values()->all());

        // Períodos do curso (1º, 2º...) que têm resultado nas avaliações do recorte — antes de filtrar, para o seletor.
        $periodosCursoDisponiveis = $linhas
            ->filter(fn ($l) => in_array($l->codigo, $codigosPeriodo, true))
            ->map(fn ($l) => PeriodoCurso::ordinal((string) $l->periodo))
            ->filter()->unique()->sort()->values()->all();
        if (! $estrito && $filtroPeriodoCurso !== null && ! in_array($filtroPeriodoCurso, $periodosCursoDisponiveis, true)) {
            $filtroPeriodoCurso = null;
        }
        if ($filtroPeriodoCurso !== null) {
            $linhas = $linhas->filter(fn ($l) => PeriodoCurso::ordinal((string) $l->periodo) === $filtroPeriodoCurso)->values();
        }

        $blocos = $doPeriodo
            ->groupBy(fn ($a) => $a['categoriaId'] ?? 0)
            ->map(fn (Collection $grupo, $categoriaId) => $this->blocoDaCategoria(
                (int) $categoriaId ?: null,
                $nomesCategoria[(int) $categoriaId] ?? 'Sem categoria',
                $grupo,
                $historico,
                $linhas,
                $variantes,
                $cursos,
                ['detalhado' => $detalhado, 'periodo_curso' => $filtroPeriodoCurso, 'campos' => (bool) ($opcoes['campos'] ?? true)],
            ))
            ->sortBy(fn ($b) => $b['id'] === null ? 'zzz' : mb_strtolower($b['nome']))
            ->values()
            ->all();

        $geral = $this->somar($linhas->whereIn('codigo', $codigosPeriodo));

        return [
            ...$base,
            'periodosDisponiveis' => $periodos,
            'periodoSelecionado' => $periodoSelecionado,
            'geral' => $geral,
            'categorias' => $blocos,
            'categoriasDisponiveis' => $categoriasDisponiveis,
            'periodosCursoDisponiveis' => $periodosCursoDisponiveis,
            'filtros' => ['categoria' => $filtroCategoria, 'periodoCurso' => $filtroPeriodoCurso],
            'insights' => $this->insightsGerais($geral),
        ];
    }

    /**
     * Tudo que depende da prova, restrito a UMA categoria.
     *
     * @param  Collection<int, array<string, mixed>>  $doPeriodo  avaliações da categoria no período selecionado
     * @param  Collection<int, array<string, mixed>>  $historico  avaliações de TODAS as categorias do período, em todos os períodos
     * @return array<string, mixed>
     */
    private function blocoDaCategoria(?int $categoriaId, string $nome, Collection $doPeriodo, Collection $historico, Collection $linhas, array $variantes, array $cursos, array $opcoes = []): array
    {
        $codigos = $doPeriodo->pluck('codigo')->all();
        $detalhado = (bool) ($opcoes['detalhado'] ?? false);
        $filtroPeriodoCurso = $opcoes['periodo_curso'] ?? null;

        // Avaliações da categoria, de qualquer período, da mais antiga pra mais nova.
        $daCategoria = $categoriaId === null
            ? collect()
            : $historico->filter(fn ($a) => $a['categoriaId'] === $categoriaId)
                ->sortBy(fn ($a) => [$a['data'] ?? '9999-12-31', $a['codigo']])
                ->values();

        $resumos = $daCategoria->mapWithKeys(fn ($a) => [$a['codigo'] => $this->resumirAvaliacao($a, $linhas->where('codigo', $a['codigo']))]);

        // Alunos dentro do esperado (só no modo detalhado): por avaliação e período do curso, já com o filtro de período.
        $esperado = $detalhado
            ? $this->dentroDoEsperadoPorPeriodo($variantes, $daCategoria->pluck('codigo')->merge($codigos)->unique()->values()->all())
            : [];
        $agregar = function (int $codigo, ?int $soOrdinal = null) use ($esperado, $filtroPeriodoCurso): array {
            $soma = ['presentes' => 0, 'dentro' => 0];
            foreach (($esperado[$codigo]['periodos'] ?? []) as $ordinal => $p) {
                if ($soOrdinal !== null && $ordinal !== $soOrdinal) {
                    continue;
                }
                if ($filtroPeriodoCurso !== null && $ordinal !== $filtroPeriodoCurso) {
                    continue;
                }
                $soma['presentes'] += $p['presentes'];
                $soma['dentro'] += $p['dentro'];
            }

            return $soma;
        };

        $avaliacoes = $doPeriodo
            ->sortBy(fn ($a) => [$a['data'] ?? '9999-12-31', $a['codigo']])
            ->values()
            ->map(function ($a) use ($linhas, $daCategoria, $resumos, $detalhado, $agregar, $esperado) {
                $linha = $this->resumirAvaliacao($a, $linhas->where('codigo', $a['codigo']));
                $ag = $detalhado ? $agregar($a['codigo']) : null;

                // Anterior = a avaliação imediatamente anterior DA MESMA CATEGORIA
                // (qualquer período letivo). Sem data não há "anterior".
                $anterior = $a['data'] === null ? null : $daCategoria
                    ->filter(fn ($o) => $o['data'] !== null && [$o['data'], $o['codigo']] < [$a['data'], $a['codigo']])
                    ->last();
                $mediaAnterior = $anterior ? ($resumos[$anterior['codigo']]['media'] ?? null) : null;

                return [
                    ...$linha,
                    'anterior' => $anterior ? ['codigo' => $anterior['codigo'], 'nome' => $anterior['nome'], 'periodoLetivo' => $anterior['periodoLetivo'], 'media' => $mediaAnterior] : null,
                    'delta' => $linha['media'] !== null && $mediaAnterior !== null ? round($linha['media'] - $mediaAnterior, 1) : null,
                    // Só no modo detalhado: quantos ficaram abaixo do esperado (ou abaixo de 60% se a prova não traz a meta).
                    'esperado' => $ag === null ? null : [
                        'comMeta' => $esperado[$a['codigo']]['comMeta'] ?? false,
                        'presentes' => $ag['presentes'],
                        'dentro' => $ag['dentro'],
                        'abaixo' => $ag['presentes'] - $ag['dentro'],
                        'abaixoPct' => $ag['presentes'] > 0 ? round(($ag['presentes'] - $ag['dentro']) / $ag['presentes'] * 100, 1) : null,
                    ],
                ];
            })
            ->filter(fn ($a) => $a['inscritos'] > 0)
            ->values();

        $linhasDaCategoria = $linhas->whereIn('codigo', $codigos);
        $totais = $this->somar($linhasDaCategoria);
        [$porPeriodoDoCurso, $periodosOmitidos] = $this->porPeriodoDoCurso($linhasDaCategoria);
        // Comparar cursos só faz sentido com pelo menos dois com resultado nesta categoria.
        $porCurso = count($cursos) > 1 ? $this->porCurso($linhasDaCategoria, $cursos) : [];
        if (count($porCurso) < 2) {
            $porCurso = [];
        }
        // Modo detalhado: área, Bloom e tema (no geral e por período do curso), já com o filtro de período.
        $campos = $detalhado && ($opcoes['campos'] ?? true) ? $this->desempenhoPorCampos($variantes, $codigos, $filtroPeriodoCurso) : null;
        $porArea = $campos !== null
            ? array_map(fn ($g) => ['area' => $g['rotulo'], 'percentual' => $g['percentual'], 'respostas' => $g['respostas']], $campos['area']['geral'])
            : $this->desempenhoPorArea($variantes, $cursos, $codigos);

        $evolucao = $resumos->filter(fn ($r) => $r['media'] !== null)->map(fn ($r) => [
            'codigo' => $r['codigo'],
            'nome' => $r['nome'],
            'data' => $r['data'],
            'periodoLetivo' => $r['periodoLetivo'],
            'media' => $r['media'],
            'noPeriodo' => in_array($r['codigo'], $codigos, true),
        ])->values()->all();

        $detalhe = null;
        if ($detalhado) {
            $comMeta = collect($esperado)->contains(fn ($e) => $e['comMeta']);

            $somaPeriodo = ['presentes' => 0, 'dentro' => 0];
            foreach ($codigos as $codigo) {
                $ag = $agregar((int) $codigo);
                $somaPeriodo['presentes'] += $ag['presentes'];
                $somaPeriodo['dentro'] += $ag['dentro'];
            }
            $abaixoEsperado = [
                'abaixo' => $somaPeriodo['presentes'] - $somaPeriodo['dentro'],
                'total' => $somaPeriodo['presentes'],
                'pct' => $somaPeriodo['presentes'] > 0 ? round(($somaPeriodo['presentes'] - $somaPeriodo['dentro']) / $somaPeriodo['presentes'] * 100, 1) : null,
            ];

            // Evolução entre as avaliações da categoria (todos os períodos letivos): no geral e por período do curso.
            $pontos = [];
            $periodosEvolucao = [];
            foreach ($daCategoria as $a) {
                $doPonto = $linhas->where('codigo', $a['codigo']);
                $resumo = $this->somar($doPonto);
                if ($resumo['presentes'] === 0) {
                    continue;
                }

                $ag = $agregar($a['codigo']);
                $porPeriodo = [];
                foreach ($doPonto->groupBy(fn ($l) => PeriodoCurso::ordinal((string) $l->periodo) ?? 0) as $ordinal => $grupo) {
                    if ($ordinal === 0) {
                        continue;
                    }
                    $r = $this->somar($grupo);
                    $agp = $agregar($a['codigo'], (int) $ordinal);
                    $porPeriodo[(int) $ordinal] = [
                        'media' => $r['media'],
                        'dentro' => $agp['dentro'],
                        'presentes' => $agp['presentes'],
                        'pct' => $agp['presentes'] > 0 ? round($agp['dentro'] / $agp['presentes'] * 100, 1) : null,
                    ];
                    $periodosEvolucao[(int) $ordinal] = true;
                }
                ksort($porPeriodo);

                $pontos[] = [
                    'codigo' => $a['codigo'],
                    'nome' => $a['nome'],
                    'data' => $a['data'],
                    'periodoLetivo' => $a['periodoLetivo'],
                    'noPeriodo' => in_array($a['codigo'], $codigos, true),
                    'geral' => [
                        'media' => $resumo['media'],
                        'dentro' => $ag['dentro'],
                        'presentes' => $ag['presentes'],
                        'pct' => $ag['presentes'] > 0 ? round($ag['dentro'] / $ag['presentes'] * 100, 1) : null,
                    ],
                    'porPeriodo' => $porPeriodo,
                ];
            }
            ksort($periodosEvolucao);

            // Alunos dentro do esperado de cada PERÍODO DO CURSO (ordinal), somando as avaliações do período letivo — a
            // comparação entre semestres olha para o período, não para o aluno.
            $porPeriodoCurso = [];
            foreach ($codigos as $codigo) {
                foreach (($esperado[(int) $codigo]['periodos'] ?? []) as $ordinal => $p) {
                    if ($ordinal === 0 || ($filtroPeriodoCurso !== null && $ordinal !== $filtroPeriodoCurso)) {
                        continue;
                    }
                    $porPeriodoCurso[$ordinal]['presentes'] = ($porPeriodoCurso[$ordinal]['presentes'] ?? 0) + $p['presentes'];
                    $porPeriodoCurso[$ordinal]['dentro'] = ($porPeriodoCurso[$ordinal]['dentro'] ?? 0) + $p['dentro'];
                }
            }
            ksort($porPeriodoCurso);

            $detalhe = [
                'comMeta' => $comMeta,
                'abaixoEsperado' => $abaixoEsperado,
                'porPeriodoCurso' => $porPeriodoCurso,
                'evolucao' => count($pontos) >= 2 ? $pontos : [],
                'periodosEvolucao' => array_keys($periodosEvolucao),
                'campos' => $campos,
            ];
        }

        return [
            'id' => $categoriaId,
            'nome' => $nome,
            'totais' => $totais,
            'avaliacoes' => $avaliacoes->all(),
            'evolucao' => count($evolucao) >= 2 ? $evolucao : [],
            'porPeriodoDoCurso' => $porPeriodoDoCurso,
            'periodosOmitidos' => $periodosOmitidos,
            'porCurso' => $porCurso,
            'porArea' => $porArea,
            'detalhe' => $detalhe,
            'insights' => $this->insightsDaCategoria($nome, $totais, $avaliacoes, $porPeriodoDoCurso, $porArea, $porCurso, $detalhe !== null && $detalhe['comMeta'] ? $detalhe['abaixoEsperado'] : null),
        ];
    }

    /**
     * Resumos de avaliações ativas cujo curso (na época da prova) é um dos informados. `null` = todos os cursos
     * (painel da reitoria): qualquer resultado com curso conhecido.
     */
    private function resumos(?array $variantesDoCurso): Builder
    {
        $consulta = DB::table('resultado_resumos as rr')
            ->join('avaliacoes as av', 'av.codigo', '=', 'rr.avaliacao_codigo')
            ->whereNull('av.deleted_at')
            ->where(fn ($q) => $q->whereNull('av.status')->orWhere('av.status', '!=', Avaliacao::STATUS_ANULADA));

        return $variantesDoCurso === null
            ? $consulta->whereNotNull('rr.curso')->where('rr.curso', '!=', '')
            : $consulta->whereIn('rr.curso', $variantesDoCurso);
    }

    /** @return Collection<int, array{codigo: int, nome: string, data: ?string, periodoLetivo: string, categoriaId: ?int}> */
    public function avaliacoesDoCurso(?array $variantes): Collection
    {
        $linhas = $this->resumos($variantes)
            ->groupBy('av.codigo', 'av.nome', 'av.data_avaliacao', 'av.categoria_id', 'av.risco_acerto', 'av.risco_ignora_falta')
            ->selectRaw('av.codigo as codigo, av.nome as nome, av.data_avaliacao as data, av.categoria_id as categoria_id, av.risco_acerto as risco_acerto, av.risco_ignora_falta as risco_ignora_falta')
            ->get();

        // Avaliação sem data não tem como cair em "jan–jun = /1, jul–dez = /2": sem outra pista ela só aparecia
        // em "Todos". O período letivo vem, então, do nome ("2026/2 - Diagnóstico...") ou, na falta, do período
        // letivo em que a maioria dos alunos dela estava matriculada.
        $semData = $linhas->filter(fn ($l) => ! $l->data)->pluck('codigo')->map(fn ($c) => (int) $c)->all();
        $porMatricula = $semData === [] ? [] : $this->periodoLetivoPelasMatriculas($semData, $variantes);

        return $linhas
            ->map(function ($l) use ($porMatricula) {
                $data = $l->data ? Carbon::parse($l->data) : null;

                return [
                    'codigo' => (int) $l->codigo,
                    'nome' => $l->nome ?: "Avaliação #{$l->codigo}",
                    'data' => $data?->format('Y-m-d'),
                    // Mesma regra de ResultadoConsultaService::periodoLetivo(): jan–jun = /1, jul–dez = /2.
                    'periodoLetivo' => $data
                        ? $data->year.'/'.($data->month <= 6 ? 1 : 2)
                        : (self::periodoLetivoDoNome((string) $l->nome) ?? $porMatricula[(int) $l->codigo] ?? ''),
                    'categoriaId' => $l->categoria_id !== null ? (int) $l->categoria_id : null,
                    // Regra de risco própria da avaliação (RegraDeRisco): limite de acerto (null = padrão, 0 = fora do critério) e dispensa de falta.
                    'riscoAcerto' => $l->risco_acerto !== null ? (float) $l->risco_acerto : null,
                    'riscoIgnoraFalta' => (bool) $l->risco_ignora_falta,
                ];
            })
            ->sortByDesc(fn ($a) => $a['data'] ?? '')
            ->values();
    }

    /** "2026/2 - Diagnóstico Institucional" → "2026/2"; null se o nome não começa por um período letivo. */
    public static function periodoLetivoDoNome(string $nome): ?string
    {
        return preg_match('#^\s*(20\d{2})\s*[/.\-]\s*([12])(?!\d)#', $nome, $m) === 1 ? $m[1].'/'.$m[2] : null;
    }

    /**
     * Período letivo mais frequente entre as matrículas (`aluno_matriculas`) dos alunos de cada avaliação.
     *
     * @param  array<int, int>  $codigos
     * @return array<int, string> codigo => período letivo
     */
    private function periodoLetivoPelasMatriculas(array $codigos, ?array $variantes): array
    {
        $melhor = [];

        $this->resumos($variantes)
            ->join('aluno_matriculas as m', 'm.id', '=', 'rr.matricula_id')
            ->whereIn('rr.avaliacao_codigo', $codigos)
            ->where('m.periodo_letivo', '!=', '')
            ->groupBy('rr.avaliacao_codigo', 'm.periodo_letivo')
            ->selectRaw('rr.avaliacao_codigo as codigo, m.periodo_letivo as periodo_letivo, COUNT(*) as total')
            ->get()
            ->each(function ($l) use (&$melhor) {
                if (($melhor[(int) $l->codigo]['total'] ?? -1) < (int) $l->total) {
                    $melhor[(int) $l->codigo] = ['periodo_letivo' => (string) $l->periodo_letivo, 'total' => (int) $l->total];
                }
            });

        return array_map(fn ($m) => $m['periodo_letivo'], $melhor);
    }

    /** Número no padrão brasileiro, sem casa decimal sobrando: 57.9 → "57,9"; 52.0 → "52". */
    public static function pct(float|int|null $valor): string
    {
        return $valor === null ? '—' : rtrim(rtrim(number_format((float) $valor, 1, ',', ''), '0'), ',');
    }

    /** @return array<int, string> id => caminho completo ("Pai › Filha") */
    public function nomesDeCategoria(): array
    {
        $todas = Categoria::all(['id', 'nome', 'categoria_pai_id'])->keyBy('id');

        $caminho = function (Categoria $categoria) use ($todas): string {
            $partes = [$categoria->nome];
            $pai = $categoria->categoria_pai_id;
            $guarda = 0;
            while ($pai !== null && $todas->has($pai) && $guarda++ < 10) {
                array_unshift($partes, $todas[$pai]->nome);
                $pai = $todas[$pai]->categoria_pai_id;
            }

            return implode(' › ', $partes);
        };

        return $todas->map($caminho)->all();
    }

    /**
     * Quantos alunos ficaram DENTRO DO ESPERADO em cada avaliação — o aluno cujo acerto na prova alcançou o mínimo esperado
     * para o período em que ele estava (meta `questoes.periodo_minimo`: a fatia da prova que já cabe no período dele).
     * Só entram os presentes com nota, e só os alunos do(s) curso(s) em foco.
     *
     * `comMeta = false` quando a prova não especifica a meta em nenhuma questão: não há "esperado" para mostrar (`pct`
     * null) e a tela da visão geral exibe só a média no lugar.
     *
     * @param  array<int, string>  $variantes  grafias do curso em foco
     * @param  array<int, int>  $codigos  avaliações pedidas
     * @return array<int, array{comMeta: bool, presentes: int, dentro: int, pct: ?float}>  por código da avaliação
     */
    public function alunosDentroDoEsperado(array $variantes, array $codigos): array
    {
        $resultado = [];

        foreach ($this->dentroDoEsperadoPorPeriodo($variantes, $codigos) as $codigo => $dados) {
            $presentes = array_sum(array_column($dados['periodos'], 'presentes'));
            $dentro = array_sum(array_column($dados['periodos'], 'dentro'));

            $resultado[(int) $codigo] = [
                'comMeta' => $dados['comMeta'],
                'presentes' => $presentes,
                'dentro' => $dentro,
                'pct' => $dados['comMeta'] && $presentes > 0 ? round($dentro / $presentes * 100, 1) : null,
            ];
        }

        return $resultado;
    }

    /**
     * O mesmo cálculo de alunosDentroDoEsperado(), aberto POR PERÍODO DO CURSO (ordinal; 0 = período não reconhecível) —
     * base das abas "por período" e do filtro de período do curso da tela de desempenho.
     *
     * Prova sem a meta em nenhuma questão (`comMeta` false) é medida pelo mínimo padrão (MINIMO_PADRAO, 60%); com a meta,
     * quem não tem período reconhecível também cai no padrão. Agrega em SQL (período × percentual, poucas linhas) e aplica
     * a regra em PHP, pois o mínimo muda com o período. Em cache (só arrays).
     *
     * @param  array<int, string>  $variantes
     * @param  array<int, int>  $codigos
     * @return array<int, array{comMeta: bool, periodos: array<int, array{presentes: int, dentro: int}>}>
     */
    public function dentroDoEsperadoPorPeriodo(array $variantes, array $codigos): array
    {
        if ($codigos === []) {
            return [];
        }

        return CacheDeAnalise::lembrarVarias(
            'painel-esperado',
            $codigos,
            ['variantes' => $variantes],
            fn () => $this->calcularDentroDoEsperado($variantes, $codigos),
        );
    }

    /** @return array<int, array{comMeta: bool, periodos: array<int, array{presentes: int, dentro: int}>}> */
    private function calcularDentroDoEsperado(array $variantes, array $codigos): array
    {
        // Questões válidas por (avaliação × período mínimo): a fatia esperada de um período sai daqui.
        $questoes = Anulacao::excluirDistribuidas(
            DB::table('questoes')
                ->whereIn('avaliacao_codigo', $codigos)
                ->whereNull('deleted_at')
                ->whereNotNull('gabarito')->where('gabarito', '!=', '')
        )
            ->groupBy('avaliacao_codigo', 'periodo_minimo')
            ->selectRaw('avaliacao_codigo as codigo, periodo_minimo as minimo, COUNT(*) as n')
            ->get()
            ->groupBy(fn ($l) => (int) $l->codigo);

        $notas = $this->resumos($variantes)
            ->whereIn('rr.avaliacao_codigo', $codigos)
            ->where('rr.ausente', false)
            ->whereNotNull('rr.percentual')
            ->groupBy('rr.avaliacao_codigo', 'rr.periodo', 'rr.percentual')
            ->selectRaw('rr.avaliacao_codigo as codigo, rr.periodo as periodo, rr.percentual as percentual, COUNT(*) as n')
            ->get()
            ->groupBy(fn ($l) => (int) $l->codigo);

        $resultado = [];
        foreach ($codigos as $codigo) {
            $daProva = $questoes->get($codigo, collect());
            $totalQuestoes = (int) $daProva->sum('n');
            $comMeta = $totalQuestoes > 0 && $daProva->contains(fn ($l) => $l->minimo !== null);

            $periodos = [];
            foreach ($notas->get($codigo, collect()) as $linha) {
                $n = (int) $linha->n;
                $ordinal = PeriodoCurso::ordinal((string) $linha->periodo);
                $minimo = $comMeta && $ordinal !== null
                    ? ($totalQuestoes - (int) $daProva->filter(fn ($l) => PeriodoCurso::aFrente($ordinal, $l->minimo !== null ? (int) $l->minimo : null))->sum('n')) / $totalQuestoes * 100
                    : self::MINIMO_PADRAO;

                $p = &$periodos[$ordinal ?? 0];
                $p ??= ['presentes' => 0, 'dentro' => 0];
                $p['presentes'] += $n;
                // 0,005 = a precisão (2 casas) em que o percentual é gravado.
                $p['dentro'] += (float) $linha->percentual + 0.005 >= $minimo ? $n : 0;
                unset($p);
            }
            ksort($periodos);

            $resultado[(int) $codigo] = ['comMeta' => $comMeta, 'periodos' => $periodos];
        }

        return $resultado;
    }

    /**
     * Acerto por ÁREA, nível de BLOOM e TEMA nas avaliações pedidas (de UMA categoria), só entre os presentes — no geral e
     * por período do curso (ordinal) — para os gráficos de abas da tela de desempenho. `$periodoCurso` restringe tudo a
     * um período do curso (filtro da tela). Mesma regra de anulação do resto do sistema (Anulacao).
     *
     * O bruto (item × período) é cacheado; o filtro e os cortes (mínimo de respostas, limite de itens) são aplicados aqui.
     *
     * @param  array<int, string>  $variantes
     * @param  array<int, int>  $codigos
     * @return array<string, array{geral: array<int, array{rotulo: string, percentual: float, respostas: int}>, porPeriodo: array<int, array<string, ?float>>, periodos: array<int, int>}>
     */
    public function desempenhoPorCampos(array $variantes, array $codigos, ?int $periodoCurso = null): array
    {
        $vazio = ['geral' => [], 'porPeriodo' => [], 'periodos' => []];
        $saida = ['area' => $vazio, 'bloom' => $vazio, 'tema' => $vazio];

        if ($codigos === []) {
            return $saida;
        }

        // Bloom: o nível; se a planilha só trouxe o verbo (Lembrar, Aplicar...), cai para ele.
        foreach (['area' => ['area'], 'bloom' => ['bloom_nivel', 'bloom_verbo'], 'tema' => ['tema']] as $chave => $colunas) {
            foreach ($colunas as $coluna) {
                $linhas = CacheDeAnalise::lembrarVarias(
                    'painel-campo-'.$coluna,
                    $codigos,
                    ['variantes' => $variantes],
                    fn () => $this->calcularCampoPorPeriodo($variantes, $codigos, $coluna),
                );

                if ($linhas !== []) {
                    $saida[$chave] = $this->resumirCampo($linhas, $periodoCurso, $chave === 'tema' ? 15 : 20);
                    break;
                }
            }
        }

        return $saida;
    }

    /** @return array<int, array{item: string, ord: int, total: int, acertos: int}> */
    private function calcularCampoPorPeriodo(array $variantes, array $codigos, string $coluna): array
    {
        $presentes = $this->resumos($variantes)
            ->whereIn('rr.avaliacao_codigo', $codigos)
            ->where('rr.ausente', false)
            ->select('rr.avaliacao_codigo', 'rr.aluno_chave', 'rr.periodo')
            ->distinct();

        return DB::table('respostas as r')
            ->joinSub($presentes, 'pr', function ($join) {
                $join->on('pr.avaliacao_codigo', '=', 'r.avaliacao_codigo')
                    ->on('pr.aluno_chave', '=', 'r.aluno_chave')
                    ->on('pr.periodo', '=', 'r.periodo');
            })
            ->join('questoes as q', function ($join) use ($coluna) {
                Anulacao::excluirDistribuidas(
                    $join->on('q.numero', '=', 'r.questao_numero')
                        ->on('q.avaliacao_codigo', '=', 'r.avaliacao_codigo')
                        ->whereNull('q.deleted_at')
                        ->whereNotNull('q.gabarito')->where('q.gabarito', '!=', '')
                        ->whereNotNull("q.{$coluna}")->where("q.{$coluna}", '!=', ''),
                    'q.anulada_modo',
                );
            })
            ->whereNull('r.deleted_at')
            ->groupBy("q.{$coluna}", 'pr.periodo')
            ->selectRaw("q.{$coluna} as item, pr.periodo as periodo, COUNT(*) as total")
            ->selectRaw('SUM(CASE WHEN '.Anulacao::condicaoAcertoSql('r.resposta', 'q.gabarito', 'q.anulada_modo').' THEN 1 ELSE 0 END) as acertos')
            ->get()
            ->map(fn ($l) => [
                'item' => (string) $l->item,
                'ord' => PeriodoCurso::ordinal((string) $l->periodo) ?? 0,
                'total' => (int) $l->total,
                'acertos' => (int) $l->acertos,
            ])
            ->all();
    }

    /**
     * @param  array<int, array{item: string, ord: int, total: int, acertos: int}>  $linhas
     * @return array{geral: array<int, array{rotulo: string, percentual: float, respostas: int}>, porPeriodo: array<int, array<string, ?float>>, periodos: array<int, int>}
     */
    private function resumirCampo(array $linhas, ?int $periodoCurso, int $limite): array
    {
        $itens = [];
        $celulas = [];

        foreach ($linhas as $l) {
            if ($periodoCurso !== null && $l['ord'] !== $periodoCurso) {
                continue;
            }

            $itens[$l['item']]['total'] = ($itens[$l['item']]['total'] ?? 0) + $l['total'];
            $itens[$l['item']]['acertos'] = ($itens[$l['item']]['acertos'] ?? 0) + $l['acertos'];

            if ($l['ord'] > 0) {
                $celulas[$l['ord']][$l['item']] = $l;
            }
        }

        $geral = [];
        foreach ($itens as $item => $t) {
            if ($t['total'] >= self::MINIMO_RESPOSTAS_AREA) {
                $geral[] = ['rotulo' => (string) $item, 'percentual' => round($t['acertos'] / $t['total'] * 100, 1), 'respostas' => $t['total']];
            }
        }
        usort($geral, fn ($a, $b) => [$a['percentual'], $a['rotulo']] <=> [$b['percentual'], $b['rotulo']]);
        $geral = array_slice($geral, 0, $limite);

        // Por período: só os itens que aparecem no geral, e célula só com respostas suficientes (senão é ruído).
        ksort($celulas);
        $porPeriodo = [];
        foreach ($celulas as $ord => $porItem) {
            foreach ($geral as $g) {
                $c = $porItem[$g['rotulo']] ?? null;
                $porPeriodo[$ord][$g['rotulo']] = $c !== null && $c['total'] >= self::MINIMO_RESPOSTAS_CELULA
                    ? round($c['acertos'] / $c['total'] * 100, 1)
                    : null;
            }
        }
        // Período sem nenhuma célula válida não vira série.
        $porPeriodo = array_filter($porPeriodo, fn ($linha) => array_filter($linha, fn ($v) => $v !== null) !== []);

        return ['geral' => $geral, 'porPeriodo' => $porPeriodo, 'periodos' => array_keys($porPeriodo)];
    }
    /**
     * Subconsulta (avaliação × aluno) dos PRESENTES — quem não ficou com a prova inteira em branco —, restrita
     * aos alunos do curso e às avaliações pedidas. Vem de `resultado_resumos.ausente`, sem tocar em `respostas`.
     */
    private function presentes(array $variantes, array $codigos): Builder
    {
        return $this->resumos($variantes)
            ->whereIn('rr.avaliacao_codigo', $codigos)
            ->where('rr.ausente', false)
            ->select('rr.avaliacao_codigo', 'rr.aluno_chave')
            ->distinct();
    }

    /**
     * Uma linha por (avaliação, curso, período do curso) com tudo que precisa
     * ser somado depois: inscritos (têm resumo), presentes e, SÓ entre os
     * presentes, soma/contagem de percentual e quantos ficaram abaixo do corte.
     *
     * @return Collection<int, object>
     */
    private function estatisticas(array $variantes, array $cursos, array $codigos): Collection
    {
        if ($codigos === []) {
            return collect();
        }

        $rotulos = collect($cursos)->mapWithKeys(fn ($c) => [NomeCurso::chave($c) => $c]);

        // Presente = não ficou com a prova inteira em branco: `rr.ausente`, gravado por ResumoResultadoService. Antes
        // isso vinha de uma subconsulta que varria `respostas` das avaliações todas, a cada visita ao painel.
        return $this->resumos($variantes)
            ->whereIn('rr.avaliacao_codigo', $codigos)
            ->groupBy('rr.avaliacao_codigo', 'rr.curso', 'rr.periodo')
            ->selectRaw('rr.avaliacao_codigo as codigo, rr.curso as curso, rr.periodo as periodo')
            ->selectRaw('COUNT(*) as inscritos')
            ->selectRaw('SUM(CASE WHEN rr.ausente = 0 THEN 1 ELSE 0 END) as presentes')
            ->selectRaw('SUM(CASE WHEN rr.ausente = 0 AND rr.percentual IS NOT NULL THEN rr.percentual ELSE 0 END) as soma_pct')
            ->selectRaw('SUM(CASE WHEN rr.ausente = 0 AND rr.percentual IS NOT NULL THEN 1 ELSE 0 END) as n_pct')
            ->selectRaw('SUM(CASE WHEN rr.ausente = 0 AND rr.percentual IS NOT NULL AND rr.percentual < '.self::LIMIAR_ADEQUADO.' THEN 1 ELSE 0 END) as abaixo')
            ->get()
            ->map(function ($l) use ($rotulos) {
                $l->codigo = (int) $l->codigo;
                // Grafias do mesmo curso viram uma só (a do cadastro do coordenador).
                $l->curso = $rotulos->get(NomeCurso::chave($l->curso), $l->curso);

                return $l;
            });
    }

    /** @return array{inscritos: int, presentes: int, ausentes: int, presenca: ?float, media: ?float, comNota: int, abaixo: int, abaixoPct: ?float, avaliacoes: int} */
    private function somar(Collection $linhas): array
    {
        $inscritos = (int) $linhas->sum('inscritos');
        $presentes = (int) $linhas->sum('presentes');
        $nPct = (int) $linhas->sum('n_pct');
        $abaixo = (int) $linhas->sum('abaixo');

        return [
            'inscritos' => $inscritos,
            'presentes' => $presentes,
            'ausentes' => $inscritos - $presentes,
            'presenca' => $inscritos > 0 ? round($presentes / $inscritos * 100, 1) : null,
            'media' => $nPct > 0 ? round($linhas->sum('soma_pct') / $nPct, 1) : null,
            'comNota' => $nPct,
            'abaixo' => $abaixo,
            'abaixoPct' => $nPct > 0 ? round($abaixo / $nPct * 100, 1) : null,
            'avaliacoes' => $linhas->pluck('codigo')->unique()->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function resumirAvaliacao(array $avaliacao, Collection $linhas): array
    {
        return [...$avaliacao, ...$this->somar($linhas)];
    }

    /**
     * Desempenho por período DO CURSO (1º, 2º... — `resumos.periodo`, vindo da
     * planilha; "5º", "5º PERÍODO" e "5°" são o mesmo período, então
     * normaliza). Valor que não é um ordinal (vazio, "2026/1" digitado no
     * lugar errado...) não é período do curso e fica de fora — devolvido em
     * `$omitidos` (nº de presentes) pra a tela avisar.
     *
     * @return array{0: array<int, array{rotulo: string, ordem: int, presentes: int, media: ?float}>, 1: int}
     */
    private function porPeriodoDoCurso(Collection $linhas): array
    {
        $omitidos = 0;
        $grupos = [];

        foreach ($linhas as $linha) {
            $ordem = $this->ordinalDoPeriodo((string) $linha->periodo);
            if ($ordem === null) {
                $omitidos += (int) $linha->presentes;

                continue;
            }
            $grupos[$ordem][] = $linha;
        }

        ksort($grupos);

        $resultado = [];
        foreach ($grupos as $ordem => $grupo) {
            $grupo = collect($grupo);
            $nPct = (int) $grupo->sum('n_pct');
            if ($nPct === 0) {
                continue;
            }
            $resultado[] = [
                'rotulo' => $ordem.'º período',
                'ordem' => $ordem,
                'presentes' => (int) $grupo->sum('presentes'),
                'media' => round($grupo->sum('soma_pct') / $nPct, 1),
            ];
        }

        return [$resultado, $omitidos];
    }

    /** "5º", "5°", "5º PERÍODO" → 5. Qualquer outra coisa → null (ver PeriodoCurso). */
    private function ordinalDoPeriodo(string $periodo): ?int
    {
        return PeriodoCurso::ordinal($periodo);
    }

    /** @return array<int, array{curso: string, presentes: int, media: ?float, abaixoPct: ?float}> */
    private function porCurso(Collection $linhas, array $cursos): array
    {
        return $linhas->groupBy('curso')
            ->map(function (Collection $grupo, string $curso) {
                $resumo = $this->somar($grupo);

                return ['curso' => $curso, 'presentes' => $resumo['presentes'], 'media' => $resumo['media'], 'abaixoPct' => $resumo['abaixoPct']];
            })
            ->filter(fn ($c) => $c['media'] !== null)
            ->sortByDesc('media')
            ->values()
            ->all();
    }

    /**
     * % de acerto por área, só entre os presentes, nas avaliações informadas
     * (de UMA categoria). Mesma regra de anulação do resto do sistema (Anulacao).
     *
     * @return array<int, array{area: string, percentual: float, respostas: int}> do mais fraco pro mais forte
     */
    public function desempenhoPorArea(array $variantes, array $cursos, array $codigos): array
    {
        if ($codigos === []) {
            return [];
        }

        // A varredura de `respostas` por área é a parte cara do painel; só muda quando os resultados, as questões
        // ou o curso dos resultados mudam (ver CacheDeAnalise).
        return CacheDeAnalise::lembrarVarias(
            'painel-areas',
            $codigos,
            ['variantes' => $variantes],
            fn () => $this->calcularDesempenhoPorArea($variantes, $codigos),
        );
    }

    /** @return array<int, array{area: string, percentual: float, respostas: int}> */
    private function calcularDesempenhoPorArea(array $variantes, array $codigos): array
    {
        $linhas = DB::table('respostas as r')
            ->joinSub($this->presentes($variantes, $codigos), 'pr', function ($join) {
                $join->on('pr.avaliacao_codigo', '=', 'r.avaliacao_codigo')
                    ->on('pr.aluno_chave', '=', 'r.aluno_chave');
            })
            ->join('questoes as q', function ($join) {
                Anulacao::excluirDistribuidas(
                    $join->on('q.numero', '=', 'r.questao_numero')
                        ->on('q.avaliacao_codigo', '=', 'r.avaliacao_codigo')
                        ->whereNull('q.deleted_at')
                        ->whereNotNull('q.gabarito')
                        ->where('q.gabarito', '!=', '')
                        ->whereNotNull('q.area')
                        ->where('q.area', '!=', ''),
                    'q.anulada_modo',
                );
            })
            ->whereNull('r.deleted_at')
            ->groupBy('q.area')
            ->selectRaw('q.area as area, COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN '.Anulacao::condicaoAcertoSql('r.resposta', 'q.gabarito', 'q.anulada_modo').' THEN 1 ELSE 0 END) as acertos')
            ->get();

        return $linhas
            ->filter(fn ($l) => (int) $l->total >= self::MINIMO_RESPOSTAS_AREA)
            ->map(fn ($l) => [
                'area' => (string) $l->area,
                'percentual' => round((int) $l->acertos / (int) $l->total * 100, 1),
                'respostas' => (int) $l->total,
            ])
            ->sortBy('percentual')
            ->values()
            ->all();
    }

    /**
     * Insights que não dependem da prova (presença).
     *
     * @return array<int, array{tom: string, icone: string, texto: string}>
     */
    private function insightsGerais(array $geral): array
    {
        if ($geral['presenca'] !== null && $geral['presenca'] < 85.0 && $geral['inscritos'] > 0) {
            return [[
                'tom' => 'atencao',
                'icone' => 'ph-user-minus',
                'texto' => "Presença de ".self::pct($geral['presenca'])."%: {$geral['ausentes']} ausência(s) em {$geral['inscritos']} participações esperadas no período.",
            ]];
        }

        return [];
    }

    /**
     * Cartões de texto no formato do boletim do aluno (tom, ícone, texto) —
     * todos sobre UMA categoria.
     *
     * @param  Collection<int, array<string, mixed>>  $avaliacoes  da categoria, no período, em ordem de data
     * @return array<int, array{tom: string, icone: string, texto: string}>
     */
    private function insightsDaCategoria(string $categoria, array $totais, Collection $avaliacoes, array $porPeriodoDoCurso, array $porArea, array $porCurso, ?array $abaixoEsperado = null): array
    {
        $cartoes = [];

        // Avaliação mais recente do período vs a anterior da MESMA categoria
        // (que pode ser de outro período letivo).
        $ultima = $avaliacoes->filter(fn ($a) => $a['delta'] !== null)->last();
        if ($ultima !== null && abs($ultima['delta']) >= self::LIMIAR_VARIACAO) {
            $subiu = $ultima['delta'] > 0;
            $anterior = $ultima['anterior'];
            $quando = $anterior['periodoLetivo'] !== '' ? ", {$anterior['periodoLetivo']}" : '';
            $cartoes[] = [
                'tom' => $subiu ? 'positivo' : 'atencao',
                'icone' => $subiu ? 'ph-trend-up' : 'ph-trend-down',
                'texto' => "Em {$categoria}, a média na avaliação mais recente ({$ultima['nome']}) ".($subiu ? 'subiu ' : 'caiu ').self::pct(abs($ultima['delta']))." pontos frente à anterior da mesma categoria ({$anterior['nome']}{$quando}): ".self::pct($anterior['media']).'% → '.self::pct($ultima['media']).'%.',
            ];
        }

        if ($abaixoEsperado !== null) {
            // A categoria traz o mínimo esperado por período: o alerta é sobre ele, não sobre os 60% fixos.
            if ($abaixoEsperado['pct'] !== null && $abaixoEsperado['pct'] / 100 >= self::LIMIAR_ALERTA_ABAIXO) {
                $cartoes[] = [
                    'tom' => 'atencao',
                    'icone' => 'ph-warning-circle',
                    'texto' => self::pct($abaixoEsperado['pct'])."% dos alunos presentes ficaram abaixo do desempenho esperado para o período em que estão ({$abaixoEsperado['abaixo']} de {$abaixoEsperado['total']}).",
                ];
            }
        } elseif ($totais['abaixoPct'] !== null && $totais['abaixoPct'] / 100 >= self::LIMIAR_ALERTA_ABAIXO) {
            $cartoes[] = [
                'tom' => 'atencao',
                'icone' => 'ph-warning-circle',
                'texto' => self::pct($totais['abaixoPct']).'% dos alunos presentes ficaram abaixo de '.(int) self::LIMIAR_ADEQUADO."% de acerto ({$totais['abaixo']} de {$totais['comNota']}).",
            ];
        }

        if (count($porArea) >= 2) {
            $pior = $porArea[0];
            $melhor = $porArea[count($porArea) - 1];
            $cartoes[] = [
                'tom' => $pior['percentual'] < self::LIMIAR_ADEQUADO ? 'atencao' : 'neutro',
                'icone' => 'ph-target',
                'texto' => "Área com menor desempenho: {$pior['area']} (".self::pct($pior['percentual'])."%). A mais forte é {$melhor['area']} (".self::pct($melhor['percentual']).'%).',
            ];
        }

        $periodosComNota = array_values(array_filter($porPeriodoDoCurso, fn ($p) => $p['presentes'] >= self::MINIMO_PRESENTES_PERIODO));
        if (count($periodosComNota) >= 2) {
            usort($periodosComNota, fn ($a, $b) => $a['media'] <=> $b['media']);
            $pior = $periodosComNota[0];
            $melhor = $periodosComNota[count($periodosComNota) - 1];
            $cartoes[] = [
                'tom' => 'neutro',
                'icone' => 'ph-graduation-cap',
                'texto' => "O {$pior['rotulo']} tem a menor média nesta categoria (".self::pct($pior['media'])."%); o {$melhor['rotulo']}, a maior (".self::pct($melhor['media']).'%).',
            ];
        }

        if (count($porCurso) >= 2) {
            $pior = $porCurso[count($porCurso) - 1];
            $melhor = $porCurso[0];
            $cartoes[] = [
                'tom' => 'neutro',
                'icone' => 'ph-books',
                'texto' => "Entre os seus cursos, {$melhor['curso']} tem a maior média (".self::pct($melhor['media'])."%) e {$pior['curso']} a menor (".self::pct($pior['media']).'%).',
            ];
        }

        return $cartoes;
    }
}
