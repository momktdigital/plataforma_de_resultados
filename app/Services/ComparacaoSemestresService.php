<?php

namespace App\Services;

use App\Models\Admin;
use Illuminate\Support\Collection;

/**
 * Compara dois períodos letivos do(s) curso(s) do coordenador ("como estamos em 2026/2 frente a 2026/1?").
 *
 * Mesma regra do resto do painel: a comparação é SEMPRE dentro da mesma categoria de avaliação (provas de
 * categorias diferentes não são comparáveis), ausentes ficam fora das médias, e o curso de cada resultado é o da
 * época da prova. Só os números que não dependem da prova (alunos, presença, quem está em atenção) são globais.
 *
 * Quem fez prova nos dois semestres é pareado pela pessoa (aluno do cadastro): o que se compara é a MÉDIA DELE na
 * categoria em cada semestre. Variação menor que VARIACAO_ALUNO pontos conta como "estável" — numa prova de 20
 * questões a nota de um aluno oscila sozinha.
 */
class ComparacaoSemestresService
{
    /** Pontos percentuais a partir dos quais a média de um aluno subiu/caiu de verdade. */
    public const VARIACAO_ALUNO = 5.0;

    /** Quantos alunos aparecem em cada lista de "mais subiram"/"mais caíram". */
    private const TOPO = 5;

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
    public function comparar(Admin $coordenador, string $curso, string $atual, string $referencia): array
    {
        $escopoAtual = $this->dashboard->escopo($coordenador, $curso, $atual);
        $escopoReferencia = $this->dashboard->escopo($coordenador, $curso, $referencia);

        $painelAtual = $this->dashboard->gerar($coordenador, $curso, $atual, $escopoAtual);
        $painelReferencia = $this->dashboard->gerar($coordenador, $curso, $referencia, $escopoReferencia);

        $listaAtual = $this->alunos->alunos($escopoAtual);
        $listaReferencia = $this->alunos->alunos($escopoReferencia);
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

        $categorias = [];
        foreach ($atuais->keys()->merge($referencias->keys())->unique() as $id) {
            $a = $atuais->get($id);
            $r = $referencias->get($id);

            $categorias[] = [
                'id' => $id ?: null,
                'nome' => ($a ?? $r)['nome'],
                'emAmbos' => $a !== null && $r !== null,
                'so' => $a === null ? $referencia : ($r === null ? $atual : null),
                'media' => $this->par($a['totais']['media'] ?? null, $r['totais']['media'] ?? null),
                'abaixoPct' => $this->par($a['totais']['abaixoPct'] ?? null, $r['totais']['abaixoPct'] ?? null),
                'presenca' => $this->par($a['totais']['presenca'] ?? null, $r['totais']['presenca'] ?? null),
                'avaliacoes' => $this->par($a['totais']['avaliacoes'] ?? null, $r['totais']['avaliacoes'] ?? null),
                'areas' => $this->parearListas($a['porArea'] ?? [], $r['porArea'] ?? [], 'area', 'percentual'),
                'periodosDoCurso' => $this->parearPeriodos($a['porPeriodoDoCurso'] ?? [], $r['porPeriodoDoCurso'] ?? []),
                'alunos' => $a !== null && $r !== null
                    ? $this->alunosEmAmbos(
                        $this->alunos->alunos($escopoAtual, (string) $id),
                        $this->alunos->alunos($escopoReferencia, (string) $id),
                    )
                    : null,
            ];
        }
        usort($categorias, fn ($x, $y) => ($x['id'] === null) <=> ($y['id'] === null) ?: strcasecmp($x['nome'], $y['nome']));

        return [
            'atual' => $atual,
            'referencia' => $referencia,
            'geral' => $geral,
            'categorias' => $categorias,
            'destaques' => $this->destaques($geral, $categorias, $atual, $referencia),
            'painelAtual' => $escopoAtual,
        ];
    }

    /**
     * Alunos com média na categoria nos DOIS semestres: quantos subiram, ficaram estáveis ou caíram, e os que mais
     * variaram.
     *
     * @param  array<int, array<string, mixed>>  $atual
     * @param  array<int, array<string, mixed>>  $referencia
     * @return array{comparaveis: int, subiram: int, estaveis: int, cairam: int, maisSubiram: array<int, array<string, mixed>>, maisCairam: array<int, array<string, mixed>>}
     */
    private function alunosEmAmbos(array $atual, array $referencia): array
    {
        $antes = collect($referencia)->filter(fn ($a) => $a['id'] !== null && $a['media'] !== null)->keyBy('id');

        $pares = collect($atual)
            ->filter(fn ($a) => $a['id'] !== null && $a['media'] !== null && $antes->has($a['id']))
            ->map(fn ($a) => [
                'id' => $a['id'],
                'nome' => $a['nome'],
                'ra' => $a['ra'],
                'foto' => $a['foto'],
                'de' => $antes[$a['id']]['media'],
                'para' => $a['media'],
                'delta' => round($a['media'] - $antes[$a['id']]['media'], 1),
            ]);

        $limiar = self::VARIACAO_ALUNO;

        return [
            'comparaveis' => $pares->count(),
            'subiram' => $pares->filter(fn ($p) => $p['delta'] >= $limiar)->count(),
            'estaveis' => $pares->filter(fn ($p) => abs($p['delta']) < $limiar)->count(),
            'cairam' => $pares->filter(fn ($p) => $p['delta'] <= -$limiar)->count(),
            'maisSubiram' => $pares->filter(fn ($p) => $p['delta'] >= $limiar)->sortByDesc('delta')->take(self::TOPO)->values()->all(),
            'maisCairam' => $pares->filter(fn ($p) => $p['delta'] <= -$limiar)->sortBy('delta')->take(self::TOPO)->values()->all(),
        ];
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

            if ($c['alunos'] !== null && $c['alunos']['comparaveis'] >= 5 && $c['alunos']['cairam'] > $c['alunos']['subiram']) {
                $cartoes[] = [
                    'tom' => 'atencao',
                    'icone' => 'ph-users-three',
                    'texto' => "Em {$c['nome']}, mais alunos caíram ({$c['alunos']['cairam']}) do que subiram ({$c['alunos']['subiram']}) entre os {$c['alunos']['comparaveis']} que fizeram provas nos dois períodos.",
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
