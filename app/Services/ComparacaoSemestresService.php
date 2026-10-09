<?php

namespace App\Services;

use App\Models\Admin;
use App\Support\PeriodoCurso;
use Illuminate\Support\Collection;

/**
 * Compara dois períodos letivos do(s) curso(s) do coordenador ("como estamos em 2026/2 frente a 2026/1?").
 *
 * Mesma regra do resto do painel: a comparação é SEMPRE dentro da mesma categoria de avaliação (provas de
 * categorias diferentes não são comparáveis), ausentes ficam fora das médias, e o curso de cada resultado é o da
 * época da prova. Só os números que não dependem da prova (alunos, presença, quem está em atenção) são globais.
 *
 * A comparação de alunos olha para o PERÍODO DO CURSO (1º, 2º...), não para a pessoa: em cada período, quantos alunos
 * ficaram dentro do esperado (a fatia da prova que cabe no período dele, ou 60% sem a meta) em cada semestre. Variação
 * menor que VARIACAO_PERIODO pontos percentuais conta como "estável".
 */
class ComparacaoSemestresService
{
    /**
     * Pontos percentuais, no % de alunos dentro do esperado de um período do curso, a partir dos quais ele subiu/caiu de
     * verdade entre os dois semestres (turmas diferentes oscilam sozinhas).
     */
    public const VARIACAO_PERIODO = 5.0;

    public function __construct(
        private readonly CoordenadorDashboardService $dashboard,
        private readonly CoordenadorAlunosService $alunos,
    ) {}

    /**
     * Períodos letivos disponíveis para o curso (do mais recente ao mais antigo), ou [] se o coordenador não tem
     * curso/resultados.
     *
     * @return array<int, string>
     */
    public function periodos(Admin $coordenador, string $curso): array
    {
        return $this->dashboard->escopo($coordenador, $curso, '')['periodosDisponiveis'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array{categoria?: string, periodo_curso?: int|string|null}  $filtros  categoria ('' = todas, '0' = sem categoria, ou o id)
     *                                                                              e período do curso (ordinal) — valem para os dois semestres
     * @return array<string, mixed>
     */
    public function comparar(Admin $coordenador, string $curso, string $atual, string $referencia, array $filtros = []): array
    {
        $filtroCategoria = (string) ($filtros['categoria'] ?? '');
        $filtroPeriodoCurso = isset($filtros['periodo_curso']) && $filtros['periodo_curso'] !== '' ? (int) $filtros['periodo_curso'] : null;

        $escopoAtual = $this->dashboard->escopo($coordenador, $curso, $atual);
        $escopoReferencia = $this->dashboard->escopo($coordenador, $curso, $referencia);

        // Estrito: uma categoria/período do curso que só existe num dos semestres aparece vazio no outro, em vez de tudo.
        $opcoes = ['detalhado' => true, 'campos' => false, 'estrito' => true, 'periodo_curso' => $filtroPeriodoCurso];
        $painelAtual = $this->dashboard->gerar($coordenador, $curso, $atual, $escopoAtual, $opcoes);
        $painelReferencia = $this->dashboard->gerar($coordenador, $curso, $referencia, $escopoReferencia, $opcoes);

        $listaAtual = $this->soDoPeriodoDoCurso($this->alunos->alunos($escopoAtual), $filtroPeriodoCurso);
        $listaReferencia = $this->soDoPeriodoDoCurso($this->alunos->alunos($escopoReferencia), $filtroPeriodoCurso);
        $resumoAtual = $this->alunos->resumo($listaAtual);
        $resumoReferencia = $this->alunos->resumo($listaReferencia);

        $geral = [
            'alunos' => $this->par($resumoAtual['total'], $resumoReferencia['total']),
            'emAtencao' => $this->par($resumoAtual['precisamAtencao'], $resumoReferencia['precisamAtencao']),
            'presenca' => $this->par($painelAtual['geral']['presenca'], $painelReferencia['geral']['presenca']),
            'avaliacoes' => $this->par($painelAtual['geral']['avaliacoes'], $painelReferencia['geral']['avaliacoes']),
        ];

        $atuais = collect($painelAtual['categorias'])->keyBy(fn ($c) => $c['id'] ?? 0);
        $referencias = collect($painelReferencia['categorias'])->keyBy(fn ($c) => $c['id'] ?? 0);

        // As categorias e períodos do curso que existem em QUALQUER um dos dois semestres, para os seletores da tela.
        $categoriasDisponiveis = collect([...$painelAtual['categoriasDisponiveis'], ...$painelReferencia['categoriasDisponiveis']])
            ->unique('id')
            ->sortBy(fn ($c) => $c['id'] === 0 ? 'zzz' : mb_strtolower($c['nome']))
            ->values()
            ->all();
        $periodosCursoDisponiveis = collect([...$painelAtual['periodosCursoDisponiveis'], ...$painelReferencia['periodosCursoDisponiveis']])->unique()->sort()->values()->all();

        $categorias = [];
        foreach ($atuais->keys()->merge($referencias->keys())->unique() as $id) {
            if ($filtroCategoria !== '' && (int) $id !== (int) $filtroCategoria) {
                continue;
            }

            $a = $atuais->get($id);
            $r = $referencias->get($id);
            $comMeta = ($a['detalhe']['comMeta'] ?? false) || ($r['detalhe']['comMeta'] ?? false);

            $categorias[] = [
                'id' => $id ?: null,
                'nome' => ($a ?? $r)['nome'],
                'emAmbos' => $a !== null && $r !== null,
                'so' => $a === null ? $referencia : ($r === null ? $atual : null),
                'media' => $this->par($a['totais']['media'] ?? null, $r['totais']['media'] ?? null),
                // "Alunos abaixo do esperado" quando a categoria traz o mínimo por período; senão, abaixo de 60%.
                'comMeta' => $comMeta,
                'abaixoPct' => $this->par($a['totais']['abaixoPct'] ?? null, $r['totais']['abaixoPct'] ?? null),
                'abaixoEsperado' => $this->par($a['detalhe']['abaixoEsperado']['pct'] ?? null, $r['detalhe']['abaixoEsperado']['pct'] ?? null),
                'presenca' => $this->par($a['totais']['presenca'] ?? null, $r['totais']['presenca'] ?? null),
                'avaliacoes' => $this->par($a['totais']['avaliacoes'] ?? null, $r['totais']['avaliacoes'] ?? null),
                'areas' => $this->parearListas($a['porArea'] ?? [], $r['porArea'] ?? [], 'area', 'percentual'),
                'periodosDoCurso' => $this->parearPeriodos($a['porPeriodoDoCurso'] ?? [], $r['porPeriodoDoCurso'] ?? []),
                'alunosPorPeriodo' => $a !== null && $r !== null
                    ? $this->alunosPorPeriodo($a['detalhe']['porPeriodoCurso'] ?? [], $r['detalhe']['porPeriodoCurso'] ?? [])
                    : null,
            ];
        }
        usort($categorias, fn ($x, $y) => ($x['id'] === null) <=> ($y['id'] === null) ?: strcasecmp($x['nome'], $y['nome']));

        return [
            'atual' => $atual,
            'referencia' => $referencia,
            'geral' => $geral,
            'categorias' => $categorias,
            'categoriasDisponiveis' => $categoriasDisponiveis,
            'periodosCursoDisponiveis' => $periodosCursoDisponiveis,
            'destaques' => $this->destaques($geral, $categorias, $atual, $referencia),
            'painelAtual' => $escopoAtual,
        ];
    }

    /**
     * Por PERÍODO DO CURSO: quantos alunos ficaram dentro do esperado em cada semestre (a regra do esperado é a de
     * CoordenadorDashboardService: a fatia da prova que cabe no período do aluno, ou 60% sem a meta). Compara os totais
     * do período — não o mesmo aluno nos dois semestres: turmas mudam, o que interessa é como o período está indo.
     *
     * `sentido`: 'subiu' / 'caiu' / 'estavel' pela variação do % de alunos dentro do esperado (VARIACAO_PERIODO); null
     * quando o período só existe num dos semestres.
     *
     * @param  array<int, array{presentes: int, dentro: int}>  $atual  ordinal => totais
     * @param  array<int, array{presentes: int, dentro: int}>  $referencia
     * @return array{periodos: array<int, array<string, mixed>>, subiram: int, estaveis: int, cairam: int}
     */
    private function alunosPorPeriodo(array $atual, array $referencia): array
    {
        $resumo = fn (?array $p) => $p === null || $p['presentes'] === 0 ? null : [
            'presentes' => $p['presentes'],
            'dentro' => $p['dentro'],
            'pct' => round($p['dentro'] / $p['presentes'] * 100, 1),
        ];

        $periodos = [];
        $contagem = ['subiram' => 0, 'estaveis' => 0, 'cairam' => 0];

        foreach (collect(array_keys($atual))->merge(array_keys($referencia))->unique()->sort() as $ordinal) {
            $a = $resumo($atual[$ordinal] ?? null);
            $r = $resumo($referencia[$ordinal] ?? null);
            $deltaPct = $a !== null && $r !== null ? round($a['pct'] - $r['pct'], 1) : null;

            $sentido = null;
            if ($deltaPct !== null) {
                $sentido = $deltaPct >= self::VARIACAO_PERIODO ? 'subiu' : ($deltaPct <= -self::VARIACAO_PERIODO ? 'caiu' : 'estavel');
                $contagem[['subiu' => 'subiram', 'caiu' => 'cairam', 'estavel' => 'estaveis'][$sentido]]++;
            }

            $periodos[] = [
                'ordem' => (int) $ordinal,
                'rotulo' => PeriodoCurso::rotulo((int) $ordinal),
                'atual' => $a,
                'referencia' => $r,
                'deltaPct' => $deltaPct,
                'deltaAlunos' => $a !== null && $r !== null ? $a['dentro'] - $r['dentro'] : null,
                'sentido' => $sentido,
            ];
        }

        return ['periodos' => $periodos, ...$contagem];
    }

    /**
     * @param  array<int, array<string, mixed>>  $alunos  lista de CoordenadorAlunosService::alunos()
     * @return array<int, array<string, mixed>>
     */
    private function soDoPeriodoDoCurso(array $alunos, ?int $ordinal): array
    {
        return $ordinal === null ? $alunos : array_values(array_filter($alunos, fn ($a) => $a['periodoCurso'] === $ordinal));
    }
    /**
     * @return array{atual: int|float|null, referencia: int|float|null, delta: float|null}
     */
    private function par(int|float|null $atual, int|float|null $referencia): array
    {
        return [
            'atual' => $atual,
            'referencia' => $referencia,
            'delta' => $atual !== null && $referencia !== null ? round($atual - $referencia, 1) : null,
        ];
    }

    /**
     * Junta duas listas por um rótulo (área), com o valor de cada semestre. As que variaram mais para baixo vêm
     * primeiro (é onde o coordenador precisa olhar); as que existem só num semestre vão para o fim.
     *
     * @param  array<int, array<string, mixed>>  $atual
     * @param  array<int, array<string, mixed>>  $referencia
     * @return array<int, array{rotulo: string, atual: ?float, referencia: ?float, delta: ?float}>
     */
    private function parearListas(array $atual, array $referencia, string $campoRotulo, string $campoValor): array
    {
        $a = collect($atual)->mapWithKeys(fn ($i) => [$i[$campoRotulo] => (float) $i[$campoValor]]);
        $r = collect($referencia)->mapWithKeys(fn ($i) => [$i[$campoRotulo] => (float) $i[$campoValor]]);

        return $this->ordenarPorVariacao(
            $a->keys()->merge($r->keys())->unique()->map(fn ($rotulo) => ['rotulo' => (string) $rotulo, ...$this->par($a[$rotulo] ?? null, $r[$rotulo] ?? null)])
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $atual
     * @param  array<int, array<string, mixed>>  $referencia
     * @return array<int, array{rotulo: string, atual: ?float, referencia: ?float, delta: ?float, ordem: int}>
     */
    private function parearPeriodos(array $atual, array $referencia): array
    {
        $a = collect($atual)->keyBy('ordem');
        $r = collect($referencia)->keyBy('ordem');

        return $a->keys()->merge($r->keys())->unique()->sort()->map(fn ($ordem) => [
            'rotulo' => ($a[$ordem] ?? $r[$ordem])['rotulo'],
            'ordem' => (int) $ordem,
            ...$this->par($a[$ordem]['media'] ?? null, $r[$ordem]['media'] ?? null),
        ])->values()->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $itens
     * @return array<int, array<string, mixed>>
     */
    private function ordenarPorVariacao(Collection $itens): array
    {
        return $itens
            ->sort(fn ($x, $y) => [$x['delta'] === null, $x['delta'] ?? 0, $x['rotulo']] <=> [$y['delta'] === null, $y['delta'] ?? 0, $y['rotulo']])
            ->values()
            ->all();
    }

    /**
     * Frases no formato dos insights do painel (tom, ícone, texto).
     *
     * @param  array<string, array<string, mixed>>  $geral
     * @param  array<int, array<string, mixed>>  $categorias
     * @return array<int, array{tom: string, icone: string, texto: string}>
     */
    private function destaques(array $geral, array $categorias, string $atual, string $referencia): array
    {
        $pct = CoordenadorDashboardService::pct(...);
        $cartoes = [];

        foreach ($categorias as $c) {
            $delta = $c['media']['delta'];
            if ($delta !== null && abs($delta) >= 3.0) {
                $subiu = $delta > 0;
                $cartoes[] = [
                    'tom' => $subiu ? 'positivo' : 'atencao',
                    'icone' => $subiu ? 'ph-trend-up' : 'ph-trend-down',
                    'texto' => "Em {$c['nome']}, a média ".($subiu ? 'subiu ' : 'caiu ').$pct(abs($delta))." pontos de {$referencia} para {$atual} ({$pct($c['media']['referencia'])}% → {$pct($c['media']['atual'])}%).",
                ];
            }

            $areas = array_values(array_filter($c['areas'], fn ($a) => $a['delta'] !== null));
            if ($areas !== [] && $areas[0]['delta'] <= -3.0) {
                $cartoes[] = [
                    'tom' => 'atencao',
                    'icone' => 'ph-target',
                    'texto' => "Em {$c['nome']}, a área que mais piorou foi {$areas[0]['rotulo']} ({$pct($areas[0]['referencia'])}% → {$pct($areas[0]['atual'])}%).",
                ];
            }
            $melhor = $areas === [] ? null : $areas[count($areas) - 1];
            if ($melhor !== null && $melhor['delta'] >= 3.0) {
                $cartoes[] = [
                    'tom' => 'positivo',
                    'icone' => 'ph-target',
                    'texto' => "Em {$c['nome']}, a área que mais evoluiu foi {$melhor['rotulo']} ({$pct($melhor['referencia'])}% → {$pct($melhor['atual'])}%).",
                ];
            }

            // O período do curso em que menos alunos atingiram o esperado (a maior queda).
            $caiu = $c['alunosPorPeriodo'] === null ? [] : array_values(array_filter($c['alunosPorPeriodo']['periodos'], fn ($p) => $p['sentido'] === 'caiu'));
            if ($caiu !== []) {
                usort($caiu, fn ($x, $y) => $x['deltaPct'] <=> $y['deltaPct']);
                $p = $caiu[0];
                $cartoes[] = [
                    'tom' => 'atencao',
                    'icone' => 'ph-users-three',
                    'texto' => "Em {$c['nome']}, no {$p['rotulo']}, menos alunos atingiram o esperado: {$pct($p['referencia']['pct'])}% em {$referencia} → {$pct($p['atual']['pct'])}% em {$atual}.",
                ];
            }
        }

        $presenca = $geral['presenca']['delta'];
        if ($presenca !== null && abs($presenca) >= 3.0) {
            $cartoes[] = [
                'tom' => $presenca > 0 ? 'positivo' : 'atencao',
                'icone' => $presenca > 0 ? 'ph-user-check' : 'ph-user-minus',
                'texto' => 'A presença '.($presenca > 0 ? 'subiu ' : 'caiu ').$pct(abs($presenca))." pontos de {$referencia} para {$atual} ({$pct($geral['presenca']['referencia'])}% → {$pct($geral['presenca']['atual'])}%).",
            ];
        }

        return $cartoes;
    }
}
