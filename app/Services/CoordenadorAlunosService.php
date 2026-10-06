<?php

namespace App\Services;

use App\Models\Aluno;
use App\Models\AlunoMatricula;
use App\Support\Anulacao;
use App\Support\CacheDeAnalise;
use App\Support\PeriodoCurso;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Os ALUNOS do curso do coordenador e como cada um está indo no semestre: lista com situação (regular, em
 * atenção, destaque...), e a ficha individual com a trajetória nas avaliações.
 *
 * Mesmas regras do resto do painel (CoordenadorDashboardService):
 *  - o curso de cada resultado é `resultado_resumos.curso` (o curso do aluno QUANDO fez a prova), nunca o curso
 *    atual dele: a ficha de um aluno transferido só mostra, ao coordenador, as provas feitas no curso dele;
 *  - ausente = prova inteira em branco (`resultado_resumos.ausente`) e fica FORA das médias — conta só em
 *    presença/faltas;
 *  - a média é sobre `resultado_resumos` (uma linha por aluno×avaliação×período — pequena); `respostas` só é
 *    tocada na ficha de UM aluno, para o desempenho por área.
 *
 * Provas de categorias diferentes não são comparáveis. A lista deixa filtrar por categoria; sem filtro, a "média"
 * é a simples entre as avaliações do semestre (a mesma "média geral" que o aluno vê no portal) e a TENDÊNCIA só
 * compara avaliações da MESMA categoria.
 *
 * Quem é a mesma pessoa: `aluno_chave` é CPF ou RA, então o mesmo aluno pode ter chaves diferentes em avaliações
 * diferentes. Por isso as linhas são ligadas ao cadastro por `aluno_id`, depois RA e CPF (ver CLAUDE.md).
 */
class CoordenadorAlunosService
{
    public const LIMIAR_ADEQUADO = CoordenadorDashboardService::LIMIAR_ADEQUADO;

    /** Média a partir da qual o aluno, sem faltas, é destaque. */
    public const LIMIAR_DESTAQUE = 80.0;

    /**
     * Queda (pontos percentuais) entre duas avaliações da mesma categoria que já merece atenção. Menos que isso é
     * ruído: numa prova de 20 questões a nota de UM aluno oscila facilmente ±10 pontos sem que nada tenha mudado.
     */
    public const QUEDA_ALERTA = 20.0;

    /** Faltas (provas inteiras em branco) a partir das quais o aluno entra em atenção. */
    public const FALTAS_ALERTA = 2;

    /** Mínimo de respostas numa área para a ficha comparar o aluno com o curso. */
    private const MINIMO_RESPOSTAS_AREA_ALUNO = 3;

    /** @var array<string, string> */
    public const SITUACOES = [
        'atencao' => 'Em atenção',
        'ausente' => 'Ausente em tudo',
        'regular' => 'Regular',
        'destaque' => 'Destaque',
        'sem_resultado' => 'Sem resultado',
    ];

    /** Quanto menor, mais urgente (ordenação "prioridade"). */
    private const PRIORIDADE = ['ausente' => 0, 'atencao' => 1, 'sem_resultado' => 2, 'regular' => 3, 'destaque' => 4];

    public function __construct(private readonly CoordenadorDashboardService $dashboard) {}

    // ------------------------------------------------------------------------------------------------------------
    // Lista
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Todos os alunos do curso no semestre escolhido (quem fez ao menos uma avaliação + quem está matriculado nele).
     *
     * @param  array<string, mixed>  $escopo  saída de CoordenadorDashboardService::escopo() (sem semCurso/semResultados)
     * @param  string  $categoria  '' = todas; '0' = avaliações sem categoria; senão o id da categoria
     * @return array<int, array<string, mixed>>
     */
    public function alunos(array $escopo, string $categoria = ''): array
    {
        /** @var Collection<int, array<string, mixed>> $avaliacoes */
        $avaliacoes = $escopo['doPeriodo'];
        if ($categoria !== '') {
            $id = (int) $categoria;
            $avaliacoes = $avaliacoes->filter(fn ($a) => (int) ($a['categoriaId'] ?? 0) === $id)->values();
        }

        $porCodigo = $avaliacoes->keyBy('codigo');
        $codigos = $porCodigo->keys()->map(fn ($c) => (int) $c)->all();
        $variantes = $escopo['variantes'];

        $linhas = $codigos === []
            ? []
            : CacheDeAnalise::lembrarVarias('painel-alunos', $codigos, ['variantes' => $variantes], fn () => $this->linhasDeResumo($codigos, $variantes));

        $chavePessoa = $this->ligarAoCadastro($linhas);

        $pessoas = [];
        foreach ($linhas as $i => $l) {
            $pessoa = &$pessoas[$chavePessoa[$i]];
            $pessoa ??= ['id' => $l['aluno_id'] ?? null, 'ra' => null, 'avals' => []];
            $pessoa['ra'] ??= $l['ra'];

            // Duas linhas da mesma avaliação (chaves diferentes da mesma pessoa): vale a que tem prova feita.
            $atual = $pessoa['avals'][$l['codigo']] ?? null;
            if ($atual === null || ($atual['ausente'] && ! $l['ausente'])) {
                $pessoa['avals'][$l['codigo']] = ['pc' => $l['percentual'], 'ausente' => $l['ausente'], 'matricula' => $l['matricula_id']];
            }
            unset($pessoa);
        }

        // O id da pessoa é o do cadastro quando houve ligação (a chave é "a{id}").
        foreach ($pessoas as $chave => &$p) {
            $p['id'] = str_starts_with($chave, 'a') ? (int) substr($chave, 1) : null;
        }
        unset($p);

        // Matriculados no semestre que ainda não têm nenhum resultado aparecem na lista como "sem resultado".
        $matriculas = $this->matriculasDoSemestre($variantes, (string) $escopo['periodoSelecionado']);
        foreach ($matriculas as $alunoId => $m) {
            $pessoas['a'.$alunoId] ??= ['id' => $alunoId, 'ra' => null, 'avals' => []];
        }

        $cadastro = $this->cadastro(array_values(array_filter(array_column($pessoas, 'id'))));
        $matriculasDosResultados = $this->matriculasPorId(
            collect($pessoas)->flatMap(fn ($p) => array_column($p['avals'], 'matricula'))->filter()->unique()->values()->all()
        );

        // Acompanhamento do coordenador (último registro de cada aluno, só dos cursos dele).
        $acompanhamentos = app(AcompanhamentoService::class)->ultimos(array_keys($cadastro), $variantes);

        $resultado = [];
        foreach ($pessoas as $p) {
            $aluno = $p['id'] !== null ? ($cadastro[$p['id']] ?? null) : null;
            $metricas = $this->metricas($p['avals'], $porCodigo);
            [$situacao, $motivos] = self::classificar($metricas);

            // Período do curso / turma na época: da matrícula da prova mais recente; senão a do semestre; senão o cadastro.
            $matricula = $this->matriculaDaUltimaProva($p['avals'], $porCodigo, $matriculasDosResultados)
                ?? ($p['id'] !== null ? ($matriculas[$p['id']] ?? null) : null);
            $ordinal = PeriodoCurso::ordinal($matricula['periodo'] ?? $aluno?->periodo);

            $resultado[] = [
                'id' => $aluno?->id,
                'nome' => $aluno?->nome,
                'ra' => $aluno?->ra ?? $p['ra'],
                'foto' => $aluno?->fotoUrl(96),
                'periodoCurso' => $ordinal,
                'periodoCursoRotulo' => $ordinal !== null ? PeriodoCurso::rotulo($ordinal) : null,
                'turma' => ($matricula['turma'] ?? null) ?: $aluno?->turma,
                'acompanhamento' => $aluno !== null ? ($acompanhamentos[$aluno->id] ?? null) : null,
                ...$metricas,
                'situacao' => $situacao,
                'motivos' => $motivos,
            ];
        }

        return $resultado;
    }

    /**
     * Contagens para os cartões e atalhos de filtro.
     *
     * @param  array<int, array<string, mixed>>  $alunos
     * @return array{total: int, porSituacao: array<string, int>, precisamAtencao: int, inscritos: int, presentes: int, presenca: ?float, porPeriodoCurso: array<int, array{ordinal: int, rotulo: string, alunos: int, atencao: int, inscritos: int, presentes: int, presenca: ?float}>}
     */
    public function resumo(array $alunos): array
    {
        $porSituacao = array_fill_keys(array_keys(self::SITUACOES), 0);
        $porPeriodo = [];
        $inscritos = $presentes = 0;

        foreach ($alunos as $a) {
            $porSituacao[$a['situacao']]++;
            $inscritos += $a['inscritos'];
            $presentes += $a['presentes'];

            if ($a['periodoCurso'] !== null) {
                $grupo = &$porPeriodo[$a['periodoCurso']];
                $grupo ??= ['ordinal' => $a['periodoCurso'], 'rotulo' => PeriodoCurso::rotulo($a['periodoCurso']), 'alunos' => 0, 'atencao' => 0, 'inscritos' => 0, 'presentes' => 0];
                $grupo['alunos']++;
                $grupo['atencao'] += in_array($a['situacao'], ['atencao', 'ausente'], true) ? 1 : 0;
                $grupo['inscritos'] += $a['inscritos'];
                $grupo['presentes'] += $a['presentes'];
                unset($grupo);
            }
        }
        ksort($porPeriodo);

        return [
            'total' => count($alunos),
            'porSituacao' => $porSituacao,
            'precisamAtencao' => $porSituacao['atencao'] + $porSituacao['ausente'],
            'inscritos' => $inscritos,
            'presentes' => $presentes,
            'presenca' => $inscritos > 0 ? round($presentes / $inscritos * 100, 1) : null,
            'porPeriodoCurso' => array_values(array_map(
                fn ($g) => [...$g, 'presenca' => $g['inscritos'] > 0 ? round($g['presentes'] / $g['inscritos'] * 100, 1) : null],
                $porPeriodo,
            )),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $alunos
     * @param  array{busca?: string, situacao?: string, periodo_curso?: string, ordem?: string, acompanhamento?: string}  $filtros
     * @return array<int, array<string, mixed>>
     */
    public function filtrar(array $alunos, array $filtros): array
    {
        $busca = self::normalizar($filtros['busca'] ?? '');
        $situacao = $filtros['situacao'] ?? '';
        $periodoCurso = $filtros['periodo_curso'] ?? '';
        $acompanhamento = $filtros['acompanhamento'] ?? '';

        $alunos = array_filter($alunos, function ($a) use ($busca, $situacao, $periodoCurso, $acompanhamento) {
            // 'sem_registro' = nenhum acompanhamento ainda; ou um dos status (último registro).
            if ($acompanhamento === 'sem_registro' && $a['acompanhamento'] !== null) {
                return false;
            }
            if ($acompanhamento !== '' && $acompanhamento !== 'sem_registro' && ($a['acompanhamento']['status'] ?? null) !== $acompanhamento) {
                return false;
            }
            if ($situacao === 'atencao' && ! in_array($a['situacao'], ['atencao', 'ausente'], true)) {
                return false;
            }
            if ($situacao !== '' && $situacao !== 'atencao' && $a['situacao'] !== $situacao) {
                return false;
            }
            if ($periodoCurso !== '' && (string) $a['periodoCurso'] !== $periodoCurso) {
                return false;
            }

            return $busca === ''
                || str_contains(self::normalizar((string) $a['nome']), $busca)
                || str_contains(self::normalizar((string) $a['ra']), $busca);
        });

        $nome = fn ($a) => $a['nome'] === null ? "\u{10FFFF}".$a['ra'] : self::normalizar($a['nome']);
        $porNome = fn ($a, $b) => strcmp($nome($a), $nome($b));
        // Valor ausente (null) vai sempre para o fim, qualquer que seja o sentido.
        $porValor = fn (string $campo, int $sentido) => function ($a, $b) use ($campo, $sentido, $porNome) {
            if ($a[$campo] === $b[$campo]) {
                return $porNome($a, $b);
            }
            if ($a[$campo] === null || $b[$campo] === null) {
                return $a[$campo] === null ? 1 : -1;
            }

            return ($a[$campo] <=> $b[$campo]) * $sentido;
        };

        $comparador = match ($filtros['ordem'] ?? 'nome') {
            'media_asc' => $porValor('media', 1),
            'media_desc' => $porValor('media', -1),
            'presenca_asc' => $porValor('presenca', 1),
            'faltas' => $porValor('faltas', -1),
            'prioridade' => fn ($a, $b) => [self::PRIORIDADE[$a['situacao']], $a['media'] ?? 101.0] <=> [self::PRIORIDADE[$b['situacao']], $b['media'] ?? 101.0] ?: $porNome($a, $b),
            default => $porNome,
        };

        $alunos = array_values($alunos);
        usort($alunos, $comparador);

        return $alunos;
    }

    /**
     * Quem merece a atenção primeiro: ausentes em tudo e depois os de menor média.
     *
     * @param  array<int, array<string, mixed>>  $alunos
     * @return array<int, array<string, mixed>>
     */
    public function emAtencao(array $alunos, int $limite = 6): array
    {
        return array_slice($this->filtrar($alunos, ['situacao' => 'atencao', 'ordem' => 'prioridade']), 0, $limite);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Ficha do aluno
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Trajetória de UM aluno nas avaliações do(s) curso(s) do coordenador. Null quando o aluno não é do curso
     * (nem tem resultado, nem matrícula, nele) — a rota responde 404 e não revela se o aluno existe.
     *
     * @param  array<string, mixed>  $escopo  saída de CoordenadorDashboardService::escopo() (sem semCurso/semResultados)
     * @param  ?string  $periodoLetivo  null = o mais recente em que o aluno aparece; '' = todos
     * @return ?array<string, mixed>
     */
    public function ficha(array $escopo, Aluno $aluno, ?string $periodoLetivo): ?array
    {
        /** @var Collection<int, array<string, mixed>> $todas */
        $todas = $escopo['avaliacoes'];
        $variantes = $escopo['variantes'];
        $porCodigo = $todas->keyBy('codigo');

        $linhas = DB::table('resultado_resumos')
            ->whereIn('avaliacao_codigo', $porCodigo->keys()->all())
            ->whereIn('curso', $variantes)
            ->where(function ($q) use ($aluno) {
                $q->where('aluno_id', $aluno->id);
                if (filled($aluno->ra)) {
                    $q->orWhere('ra', $aluno->ra);
                }
                if (filled($aluno->cpf)) {
                    $q->orWhere('cpf', $aluno->cpf);
                }
            })
            ->get(['avaliacao_codigo', 'aluno_chave', 'periodo', 'ausente', 'percentual', 'acertos', 'total', 'matricula_id']);

        $matriculas = AlunoMatricula::where('aluno_id', $aluno->id)
            ->whereIn('curso', $variantes)
            ->orderByDesc('periodo_letivo')->orderByDesc('data_inicio')->orderByDesc('id')
            ->get();

        if ($linhas->isEmpty() && $matriculas->isEmpty()) {
            return null;
        }

        // Semestres em que o aluno aparece (resultado ou matrícula), do mais recente.
        $periodosDoAluno = $linhas
            ->map(fn ($l) => $porCodigo[(int) $l->avaliacao_codigo]['periodoLetivo'] ?? '')
            ->merge($matriculas->pluck('periodo_letivo'))
            ->filter()->unique()->sortDesc()->values()->all();
        $periodo = $periodoLetivo ?? ($periodosDoAluno[0] ?? '');
        if ($periodo !== '' && ! in_array($periodo, $periodosDoAluno, true)) {
            $periodo = $periodosDoAluno[0] ?? '';
        }

        // Uma entrada por avaliação (se houver duas linhas, vale a que tem prova feita).
        $avals = [];
        $chaves = [];
        foreach ($linhas as $l) {
            $codigo = (int) $l->avaliacao_codigo;
            if ($periodo !== '' && ($porCodigo[$codigo]['periodoLetivo'] ?? '') !== $periodo) {
                continue;
            }
            $chaves[$l->aluno_chave] = true;
            $atual = $avals[$codigo] ?? null;
            if ($atual === null || ($atual['ausente'] && ! $l->ausente)) {
                $avals[$codigo] = [
                    'pc' => $l->percentual !== null ? (float) $l->percentual : null,
                    'ausente' => (bool) $l->ausente,
                    'acertos' => (int) $l->acertos,
                    'total' => (int) $l->total,
                    'matricula' => $l->matricula_id,
                ];
            }
        }

        $metricas = $this->metricas($avals, $porCodigo);
        [$situacao, $motivos] = self::classificar($metricas);

        $estatisticas = $this->estatisticasDoCurso($avals, $variantes);
        $nomesCategoria = $this->dashboard->nomesDeCategoria();

        $blocos = [];
        $porCategoria = collect(array_keys($avals))->groupBy(fn ($c) => $porCodigo[$c]['categoriaId'] ?? 0);
        foreach ($porCategoria as $categoriaId => $codigosDaCategoria) {
            $itens = $codigosDaCategoria
                ->sortBy(fn ($c) => [$porCodigo[$c]['data'] ?? '9999-12-31', $c])
                ->map(function ($c) use ($avals, $porCodigo, $estatisticas) {
                    $a = $avals[$c];
                    $curso = $estatisticas[$c] ?? null;

                    return [
                        'codigo' => $c,
                        'nome' => $porCodigo[$c]['nome'],
                        'data' => $porCodigo[$c]['data'],
                        'ausente' => $a['ausente'],
                        'percentual' => $a['ausente'] ? null : $a['pc'],
                        'acertos' => $a['ausente'] ? null : $a['acertos'],
                        'total' => $a['total'],
                        'mediaCurso' => $curso['media'] ?? null,
                        'diferenca' => (! $a['ausente'] && $a['pc'] !== null && ($curso['media'] ?? null) !== null) ? round($a['pc'] - $curso['media'], 1) : null,
                        'posicao' => (! $a['ausente'] && $a['pc'] !== null) ? ($curso['posicoes'][$c] ?? null) : null,
                        'presentesCurso' => $curso['presentes'] ?? null,
                    ];
                })->values()->all();

            // Áreas: aluno x curso (o curso, na categoria inteira do semestre — o mesmo recorte da tela Desempenho).
            $codigosDaCategoriaNoSemestre = $escopo['avaliacoes']
                ->filter(fn ($a) => (int) ($a['categoriaId'] ?? 0) === (int) $categoriaId && ($periodo === '' || $a['periodoLetivo'] === $periodo))
                ->pluck('codigo')->all();
            $areasCurso = collect($this->dashboard->desempenhoPorArea($variantes, $escopo['cursosEmFoco'], $codigosDaCategoriaNoSemestre))->keyBy('area');
            $areas = collect($this->desempenhoDoAlunoPorArea(array_keys($chaves), $codigosDaCategoria->all()))
                ->map(fn ($a) => [...$a, 'curso' => $areasCurso[$a['area']]['percentual'] ?? null])
                ->all();

            $blocos[] = [
                'id' => (int) $categoriaId ?: null,
                'nome' => $nomesCategoria[(int) $categoriaId] ?? 'Sem categoria',
                'avaliacoes' => $itens,
                'grafico' => count(array_filter($itens, fn ($i) => $i['percentual'] !== null)) >= 2
                    ? array_values(array_map(fn ($i) => ['nome' => $i['nome'], 'aluno' => $i['percentual'], 'curso' => $i['mediaCurso']], array_filter($itens, fn ($i) => $i['percentual'] !== null)))
                    : [],
                'areas' => $areas,
            ];
        }
        usort($blocos, fn ($a, $b) => ($a['id'] === null) <=> ($b['id'] === null) ?: strcasecmp($a['nome'], $b['nome']));

        // Matrícula que valia na prova mais recente; sem prova, a mais recente do semestre/curso.
        $matriculaAtual = $matriculas->first(fn ($m) => $periodo === '' || $m->periodo_letivo === $periodo) ?? $matriculas->first();
        $ordinal = PeriodoCurso::ordinal($matriculaAtual?->periodo ?? $aluno->periodo);

        return [
            'aluno' => $aluno,
            'periodoSelecionado' => $periodo,
            'periodosDoAluno' => $periodosDoAluno,
            'periodoCursoRotulo' => $ordinal !== null ? PeriodoCurso::rotulo($ordinal) : null,
            'turma' => $matriculaAtual?->turma ?: $aluno->turma,
            'curso' => $matriculaAtual?->curso,
            'statusMatricula' => $matriculaAtual?->status,
            'matriculas' => $matriculas->map(fn ($m) => [
                'periodoLetivo' => $m->periodo_letivo,
                'curso' => $m->curso,
                'periodo' => $m->periodo,
                'turma' => $m->turma,
                'status' => $m->status,
            ])->all(),
            ...$metricas,
            'situacao' => $situacao,
            'motivos' => $motivos,
            'categorias' => $blocos,
            'resumoTexto' => $this->resumoEmTexto($metricas, $blocos),
        ];
    }

    // ------------------------------------------------------------------------------------------------------------
    // Regras
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Situação do aluno a partir das métricas dele e os motivos (mostrados ao coordenador — nada de "caixa-preta").
     *
     * @param  array<string, mixed>  $m  inscritos, presentes, faltas, media, tendencia
     * @return array{0: string, 1: array<int, string>}
     */
    public static function classificar(array $m): array
    {
        if ($m['inscritos'] === 0) {
            return ['sem_resultado', ['Nenhuma avaliação registrada neste semestre.']];
        }
        if ($m['presentes'] === 0) {
            return ['ausente', ["Faltou em todas as {$m['inscritos']} avaliação(ões) do semestre."]];
        }

        $motivos = [];
        if ($m['media'] !== null && $m['media'] < self::LIMIAR_ADEQUADO) {
            $motivos[] = 'Média de '.self::pct($m['media']).'% (abaixo de '.(int) self::LIMIAR_ADEQUADO.'%).';
        }
        if ($m['faltas'] >= self::FALTAS_ALERTA) {
            $motivos[] = "{$m['faltas']} faltas em {$m['inscritos']} avaliações.";
        }
        $tendencia = $m['tendencia']['delta'] ?? null;
        if ($tendencia !== null && $tendencia <= -self::QUEDA_ALERTA) {
            $motivos[] = 'Queda de '.self::pct(abs($tendencia)).' pontos na última avaliação.';
        }

        if ($motivos !== []) {
            return ['atencao', $motivos];
        }

        return [($m['media'] !== null && $m['media'] >= self::LIMIAR_DESTAQUE && $m['faltas'] === 0) ? 'destaque' : 'regular', []];
    }

    /**
     * @param  array<int, array{pc: ?float, ausente: bool}>  $avals  codigo => resultado do aluno
     * @param  Collection<int, array<string, mixed>>  $porCodigo  avaliações (nome, data, categoriaId) por código
     * @return array{inscritos: int, presentes: int, faltas: int, presenca: ?float, media: ?float, abaixo: int, ultima: ?array{nome: string, data: ?string, pc: float}, tendencia: ?array{delta: float, de: float, para: float}}
     */
    private function metricas(array $avals, Collection $porCodigo): array
    {
        $presentes = $comNota = $abaixo = 0;
        $soma = 0.0;
        $ultima = null;
        $porCategoria = [];

        foreach ($avals as $codigo => $a) {
            if ($a['ausente']) {
                continue;
            }
            $presentes++;
            if ($a['pc'] === null) {
                continue;
            }

            $comNota++;
            $soma += $a['pc'];
            $abaixo += $a['pc'] < self::LIMIAR_ADEQUADO ? 1 : 0;

            $avaliacao = $porCodigo[$codigo];
            $ordem = ($avaliacao['data'] ?? '0000-00-00').'|'.str_pad((string) $codigo, 10, '0', STR_PAD_LEFT);
            $ponto = ['ordem' => $ordem, 'datada' => $avaliacao['data'] !== null, 'pc' => $a['pc'], 'nome' => $avaliacao['nome'], 'data' => $avaliacao['data']];
            $porCategoria[$avaliacao['categoriaId'] ?? 0][] = $ponto;
            if ($ultima === null || $ordem > $ultima['ordem']) {
                $ultima = $ponto;
            }
        }

        // Tendência: as duas últimas avaliações DATADAS da mesma categoria; vale a categoria cuja última é a mais recente.
        $tendencia = null;
        $ordemTendencia = '';
        foreach ($porCategoria as $pontos) {
            $datados = array_values(array_filter($pontos, fn ($p) => $p['datada']));
            usort($datados, fn ($a, $b) => strcmp($a['ordem'], $b['ordem']));
            if (count($datados) < 2) {
                continue;
            }
            $fim = $datados[count($datados) - 1];
            $antes = $datados[count($datados) - 2];
            if ($fim['ordem'] > $ordemTendencia) {
                $ordemTendencia = $fim['ordem'];
                $tendencia = ['delta' => round($fim['pc'] - $antes['pc'], 1), 'de' => $antes['pc'], 'para' => $fim['pc']];
            }
        }

        $inscritos = count($avals);

        return [
            'inscritos' => $inscritos,
            'presentes' => $presentes,
            'faltas' => $inscritos - $presentes,
            'presenca' => $inscritos > 0 ? round($presentes / $inscritos * 100, 1) : null,
            'media' => $comNota > 0 ? round($soma / $comNota, 1) : null,
            'abaixo' => $abaixo,
            'ultima' => $ultima !== null ? ['nome' => $ultima['nome'], 'data' => $ultima['data'], 'pc' => $ultima['pc']] : null,
            'tendencia' => $tendencia,
        ];
    }

    /**
     * Frases da ficha, no formato dos insights do boletim do aluno (tom, ícone, texto).
     *
     * @param  array<string, mixed>  $m
     * @param  array<int, array<string, mixed>>  $blocos
     * @return array<int, array{tom: string, icone: string, texto: string}>
     */
    private function resumoEmTexto(array $m, array $blocos): array
    {
        $cartoes = [];

        if ($m['inscritos'] > 0 && $m['faltas'] > 0) {
            $cartoes[] = [
                'tom' => $m['faltas'] >= self::FALTAS_ALERTA ? 'atencao' : 'neutro',
                'icone' => 'ph-user-minus',
                'texto' => "Faltou em {$m['faltas']} de {$m['inscritos']} avaliação(ões) do semestre (presença de ".self::pct($m['presenca']).'%).',
            ];
        }

        if ($m['tendencia'] !== null && abs($m['tendencia']['delta']) >= 3) {
            $subiu = $m['tendencia']['delta'] > 0;
            $cartoes[] = [
                'tom' => $subiu ? 'positivo' : 'atencao',
                'icone' => $subiu ? 'ph-trend-up' : 'ph-trend-down',
                'texto' => 'Na avaliação mais recente com comparação, o desempenho '.($subiu ? 'subiu ' : 'caiu ').self::pct(abs($m['tendencia']['delta'])).' pontos frente à anterior da mesma categoria ('.self::pct($m['tendencia']['de']).'% → '.self::pct($m['tendencia']['para']).'%).',
            ];
        }

        $abaixoDoCurso = 0;
        $comparadas = 0;
        foreach ($blocos as $b) {
            foreach ($b['avaliacoes'] as $a) {
                if ($a['diferenca'] !== null) {
                    $comparadas++;
                    $abaixoDoCurso += $a['diferenca'] < 0 ? 1 : 0;
                }
            }
        }
        if ($comparadas >= 2) {
            $cartoes[] = [
                'tom' => $abaixoDoCurso === $comparadas ? 'atencao' : ($abaixoDoCurso === 0 ? 'positivo' : 'neutro'),
                'icone' => 'ph-users-three',
                'texto' => $abaixoDoCurso === 0
                    ? "Ficou acima da média do curso em todas as {$comparadas} avaliações com comparação."
                    : "Ficou abaixo da média do curso em {$abaixoDoCurso} de {$comparadas} avaliações com comparação.",
            ];
        }

        // Área mais fraca / mais forte (a de pior desempenho de toda a trajetória, com amostra mínima).
        $areas = collect($blocos)->flatMap(fn ($b) => $b['areas'])->sortBy('percentual')->values();
        if ($areas->count() >= 2) {
            $pior = $areas->first();
            $melhor = $areas->last();
            $cartoes[] = [
                'tom' => $pior['percentual'] < self::LIMIAR_ADEQUADO ? 'atencao' : 'neutro',
                'icone' => 'ph-target',
                'texto' => "Área mais fraca: {$pior['area']} (".self::pct($pior['percentual']).'%); a mais forte é '.$melhor['area'].' ('.self::pct($melhor['percentual']).'%).',
            ];
        }

        return $cartoes;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Consultas
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Uma linha por (aluno × avaliação × período) do curso — em array simples, para poder ir ao cache.
     *
     * @param  array<int, int>  $codigos
     * @param  array<int, string>  $variantes
     * @return array<int, array{codigo: int, aluno_chave: string, aluno_id: ?int, ra: ?string, cpf: ?string, ausente: bool, percentual: ?float, matricula_id: ?int}>
     */
    private function linhasDeResumo(array $codigos, array $variantes): array
    {
        return DB::table('resultado_resumos')
            ->whereIn('avaliacao_codigo', $codigos)
            ->whereIn('curso', $variantes)
            ->orderBy('id')
            ->get(['avaliacao_codigo', 'aluno_chave', 'aluno_id', 'ra', 'cpf', 'ausente', 'percentual', 'matricula_id'])
            ->map(fn ($r) => [
                'codigo' => (int) $r->avaliacao_codigo,
                'aluno_chave' => (string) $r->aluno_chave,
                'aluno_id' => $r->aluno_id !== null ? (int) $r->aluno_id : null,
                'ra' => $r->ra,
                'cpf' => $r->cpf,
                'ausente' => (bool) $r->ausente,
                'percentual' => $r->percentual !== null ? (float) $r->percentual : null,
                'matricula_id' => $r->matricula_id !== null ? (int) $r->matricula_id : null,
            ])
            ->all();
    }

    /**
     * Liga cada linha ao cadastro de alunos: `aluno_id`, depois RA, depois CPF. Linha sem cadastro fica com a
     * própria chave ("k..."). Os RA/CPF vão como parâmetro (`alunos` e `resultado_resumos` têm collations diferentes).
     *
     * @param  array<int, array<string, mixed>>  $linhas
     * @return array<int, string> índice da linha => "a{id do aluno}" ou "k{aluno_chave}"
     */
    private function ligarAoCadastro(array $linhas): array
    {
        $semId = array_filter($linhas, fn ($l) => $l['aluno_id'] === null);
        $porRa = $this->idsPor('ra', array_column($semId, 'ra'));
        $porCpf = $this->idsPor('cpf', array_column($semId, 'cpf'));

        $chaves = [];
        foreach ($linhas as $i => $l) {
            $id = $l['aluno_id'] ?? ($porRa[$l['ra']] ?? null) ?? ($porCpf[$l['cpf']] ?? null);
            $chaves[$i] = $id !== null ? 'a'.$id : 'k'.$l['aluno_chave'];
        }

        return $chaves;
    }

    /**
     * @param  array<int, ?string>  $valores
     * @return array<string, int> valor => id do aluno
     */
    private function idsPor(string $coluna, array $valores): array
    {
        $mapa = [];
        foreach (array_chunk(array_values(array_unique(array_filter($valores))), 500) as $lote) {
            foreach (DB::table('alunos')->whereIn($coluna, $lote)->get(['id', $coluna]) as $a) {
                $mapa[(string) $a->{$coluna}] ??= (int) $a->id;
            }
        }

        return $mapa;
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, Aluno>
     */
    private function cadastro(array $ids): array
    {
        $alunos = [];
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $lote) {
            foreach (Aluno::whereIn('id', $lote)->get(['id', 'ra', 'nome', 'periodo', 'turma', 'cod_perfil']) as $aluno) {
                $alunos[$aluno->id] = $aluno;
            }
        }

        return $alunos;
    }

    /**
     * Matriculados no semestre, em curso do coordenador, que seguem vigentes nele (ativos ou que cumpriram o
     * período). Só faz sentido com um semestre escolhido.
     *
     * @param  array<int, string>  $variantes
     * @return array<int, array{periodo: ?string, turma: ?string}> aluno_id => matrícula
     */
    private function matriculasDoSemestre(array $variantes, string $periodoLetivo): array
    {
        if ($periodoLetivo === '') {
            return [];
        }

        return AlunoMatricula::whereIn('curso', $variantes)
            ->where('periodo_letivo', $periodoLetivo)
            ->get(['aluno_id', 'status', 'periodo', 'turma'])
            ->filter(fn ($m) => AlunoMatricula::vigenteNoPeriodo($m->status))
            ->mapWithKeys(fn ($m) => [(int) $m->aluno_id => ['periodo' => $m->periodo, 'turma' => $m->turma]])
            ->all();
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array{periodo: ?string, turma: ?string}> id da matrícula => dados
     */
    private function matriculasPorId(array $ids): array
    {
        $mapa = [];
        foreach (array_chunk($ids, 500) as $lote) {
            foreach (DB::table('aluno_matriculas')->whereIn('id', $lote)->get(['id', 'periodo', 'turma']) as $m) {
                $mapa[(int) $m->id] = ['periodo' => $m->periodo, 'turma' => $m->turma];
            }
        }

        return $mapa;
    }

    /**
     * @param  array<int, array{matricula: ?int}>  $avals
     * @param  array<int, array{periodo: ?string, turma: ?string}>  $matriculas
     * @return ?array{periodo: ?string, turma: ?string}
     */
    private function matriculaDaUltimaProva(array $avals, Collection $porCodigo, array $matriculas): ?array
    {
        $melhor = null;
        $ordemMelhor = '';
        foreach ($avals as $codigo => $a) {
            if ($a['matricula'] === null || ! isset($matriculas[$a['matricula']])) {
                continue;
            }
            $ordem = ($porCodigo[$codigo]['data'] ?? '0000-00-00').'|'.$codigo;
            if ($melhor === null || $ordem > $ordemMelhor) {
                $melhor = $matriculas[$a['matricula']];
                $ordemMelhor = $ordem;
            }
        }

        return $melhor;
    }

    /**
     * Média e nº de presentes do curso em cada avaliação do aluno + a posição dele (1 = melhor nota; empate divide
     * a posição). Duas consultas agregadas, qualquer que seja o tamanho do curso.
     *
     * @param  array<int, array{pc: ?float, ausente: bool}>  $avals
     * @param  array<int, string>  $variantes
     * @return array<int, array{media: ?float, presentes: int, posicoes: array<int, int>}>
     */
    private function estatisticasDoCurso(array $avals, array $variantes): array
    {
        if ($avals === []) {
            return [];
        }

        $codigos = array_keys($avals);

        $base = fn () => DB::table('resultado_resumos')->whereIn('avaliacao_codigo', $codigos)->whereIn('curso', $variantes);

        $estatisticas = [];
        $base()->groupBy('avaliacao_codigo')
            ->selectRaw('avaliacao_codigo as codigo')
            ->selectRaw('SUM(CASE WHEN ausente = 0 THEN 1 ELSE 0 END) as presentes')
            ->selectRaw('SUM(CASE WHEN ausente = 0 AND percentual IS NOT NULL THEN percentual ELSE 0 END) as soma')
            ->selectRaw('SUM(CASE WHEN ausente = 0 AND percentual IS NOT NULL THEN 1 ELSE 0 END) as n')
            ->get()
            ->each(function ($l) use (&$estatisticas) {
                $estatisticas[(int) $l->codigo] = [
                    'media' => (int) $l->n > 0 ? round((float) $l->soma / (int) $l->n, 1) : null,
                    'presentes' => (int) $l->presentes,
                    'posicoes' => [],
                ];
            });

        // Quantos tiraram nota MAIOR que a do aluno, em cada avaliação — tudo numa passada só.
        $comNota = array_filter($avals, fn ($a) => ! $a['ausente'] && $a['pc'] !== null);
        if ($comNota !== []) {
            $consulta = $base();
            $indice = [];
            foreach ($comNota as $codigo => $a) {
                $apelido = 'acima_'.count($indice);
                $indice[$apelido] = $codigo;
                $consulta->selectRaw("SUM(CASE WHEN avaliacao_codigo = ? AND ausente = 0 AND percentual > ? THEN 1 ELSE 0 END) as {$apelido}", [$codigo, $a['pc']]);
            }
            $linha = (array) $consulta->first();
            foreach ($indice as $apelido => $codigo) {
                if (isset($estatisticas[$codigo])) {
                    $estatisticas[$codigo]['posicoes'][$codigo] = (int) ($linha[$apelido] ?? 0) + 1;
                }
            }
        }

        return $estatisticas;
    }

    /**
     * % de acerto do aluno por área, nas avaliações informadas (mesma regra de anulação do resto do sistema).
     *
     * @param  array<int|string, string>  $chaves  aluno_chave do aluno
     * @param  array<int, int>  $codigos
     * @return array<int, array{area: string, percentual: float, respostas: int}> da mais fraca para a mais forte
     */
    private function desempenhoDoAlunoPorArea(array $chaves, array $codigos): array
    {
        if ($chaves === [] || $codigos === []) {
            return [];
        }

        return DB::table('respostas as r')
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
            ->whereIn('r.avaliacao_codigo', $codigos)
            ->whereIn('r.aluno_chave', $chaves)
            ->whereNull('r.deleted_at')
            ->groupBy('q.area')
            ->selectRaw('q.area as area, COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN '.Anulacao::condicaoAcertoSql('r.resposta', 'q.gabarito', 'q.anulada_modo').' THEN 1 ELSE 0 END) as acertos')
            ->get()
            ->filter(fn ($l) => (int) $l->total >= self::MINIMO_RESPOSTAS_AREA_ALUNO)
            ->map(fn ($l) => [
                'area' => (string) $l->area,
                'percentual' => round((int) $l->acertos / (int) $l->total * 100, 1),
                'respostas' => (int) $l->total,
            ])
            ->sortBy('percentual')
            ->values()
            ->all();
    }

    // ------------------------------------------------------------------------------------------------------------

    private static function pct(?float $valor): string
    {
        return $valor === null ? '—' : rtrim(rtrim(number_format($valor, 1, ',', ''), '0'), ',');
    }

    /** Sem acento, minúsculo, espaços colapsados — para busca por nome. */
    private static function normalizar(string $texto): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', Str::ascii($texto))));
    }
}
