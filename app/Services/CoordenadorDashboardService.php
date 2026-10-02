<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Categoria;
use App\Models\Resposta;
use App\Support\Anulacao;
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

    /** Períodos do curso com menos presentes que isto não entram no insight de melhor/pior. */
    private const MINIMO_PRESENTES_PERIODO = 10;

    /**
     * @return array<string, mixed>
     */
    public function gerar(Admin $coordenador, string $cursoSelecionado = '', ?string $periodoLetivo = null): array
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
            return [...$base, 'semResultados' => true];
        }

        $periodos = $avaliacoes->pluck('periodoLetivo')->filter()->unique()->sortDesc()->values()->all();
        $periodoSelecionado = $periodoLetivo ?? ($periodos[0] ?? '');
        if ($periodoSelecionado !== '' && ! in_array($periodoSelecionado, $periodos, true)) {
            $periodoSelecionado = $periodos[0] ?? '';
        }

        $doPeriodo = $periodoSelecionado === ''
            ? $avaliacoes
            : $avaliacoes->filter(fn ($a) => $a['periodoLetivo'] === $periodoSelecionado)->values();

        // Histórico das categorias presentes no período: inclui períodos
        // anteriores, que é de onde vem a "avaliação anterior" de cada uma.
        $categoriasNoPeriodo = $doPeriodo->pluck('categoriaId')->filter()->unique()->all();
        $historico = $avaliacoes->filter(fn ($a) => in_array($a['categoriaId'], $categoriasNoPeriodo, true))->values();

        $codigosPeriodo = $doPeriodo->pluck('codigo')->all();
        $linhas = $this->estatisticas($variantes, $cursos, $doPeriodo->pluck('codigo')->merge($historico->pluck('codigo'))->unique()->values()->all());

        $nomesCategoria = $this->nomesDeCategoria();
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
    private function blocoDaCategoria(?int $categoriaId, string $nome, Collection $doPeriodo, Collection $historico, Collection $linhas, array $variantes, array $cursos): array
    {
        $codigos = $doPeriodo->pluck('codigo')->all();

        // Avaliações da categoria, de qualquer período, da mais antiga pra mais nova.
        $daCategoria = $categoriaId === null
            ? collect()
            : $historico->filter(fn ($a) => $a['categoriaId'] === $categoriaId)
                ->sortBy(fn ($a) => [$a['data'] ?? '9999-12-31', $a['codigo']])
                ->values();

        $resumos = $daCategoria->mapWithKeys(fn ($a) => [$a['codigo'] => $this->resumirAvaliacao($a, $linhas->where('codigo', $a['codigo']))]);

        $avaliacoes = $doPeriodo
            ->sortBy(fn ($a) => [$a['data'] ?? '9999-12-31', $a['codigo']])
            ->values()
            ->map(function ($a) use ($linhas, $daCategoria, $resumos) {
                $linha = $this->resumirAvaliacao($a, $linhas->where('codigo', $a['codigo']));

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
        $porArea = $this->desempenhoPorArea($variantes, $cursos, $codigos);

        $evolucao = $resumos->filter(fn ($r) => $r['media'] !== null)->map(fn ($r) => [
            'codigo' => $r['codigo'],
            'nome' => $r['nome'],
            'data' => $r['data'],
            'periodoLetivo' => $r['periodoLetivo'],
            'media' => $r['media'],
            'noPeriodo' => in_array($r['codigo'], $codigos, true),
        ])->values()->all();

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
            'insights' => $this->insightsDaCategoria($nome, $totais, $avaliacoes, $porPeriodoDoCurso, $porArea, $porCurso),
        ];
    }

    /** Resumos de avaliações ativas cujo curso (na época da prova) é um dos informados. */
    private function resumos(array $variantesDoCurso): Builder
    {
        return DB::table('resultado_resumos as rr')
            ->join('avaliacoes as av', 'av.codigo', '=', 'rr.avaliacao_codigo')
            ->whereNull('av.deleted_at')
            ->where(fn ($q) => $q->whereNull('av.status')->orWhere('av.status', '!=', 'anulada'))
            ->whereIn('rr.curso', $variantesDoCurso);
    }

    /** @return Collection<int, array{codigo: int, nome: string, data: ?string, periodoLetivo: string, categoriaId: ?int}> */
    private function avaliacoesDoCurso(array $variantes): Collection
    {
        return $this->resumos($variantes)
            ->groupBy('av.codigo', 'av.nome', 'av.data_avaliacao', 'av.categoria_id')
            ->selectRaw('av.codigo as codigo, av.nome as nome, av.data_avaliacao as data, av.categoria_id as categoria_id')
            ->get()
            ->map(function ($l) {
                $data = $l->data ? Carbon::parse($l->data) : null;

                return [
                    'codigo' => (int) $l->codigo,
                    'nome' => $l->nome ?: "Avaliação #{$l->codigo}",
                    'data' => $data?->format('Y-m-d'),
                    // Mesma regra de ResultadoConsultaService::periodoLetivo(): jan–jun = /1, jul–dez = /2.
                    'periodoLetivo' => $data ? $data->year.'/'.($data->month <= 6 ? 1 : 2) : '',
                    'categoriaId' => $l->categoria_id !== null ? (int) $l->categoria_id : null,
                ];
            })
            ->sortByDesc(fn ($a) => $a['data'] ?? '')
            ->values();
    }

    /** @return array<int, string> id => caminho completo ("Pai › Filha") */
    private function nomesDeCategoria(): array
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
     * (aluno × avaliação) que NÃO ficou com a prova inteira em branco — os
     * presentes. Restrito aos alunos do curso e às avaliações pedidas.
     */
    private function presentes(array $variantes, array $codigos): Builder
    {
        return DB::table('respostas as rp')
            ->whereIn('rp.avaliacao_codigo', $codigos)
            ->whereNull('rp.deleted_at')
            ->whereIn('rp.aluno_chave', $this->resumos($variantes)->whereIn('rr.avaliacao_codigo', $codigos)->select('rr.aluno_chave'))
            ->groupBy('rp.avaliacao_codigo', 'rp.aluno_chave')
            ->havingRaw('SUM(CASE WHEN '.Resposta::semRespostaSql('rp.resposta').' THEN 0 ELSE 1 END) > 0')
            ->select('rp.avaliacao_codigo', 'rp.aluno_chave');
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

        return $this->resumos($variantes)
            ->whereIn('rr.avaliacao_codigo', $codigos)
            ->leftJoinSub($this->presentes($variantes, $codigos), 'pr', function ($join) {
                $join->on('pr.avaliacao_codigo', '=', 'rr.avaliacao_codigo')
                    ->on('pr.aluno_chave', '=', 'rr.aluno_chave');
            })
            ->groupBy('rr.avaliacao_codigo', 'rr.curso', 'rr.periodo')
            ->selectRaw('rr.avaliacao_codigo as codigo, rr.curso as curso, rr.periodo as periodo')
            ->selectRaw('COUNT(*) as inscritos')
            ->selectRaw('SUM(CASE WHEN pr.aluno_chave IS NULL THEN 0 ELSE 1 END) as presentes')
            ->selectRaw('SUM(CASE WHEN pr.aluno_chave IS NOT NULL AND rr.percentual IS NOT NULL THEN rr.percentual ELSE 0 END) as soma_pct')
            ->selectRaw('SUM(CASE WHEN pr.aluno_chave IS NOT NULL AND rr.percentual IS NOT NULL THEN 1 ELSE 0 END) as n_pct')
            ->selectRaw('SUM(CASE WHEN pr.aluno_chave IS NOT NULL AND rr.percentual IS NOT NULL AND rr.percentual < '.self::LIMIAR_ADEQUADO.' THEN 1 ELSE 0 END) as abaixo')
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
    private function desempenhoPorArea(array $variantes, array $cursos, array $codigos): array
    {
        if ($codigos === []) {
            return [];
        }

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
                'texto' => "Presença de {$geral['presenca']}%: {$geral['ausentes']} ausência(s) em {$geral['inscritos']} participações esperadas no período.",
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
    private function insightsDaCategoria(string $categoria, array $totais, Collection $avaliacoes, array $porPeriodoDoCurso, array $porArea, array $porCurso): array
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
                'texto' => "Em {$categoria}, a média na avaliação mais recente ({$ultima['nome']}) ".($subiu ? 'subiu ' : 'caiu ').abs($ultima['delta'])." pontos frente à anterior da mesma categoria ({$anterior['nome']}{$quando}): {$anterior['media']}% → {$ultima['media']}%.",
            ];
        }

        if ($totais['abaixoPct'] !== null && $totais['abaixoPct'] / 100 >= self::LIMIAR_ALERTA_ABAIXO) {
            $cartoes[] = [
                'tom' => 'atencao',
                'icone' => 'ph-warning-circle',
                'texto' => "{$totais['abaixoPct']}% dos alunos presentes ficaram abaixo de ".(int) self::LIMIAR_ADEQUADO."% de acerto ({$totais['abaixo']} de {$totais['comNota']}).",
            ];
        }

        if (count($porArea) >= 2) {
            $pior = $porArea[0];
            $melhor = $porArea[count($porArea) - 1];
            $cartoes[] = [
                'tom' => $pior['percentual'] < self::LIMIAR_ADEQUADO ? 'atencao' : 'neutro',
                'icone' => 'ph-target',
                'texto' => "Área com menor desempenho: {$pior['area']} ({$pior['percentual']}%). A mais forte é {$melhor['area']} ({$melhor['percentual']}%).",
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
                'texto' => "O {$pior['rotulo']} tem a menor média nesta categoria ({$pior['media']}%); o {$melhor['rotulo']}, a maior ({$melhor['media']}%).",
            ];
        }

        if (count($porCurso) >= 2) {
            $pior = $porCurso[count($porCurso) - 1];
            $melhor = $porCurso[0];
            $cartoes[] = [
                'tom' => 'neutro',
                'icone' => 'ph-books',
                'texto' => "Entre os seus cursos, {$melhor['curso']} tem a maior média ({$melhor['media']}%) e {$pior['curso']} a menor ({$pior['media']}%).",
            ];
        }

        return $cartoes;
    }
}
