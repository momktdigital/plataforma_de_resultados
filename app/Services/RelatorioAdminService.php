<?php

namespace App\Services;

use App\Models\Avaliacao;
use App\Models\Resposta;
use App\Support\AlunoVinculoResolver;
use App\Support\Anulacao;
use App\Support\CacheDeAnalise;
use App\Support\Concerns\ComEscopoDeCurso;
use App\Support\Dificuldade;
use App\Support\FiltroDemografico;
use App\Support\NomeCurso;
use App\Support\PeriodoCurso;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Análises administrativas adicionais por avaliação, complementares ao
 * histograma/radar de BiDashboardService e às questões críticas de
 * EstatisticaErroService — cada método aqui só é chamado pelo controller
 * quando o visual correspondente está habilitado E disponível (ver
 * App\Services\Visualizacoes\VisualizacaoConfigService), então nenhum deles
 * verifica de novo se há dados suficientes.
 *
 * Toda agregação sobre `respostas`/`resultado_resumos` é feita em SQL (join +
 * GROUP BY), no mesmo espírito de BiDashboardService, para não trazer a
 * tabela inteira pra uma Collection do PHP. O vínculo com `alunos` (turma,
 * dados demográficos) nunca é um JOIN direto contra essas tabelas grandes —
 * ver App\Support\AlunoVinculoResolver para o porquê.
 */
class RelatorioAdminService
{
    use ComEscopoDeCurso;

    /** @var array<string, Collection<int, array<string, mixed>>> memo das linhas da evolução, por avaliação e escopo (vive só na requisição) */
    private array $evolucaoMemo = [];

    /**
     * Contagem de respostas por (questão, resposta) memorizada por avaliação/período/filtro/escopo: todo visual que
     * agrega por questão (área, tema, bloom, miller, dificuldade, alternativas) sai da MESMA varredura de `respostas`.
     *
     * @var array<string, Collection<int, object>>
     */
    private array $contagensMemo = [];

    /** @var array<int, Collection<int, object>> */
    private array $questoesMemo = [];

    /**
     * Rótulo de exibição de cada `tipo` de App\Models\QuestaoReferencia — a
     * ordem aqui é a ordem em que os blocos aparecem na tela. Os tipos são
     * gravados por QuestaoImportService (ver REFERENCIA_LETRAS lá).
     */
    private const ROTULOS_REFERENCIA = [
        'dcn' => 'DCN — Diretrizes Curriculares Nacionais',
        'ppc' => 'PPC — Projeto Pedagógico do Curso',
        'portaria_inep' => 'Portaria INEP',
        'matriz_prova' => 'Matriz da prova',
    ];

    /**
     * Piso de respondentes para um grupo demográfico aparecer na análise de
     * equidade. Abaixo disso a "média do grupo" vira dado individual.
     */
    private const MINIMO_POR_GRUPO = 10;

    public function __construct(
        private readonly AlunoVinculoResolver $alunoResolver = new AlunoVinculoResolver,
    ) {}

    /**
     * Lista nominal dos respondentes da avaliação (tela "Alunos da avaliação"
     * e a planilha baixada dela — ver ListaAlunosExportService). Ordenada pelo
     * percentual; ausentes (prova inteira em branco) vão pro fim, marcados.
     *
     * `periodo_curso` é o período do aluno no curso (1º, 2º... da matrícula;
     * na falta dele, o que veio na planilha de resultados); `periodo` continua
     * sendo o texto cru da planilha de resultados.
     *
     * Com $limite devolve só uma página (a tela carrega o resto sob demanda — uma lista de milhares de linhas com
     * foto não cabe numa página); sem $limite devolve todos (planilha). A ordem é total e estável: presentes antes
     * dos ausentes, maior percentual primeiro e, no empate, pela chave do aluno.
     *
     * Com `$comEsperadas`, cada linha traz também `esperadas`: quantas questões o aluno acertou DENTRO DO ESPERADO — entre
     * as questões que ele precisava acertar pelo período em que estava (as marcadas para um período à frente, meta
     * `questoes.periodo_minimo`, ficam de fora) — e quantas eram. null quando a avaliação não define a meta por período
     * (aí todas as questões valem para todos e o número seria igual ao total) ou o aluno não tem nota.
     *
     * @return array<int, array{ra: ?string, cpf: ?string, periodo: string, periodo_curso: ?string, acertos: int, total: int, percentual: ?float, aluno_nome: ?string, turma: ?string, curso: ?string, foto: ?string, ausente: bool, esperadas: ?array{acertos: int, total: int}}>
     */
    public function rankingCompleto(Avaliacao $avaliacao, string $periodo = '', ?int $limite = null, int $inicio = 0, bool $comEsperadas = false): array
    {
        $resumos = $this->escoparResumos(DB::table('resultado_resumos'))
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->when($periodo !== '', fn ($q) => $q->where('periodo', $periodo))
            ->orderBy('ausente')
            ->orderByDesc('percentual')
            ->orderBy('aluno_chave')
            ->orderBy('periodo')
            ->when($limite !== null, fn ($q) => $q->offset($inicio)->limit($limite))
            ->select('aluno_chave', 'ra', 'cpf', 'periodo', 'acertos', 'total', 'percentual', 'curso', 'matricula_id', 'ausente')
            ->get();

        // Período do curso na época da prova (da matrícula que valia nela).
        $periodosDaMatricula = DB::table('aluno_matriculas')
            ->whereIn('id', $resumos->pluck('matricula_id')->filter()->unique()->all())
            ->pluck('periodo', 'id');

        $alunos = $this->dentroDoEscopo($this->alunoResolver->resolver($avaliacao->codigo, $periodo), $avaliacao->codigo);
        $esperadas = $comEsperadas ? $this->acertosDentroDoEsperado($avaliacao, $resumos) : [];

        return $resumos->map(function ($r) use ($alunos, $periodosDaMatricula, $esperadas) {
            $aluno = $alunos->get($r->aluno_chave);

            return [
                'ra' => $r->ra,
                'cpf' => $r->cpf,
                'periodo' => $r->periodo,
                'periodo_curso' => ($r->matricula_id ? $periodosDaMatricula->get($r->matricula_id) : null) ?: $aluno?->periodo ?: ($r->periodo !== '' ? $r->periodo : null),
                'acertos' => (int) $r->acertos,
                'total' => (int) $r->total,
                'percentual' => $r->percentual !== null ? (float) $r->percentual : null,
                'aluno_nome' => $aluno?->nome,
                'turma' => $aluno?->turma,
                'curso' => $r->curso ?: $aluno?->curso,
                'foto' => $aluno?->fotoUrl(96),
                // Ausente = nenhuma resposta de verdade na prova inteira (gravado em resultado_resumos.ausente).
                'ausente' => (bool) $r->ausente,
                'esperadas' => $esperadas[$r->aluno_chave.'|'.$r->periodo] ?? null,
            ];
        })
            ->values()
            ->all();
    }

    /** A avaliação define o período mínimo em alguma questão válida? (sem isso não há "dentro do esperado" por aluno) */
    public function avaliacaoTemMetaPorPeriodo(Avaliacao $avaliacao): bool
    {
        return DB::table('questoes')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->whereNull('deleted_at')
            ->whereNotNull('periodo_minimo')
            ->exists();
    }

    /**
     * Acertos de cada aluno nas questões que ele precisava acertar pelo período dele. Agrega em SQL por
     * (aluno, período, meta da questão) — poucas linhas por aluno — e aplica o período do aluno em PHP.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $resumos  linhas de resultado_resumos (aluno_chave, periodo, percentual...)
     * @return array<string, array{acertos: int, total: int}>  "aluno_chave|periodo" => acertos dentro do esperado
     */
    private function acertosDentroDoEsperado(Avaliacao $avaliacao, $resumos): array
    {
        if ($resumos->isEmpty() || ! $this->avaliacaoTemMetaPorPeriodo($avaliacao)) {
            return [];
        }

        $resultado = [];

        foreach ($resumos->pluck('aluno_chave')->unique()->chunk(1000) as $lote) {
            $linhas = DB::table('respostas as r')
                ->join('questoes as q', function ($join) {
                    Anulacao::excluirDistribuidas(
                        $join->on('q.numero', '=', 'r.questao_numero')
                            ->on('q.avaliacao_codigo', '=', 'r.avaliacao_codigo')
                            ->whereNull('q.deleted_at')
                            ->whereNotNull('q.gabarito')->where('q.gabarito', '!=', ''),
                        'q.anulada_modo',
                    );
                })
                ->where('r.avaliacao_codigo', $avaliacao->codigo)
                ->whereNull('r.deleted_at')
                ->whereIn('r.aluno_chave', $lote->all())
                ->groupBy('r.aluno_chave', 'r.periodo', 'q.periodo_minimo')
                ->selectRaw('r.aluno_chave as chave, r.periodo as periodo, q.periodo_minimo as minimo, COUNT(*) as total')
                ->selectRaw('SUM(CASE WHEN '.Anulacao::condicaoAcertoSql('r.resposta', 'q.gabarito', 'q.anulada_modo').' THEN 1 ELSE 0 END) as acertos')
                ->get();

            foreach ($linhas as $l) {
                $ordinal = PeriodoCurso::ordinal((string) $l->periodo);
                if (PeriodoCurso::aFrente($ordinal, $l->minimo !== null ? (int) $l->minimo : null)) {
                    continue;
                }

                $chave = $l->chave.'|'.$l->periodo;
                $resultado[$chave]['acertos'] = ($resultado[$chave]['acertos'] ?? 0) + (int) $l->acertos;
                $resultado[$chave]['total'] = ($resultado[$chave]['total'] ?? 0) + (int) $l->total;
            }
        }

        return $resultado;
    }

    /**
     * Quantos respondentes tem a lista nominal e o percentual de cada um (leve: uma coluna de `resultado_resumos`)
     * — o título da lista e o texto explicativo do visual precisam do conjunto inteiro, mesmo paginado.
     *
     * @return array{total: int, percentuais: array<int, ?float>}
     */
    public function resumoDoRanking(Avaliacao $avaliacao, string $periodo = ''): array
    {
        $percentuais = $this->escoparResumos(DB::table('resultado_resumos'))
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->when($periodo !== '', fn ($q) => $q->where('periodo', $periodo))
            ->pluck('percentual')
            ->map(fn ($p) => $p !== null ? (float) $p : null)
            ->all();

        return ['total' => count($percentuais), 'percentuais' => $percentuais];
    }

    /**
     * $filtro nunca restringe por turma aqui — turma já É a dimensão de
     * agrupamento deste visual (ver FiltroDemografico::semTurma()).
     *
     * @return array<int, array{turma: string, respondentes: int, media: float, minimo: float, maximo: float}>
     */
    public function distribuicaoPorTurma(Avaliacao $avaliacao, string $periodo = '', ?FiltroDemografico $filtro = null): array
    {
        $resumos = $this->escoparResumos(DB::table('resultado_resumos'))
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->when($periodo !== '', fn ($q) => $q->where('periodo', $periodo))
            ->whereNotNull('percentual')
            ->select('aluno_chave', 'percentual')
            ->get();

        $alunos = $this->dentroDoEscopo($this->alunoResolver->resolver($avaliacao->codigo, $periodo), $avaliacao->codigo);
        $chaves = $filtro !== null
            ? $this->alunoResolver->chavesFiltradas($avaliacao->codigo, $periodo, $filtro->semTurma(), $avaliacao->data_avaliacao)
            : null;

        $porTurma = [];
        foreach ($resumos as $r) {
            if ($chaves !== null && ! $chaves->contains($r->aluno_chave)) {
                continue;
            }

            $turma = $alunos->get($r->aluno_chave)?->turma;
            if (empty($turma)) {
                continue;
            }
            $porTurma[$turma][] = (float) $r->percentual;
        }

        $resultado = [];
        foreach ($porTurma as $turma => $percentuais) {
            $resultado[] = [
                'turma' => $turma,
                'respondentes' => count($percentuais),
                'media' => round(array_sum($percentuais) / count($percentuais), 1),
                'minimo' => round(min($percentuais), 1),
                'maximo' => round(max($percentuais), 1),
            ];
        }

        usort($resultado, fn ($a, $b) => $b['media'] <=> $a['media']);

        return $resultado;
    }

    /**
     * `meta`/`desvio`/`atingiu` só vêm preenchidos quando a avaliação tem meta
     * de % de acerto cadastrada pra aquele nível (Avaliacao::meta_acerto_dificuldade);
     * desvio = observado − meta, em pontos percentuais.
     *
     * @return array<string, array{esperado: string, observado: ?float, questoes: int, meta: ?float, desvio: ?float, atingiu: ?bool}> ver App\Support\Dificuldade
     */
    public function curvaDificuldade(Avaliacao $avaliacao): array
    {
        $porQuestao = $this->acertosPorQuestaoComCampo($avaliacao, 'dificuldade_pedagogica');

        $ordem = Dificuldade::rotulos();
        $acumulado = [];

        foreach ($porQuestao as $linha) {
            $chave = $linha->campo;
            if (! isset($ordem[$chave])) {
                continue;
            }
            // A consulta já agrupa por nível: cada linha É um nível, então o
            // nº de questões vem do COUNT(DISTINCT) dela, não de contar linhas.
            $acumulado[$chave] ??= ['acertos' => 0, 'total' => 0, 'questoes' => 0];
            $acumulado[$chave]['acertos'] += (int) $linha->acertos;
            $acumulado[$chave]['total'] += (int) $linha->total;
            $acumulado[$chave]['questoes'] += (int) $linha->questoes;
        }

        $resultado = [];
        foreach ($ordem as $chave => $label) {
            // Todos os níveis aparecem (mesmo sem questões) pra deixar claro
            // que a avaliação não tem nada cadastrado naquele nível.
            $s = $acumulado[$chave] ?? ['acertos' => 0, 'total' => 0, 'questoes' => 0];
            $observado = $s['total'] > 0 ? round($s['acertos'] / $s['total'] * 100, 1) : null;
            $meta = $avaliacao->metaAcertoDificuldade($chave);
            $resultado[$chave] = [
                'esperado' => $label,
                'observado' => $observado,
                'questoes' => $s['questoes'],
                'meta' => $meta,
                'desvio' => $meta === null || $observado === null ? null : round($observado - $meta, 1),
                'atingiu' => $meta === null || $observado === null ? null : $observado >= $meta,
            ];
        }

        return $resultado;
    }

    /** @return array<int, array{numero: int, dificuldade_tri: float, taxa_acerto: float}> */
    public function dispersaoTri(Avaliacao $avaliacao): array
    {
        $porQuestao = Anulacao::excluirDistribuidas(
            DB::table('questoes')
                ->where('avaliacao_codigo', $avaliacao->codigo)
                ->whereNull('deleted_at')
                ->whereNotNull('gabarito')
                ->where('gabarito', '!=', '')
                ->whereNotNull('dificuldade_tri')
        )->select('numero', 'dificuldade_tri')
            ->get()
            ->keyBy('numero');

        if ($porQuestao->isEmpty()) {
            return [];
        }

        $stats = $this->escopar(DB::table('respostas as r'), 'r.', $avaliacao->codigo)
            ->join('questoes as q', function ($join) use ($avaliacao) {
                $join->on('q.numero', '=', 'r.questao_numero')
                    ->where('q.avaliacao_codigo', $avaliacao->codigo)
                    ->whereNull('q.deleted_at');
            })
            ->where('r.avaliacao_codigo', $avaliacao->codigo)
            ->whereNull('r.deleted_at')
            ->whereIn('r.questao_numero', $porQuestao->keys())
            ->groupBy('r.questao_numero')
            ->selectRaw('r.questao_numero as numero')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN '.Anulacao::condicaoAcertoSql('r.resposta', 'q.gabarito', 'q.anulada_modo').' THEN 1 ELSE 0 END) as acertos')
            ->get()
            ->keyBy('numero');

        $resultado = [];
        foreach ($porQuestao as $numero => $questao) {
            $stat = $stats->get($numero);
            if ($stat === null || (int) $stat->total === 0) {
                continue;
            }

            $resultado[] = [
                'numero' => (int) $numero,
                'dificuldade_tri' => (float) $questao->dificuldade_tri,
                'taxa_acerto' => round((int) $stat->acertos / (int) $stat->total * 100, 1),
            ];
        }

        return $resultado;
    }

    /**
     * [habilidade => [turma => %acerto]]. A agregação por (aluno, habilidade) é
     * feita inteira em SQL sobre `respostas` (sem JOIN com `alunos` — ver
     * App\Support\AlunoVinculoResolver); a turma de cada aluno_chave é resolvida
     * à parte (tabela pequena) e só então cruzada em PHP, porque o Nº de pares
     * (aluno_chave, habilidade) de uma avaliação é sempre pequeno (nº de
     * respondentes × nº de habilidades), mesmo quando `respostas` tem
     * centenas de milhares de linhas.
     *
     * $filtro nunca restringe por turma aqui — turma já É a dimensão de
     * agrupamento deste visual (ver FiltroDemografico::semTurma()).
     *
     * @return array<string, array<string, float>>
     */
    public function heatmapHabilidadeTurma(Avaliacao $avaliacao, string $periodo = '', ?FiltroDemografico $filtro = null): array
    {
        $chaves = $filtro !== null
            ? $this->alunoResolver->chavesFiltradas($avaliacao->codigo, $periodo, $filtro->semTurma(), $avaliacao->data_avaliacao)
            : null;

        $porAlunoHabilidade = $this->escopar(DB::table('respostas as r'), 'r.', $avaliacao->codigo)
            ->join('questoes as q', function ($join) use ($avaliacao) {
                Anulacao::excluirDistribuidas(
                    $join->on('q.numero', '=', 'r.questao_numero')
                        ->where('q.avaliacao_codigo', $avaliacao->codigo)
                        ->whereNull('q.deleted_at')
                        ->whereNotNull('q.gabarito')
                        ->where('q.gabarito', '!=', '')
                        ->whereNotNull('q.habilidade')
                        ->where('q.habilidade', '!=', ''),
                    'q.anulada_modo',
                );
            })
            ->where('r.avaliacao_codigo', $avaliacao->codigo)
            ->whereNull('r.deleted_at')
            ->when($periodo !== '', fn ($q) => $q->where('r.periodo', $periodo))
            ->when($chaves !== null, fn ($q) => $q->whereIn('r.aluno_chave', $chaves))
            ->groupBy('r.aluno_chave', 'q.habilidade')
            ->selectRaw('r.aluno_chave as aluno_chave, q.habilidade as habilidade')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN '.Anulacao::condicaoAcertoSql('r.resposta', 'q.gabarito', 'q.anulada_modo').' THEN 1 ELSE 0 END) as acertos')
            ->get();

        if ($porAlunoHabilidade->isEmpty()) {
            return [];
        }

        $alunos = $this->dentroDoEscopo($this->alunoResolver->resolver($avaliacao->codigo, $periodo), $avaliacao->codigo);

        $acumulado = [];
        foreach ($porAlunoHabilidade as $linha) {
            $turma = $alunos->get($linha->aluno_chave)?->turma;
            if (empty($turma)) {
                continue;
            }

            $acumulado[$linha->habilidade][$turma] ??= ['acertos' => 0, 'total' => 0];
            $acumulado[$linha->habilidade][$turma]['acertos'] += (int) $linha->acertos;
            $acumulado[$linha->habilidade][$turma]['total'] += (int) $linha->total;
        }

        $matriz = [];
        foreach ($acumulado as $habilidade => $porTurma) {
            foreach ($porTurma as $turma => $s) {
                $matriz[$habilidade][$turma] = $s['total'] > 0 ? round($s['acertos'] / $s['total'] * 100, 1) : 0.0;
            }
        }

        return $matriz;
    }

    /** @return array{sexo: array<string, int>, cor_raca: array<string, int>, forma_ingresso: array<string, int>, uf: array<string, int>} */
    public function perfilDemografico(Avaliacao $avaliacao): array
    {
        $alunos = $this->dentroDoEscopo($this->alunoResolver->resolver($avaliacao->codigo), $avaliacao->codigo);

        $contarPor = fn (string $campo) => $alunos
            ->map(fn ($a) => $a->{$campo})
            ->filter(fn ($v) => ! empty($v))
            ->countBy()
            ->sortDesc()
            ->all();

        return [
            'sexo' => $contarPor('sexo'),
            'cor_raca' => $contarPor('cor_raca'),
            'uf' => $contarPor('uf'),
            'forma_ingresso' => $contarPor('forma_ingresso'),
        ];
    }

    /**
     * Uma linha por questão (não mais uma coluna por questão): faz mais
     * sentido quando a avaliação tem muito mais questões que alternativas
     * possíveis. Ordenado por % de acerto ascendente — as questões mais
     * problemáticas aparecem primeiro, é o que mais interessa pra quem está
     * revisando a prova. Cada alternativa errada marca 'ehDistrator' quando é
     * a mais escolhida entre as erradas — o "distrator" que mais confundiu
     * os respondentes.
     *
     * O % de acerto e a distribuição aqui são sempre os valores BRUTOS (sem
     * aplicar a regra de anulação) — o propósito deste visual é diagnóstico
     * (o que os respondentes realmente marcaram), então anular a questão não
     * deve maquiar esse número; só marca 'anulada' pra avisar que ela não
     * conta mais na nota (ver App\Support\Anulacao).
     *
     * @return array<int, array{numero: int, area: ?string, tema: ?string, gabarito: ?string, anulada: bool, totalRespostas: int, percentualAcerto: float, alternativas: array<int, array{letra: string, total: int, percentual: float, ehGabarito: bool, ehDistrator: bool}>}>
     */
    public function analiseAlternativas(Avaliacao $avaliacao, string $periodo = '', ?FiltroDemografico $filtro = null): array
    {
        $questoes = $this->questoesDaAvaliacao($avaliacao);

        $chaves = $filtro !== null
            ? $this->alunoResolver->chavesFiltradas($avaliacao->codigo, $periodo, $filtro, $avaliacao->data_avaliacao)
            : null;

        $linhas = $this->contagensPorQuestao($avaliacao, $periodo, $chaves);

        if ($linhas->isEmpty()) {
            return [];
        }

        $contagensPorQuestao = [];
        foreach ($linhas as $linha) {
            // NULL, '' e espaços viram o mesmo "—" (era o COALESCE(NULLIF(resposta, ''), '—') da consulta).
            $alternativa = $linha['resposta'] === null || rtrim((string) $linha['resposta'], ' ') === '' ? '—' : (string) $linha['resposta'];
            $contagensPorQuestao[(int) $linha['numero']][$alternativa] = (int) $linha['total'];
        }

        $resultado = [];
        foreach ($contagensPorQuestao as $numero => $contagens) {
            $questao = $questoes->get($numero);
            $gabarito = $questao?->gabarito;
            $totalRespostas = array_sum($contagens);
            $acertos = ($gabarito !== null && $gabarito !== '') ? ($contagens[$gabarito] ?? 0) : 0;

            // '—' é o placeholder sintético desta query pra NULL/'' (via
            // COALESCE acima) — mas a maioria das importações reais nunca
            // grava NULL/'': usa sentinelas próprias ("BLANK", "-") que
            // passam direto pelo COALESCE sem cair nele. Nenhuma das duas
            // é uma alternativa de verdade, então nenhuma pode ser
            // "distrator" — ver Resposta::ehSemResposta().
            $semResposta = fn (string $alternativa) => $alternativa === '—' || Resposta::ehSemResposta($alternativa);

            // "Distrator" só faz sentido quando a alternativa errada mais
            // marcada supera até o próprio gabarito em popularidade — se o
            // gabarito já é a mais marcada (a maioria acertou), não há uma
            // alternativa "roubando" respostas de verdade, então nenhuma
            // fica destacada.
            $maisMarcada = collect($contagens)
                ->reject(fn ($total, $alternativa) => $semResposta($alternativa) || $total === 0)
                ->sortDesc()
                ->keys()
                ->first();
            $distrator = ($maisMarcada !== null && $maisMarcada !== $gabarito) ? $maisMarcada : null;

            $alternativas = collect($contagens)
                ->map(fn ($total, $alternativa) => [
                    'letra' => $alternativa,
                    'total' => $total,
                    'percentual' => $totalRespostas > 0 ? round($total / $totalRespostas * 100, 1) : 0.0,
                    'ehGabarito' => $gabarito !== null && $gabarito !== '' && $alternativa === $gabarito,
                    'ehDistrator' => $alternativa === $distrator,
                ])
                ->sortBy(fn ($a) => $semResposta($a['letra']) ? 'zzzzzzzz'.$a['letra'] : $a['letra'])
                ->values()
                ->all();

            $resultado[] = [
                'numero' => $numero,
                'area' => $questao?->area,
                'tema' => $questao?->tema,
                'gabarito' => $gabarito,
                'anulada' => $questao?->anulada_modo !== null,
                'totalRespostas' => $totalRespostas,
                'percentualAcerto' => $totalRespostas > 0 ? round($acertos / $totalRespostas * 100, 1) : 0.0,
                'alternativas' => $alternativas,
            ];
        }

        usort($resultado, fn ($a, $b) => $a['percentualAcerto'] <=> $b['percentualAcerto']);

        return $resultado;
    }

    /** @return array<string, float> */
    public function mediaPorArea(Avaliacao $avaliacao, string $periodo = ''): array
    {
        return $this->mediaPorCampoDireto($avaliacao, 'area', $periodo);
    }

    /**
     * Diferente de mediaPorArea() (uma média só por área), aqui cada linha é
     * um tema — a área aparece como legenda/subtítulo, já que um tema
     * pertence a uma única área. Ordenado por % de acerto ascendente pro
     * mesmo motivo de analiseAlternativas(): a view separa os primeiros
     * (menor acerto) dos últimos (maior acerto) da lista.
     *
     * @return array<int, array{area: ?string, tema: string, totalQuestoes: int, percentual: float}>
     */
    public function desempenhoPorTema(Avaliacao $avaliacao, string $periodo = ''): array
    {
        // (área, tema) → respostas/acertos, somados das questões elegíveis (com gabarito, fora as de distribuição de
        // pontuação, com tema) a partir da varredura compartilhada. Agrupa como o banco agrupava: sem diferenciar
        // maiúsculas/acentos, e na ordem da chave — a ordem do empate no usort() abaixo depende dela.
        $questoes = $this->questoesDaAvaliacao($avaliacao);
        $grupos = [];
        foreach ($this->totaisPorQuestao($avaliacao, $periodo) as $numero => $t) {
            $q = $questoes->get($numero);
            if ($q === null || ! $this->questaoContaNaNota($q) || $q->tema === null || $q->tema === '') {
                continue;
            }

            $chave = NomeCurso::chave($q->area).'|'.NomeCurso::chave($q->tema);
            $grupos[$chave] ??= (object) ['area' => $q->area, 'tema' => $q->tema, 'total_questoes' => 0, 'total' => 0, 'acertos' => 0];
            $grupos[$chave]->total_questoes++;
            $grupos[$chave]->total += $t['total'];
            $grupos[$chave]->acertos += $t['acertos'];
        }
        uasort($grupos, fn ($x, $y) => [NomeCurso::chave($x->area), NomeCurso::chave($x->tema)] <=> [NomeCurso::chave($y->area), NomeCurso::chave($y->tema)]);
        $linhas = collect(array_values($grupos));

        $resultado = $linhas->map(fn ($l) => [
            'area' => $l->area,
            'tema' => $l->tema,
            'totalQuestoes' => (int) $l->total_questoes,
            'percentual' => (int) $l->total > 0 ? round((int) $l->acertos / (int) $l->total * 100, 1) : 0.0,
        ])->all();

        usort($resultado, fn ($a, $b) => $a['percentual'] <=> $b['percentual']);

        return $resultado;
    }

    /** @return array<int, array{nome_metrica: string, n: int, correlacao: ?float}> */
    public function correlacaoMetricas(Avaliacao $avaliacao, string $periodo = '', ?FiltroDemografico $filtro = null): array
    {
        $chaves = $filtro !== null
            ? $this->alunoResolver->chavesFiltradas($avaliacao->codigo, $periodo, $filtro, $avaliacao->data_avaliacao)
            : null;

        $percentuais = $this->escoparResumos(DB::table('resultado_resumos'))
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->when($periodo !== '', fn ($q) => $q->where('periodo', $periodo))
            ->when($chaves !== null, fn ($q) => $q->whereIn('aluno_chave', $chaves))
            ->whereNotNull('percentual')
            ->pluck('percentual', 'aluno_chave');

        if ($percentuais->isEmpty()) {
            return [];
        }

        $metricas = $this->escopar(DB::table('resultado_metricas'), 'resultado_metricas.', $avaliacao->codigo)
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->whereNull('deleted_at')
            ->when($periodo !== '', fn ($q) => $q->where('periodo', $periodo))
            ->when($chaves !== null, fn ($q) => $q->whereIn('aluno_chave', $chaves))
            ->select('nome_metrica', 'aluno_chave', 'valor')
            ->get()
            ->groupBy('nome_metrica');

        $resultado = [];
        foreach ($metricas as $nomeMetrica => $linhas) {
            $pares = [];
            foreach ($linhas as $linha) {
                // Casa por aluno_chave (coluna gerada COALESCE(cpf, ra) da
                // LINHA importada) em vez de recalcular "cpf ?: ra" aqui —
                // mesma armadilha já corrigida em
                // RelatorioAlunoService::rankingPercentil().
                $percentual = $percentuais->get($linha->aluno_chave);
                if ($percentual === null || ! is_numeric($linha->valor)) {
                    continue;
                }
                $pares[] = [(float) $percentual, (float) $linha->valor];
            }

            $resultado[] = [
                'nome_metrica' => $nomeMetrica,
                'n' => count($pares),
                'correlacao' => count($pares) >= 2 ? $this->correlacaoPearson($pares) : null,
            ];
        }

        return $resultado;
    }

    /**
     * Evolução da média ao longo das avaliações da MESMA categoria, com todos os
     * respondentes ("avaliação inteira"). Cada ponto traz a média com e sem os
     * ausentes (prova inteira em branco), para a tela alternar sem recarregar.
     *
     * @return array<int, array{codigo: int, nome: string, data: ?string, media: float, mediaPresentes: ?float, respondentes: int, presentes: int}>
     */
    public function evolucaoCategoria(Avaliacao $avaliacao): array
    {
        [$linhas] = $this->linhasDaEvolucao($avaliacao);

        return $this->pontosDaEvolucao($linhas);
    }

    /**
     * A mesma evolução, aberta POR PERÍODO do curso e, dentro dele, por turma:
     * para cada período (1º, 2º... 12º), uma série por turma — a média dos
     * alunos que estavam naquela turma EM CADA avaliação (período e turma da
     * época da prova, vindos da matrícula que valia nela; ver
     * CursoDoResultadoService). Valor de período que não é um período do curso
     * ("2026/1" digitado na coluna errada) fica de fora.
     *
     * @return array<int, array{rotulo: string, turmas: array<string, array<int, array{codigo: int, nome: string, data: ?string, media: float, mediaPresentes: ?float, respondentes: int, presentes: int}>>}> chaveado pelo nº do período
     */
    public function evolucaoCategoriaPorPeriodo(Avaliacao $avaliacao): array
    {
        [$linhas] = $this->linhasDaEvolucao($avaliacao);

        $resultado = [];
        foreach ($linhas->groupBy(fn ($l) => $l['periodoOrdinal'] ?? 0) as $ordinal => $doPeriodo) {
            if ($ordinal === 0) {
                continue;
            }

            $turmas = $doPeriodo->groupBy('turma')->map(fn ($doTurma) => $this->pontosDaEvolucao($doTurma))->sortKeys(SORT_NATURAL | SORT_FLAG_CASE)->all();
            $resultado[(int) $ordinal] = ['rotulo' => PeriodoCurso::rotulo((int) $ordinal), 'turmas' => $turmas];
        }
        ksort($resultado);

        return $resultado;
    }

    /**
     * Uma linha por resultado das avaliações da categoria (poucos milhares:
     * nº de respondentes × nº de avaliações da categoria), já com período e
     * turma da época da prova e a marca de ausente. Memorizado por
     * avaliação: o visual pede duas vezes (inteira e por período).
     *
     * @return array{0: Collection<int, array<string, mixed>>}
     */
    private function linhasDaEvolucao(Avaliacao $avaliacao): array
    {
        if ($avaliacao->categoria_id === null) {
            return [collect()];
        }

        // A chave inclui o escopo: uma cópia paraCursos() herda o memo da original
        // e não pode reaproveitar linhas de outro conjunto de cursos.
        $chaveMemo = $avaliacao->codigo.'|'.($this->escopo === null ? '*' : implode(',', $this->escopo->cursos));
        if (isset($this->evolucaoMemo[$chaveMemo])) {
            return [$this->evolucaoMemo[$chaveMemo]];
        }

        $brutas = DB::table('avaliacoes as av')
            ->join('resultado_resumos as rr', 'rr.avaliacao_codigo', '=', 'av.codigo')
            // Joins por id (INT): nunca comparam colunas de collations diferentes.
            ->leftJoin('aluno_matriculas as m', 'm.id', '=', 'rr.matricula_id')
            ->leftJoin('alunos as a', 'a.id', '=', 'rr.aluno_id')
            ->where('av.categoria_id', $avaliacao->categoria_id)
            ->whereNull('av.deleted_at')
            // Prova inteira anulada não entra na evolução.
            ->where(fn ($q) => $q->whereNull('av.status')->orWhere('av.status', '!=', Avaliacao::STATUS_ANULADA))
            ->whereNotNull('rr.percentual')
            ->when($this->escopo !== null, fn ($q) => $this->escopo->restringirResumos($q, 'rr.curso'))
            ->get([
                'av.codigo as codigo', 'av.nome as nome', 'av.data_avaliacao as data',
                'rr.aluno_chave as chave', 'rr.percentual as percentual', 'rr.ausente as ausente',
                'rr.periodo as periodo_resumo', 'm.periodo as periodo_matricula', 'm.turma as turma_matricula',
                'a.periodo as periodo_atual', 'a.turma as turma_atual',
            ]);

        $linhas = $brutas->map(fn ($l) => [
            'codigo' => (int) $l->codigo,
            'nome' => $l->nome,
            'data' => $l->data,
            'percentual' => (float) $l->percentual,
            'ausente' => (bool) $l->ausente,
            'periodoOrdinal' => PeriodoCurso::ordinal($l->periodo_matricula)
                ?? PeriodoCurso::ordinal($l->periodo_resumo)
                ?? PeriodoCurso::ordinal($l->periodo_atual),
            'turma' => ($l->turma_matricula ?: $l->turma_atual) ?: 'Sem turma',
        ]);

        $this->evolucaoMemo[$chaveMemo] = $linhas;

        return [$linhas];
    }

    /**
     * Uma média por avaliação (em ordem de data) a partir das linhas de
     * resultado: com todos e só com os presentes.
     *
     * @param  Collection<int, array<string, mixed>>  $linhas
     * @return array<int, array{codigo: int, nome: string, data: ?string, media: float, mediaPresentes: ?float, respondentes: int, presentes: int}>
     */
    private function pontosDaEvolucao(Collection $linhas): array
    {
        return $linhas
            ->groupBy('codigo')
            ->map(function (Collection $doGrupo) {
                $primeira = $doGrupo->first();
                $presentes = $doGrupo->where('ausente', false);

                return [
                    'codigo' => $primeira['codigo'],
                    'nome' => $primeira['nome'],
                    'data' => $primeira['data'],
                    'media' => round($doGrupo->avg('percentual'), 1),
                    'mediaPresentes' => $presentes->isEmpty() ? null : round($presentes->avg('percentual'), 1),
                    'respondentes' => $doGrupo->count(),
                    'presentes' => $presentes->count(),
                ];
            })
            ->sortBy(fn ($p) => [$p['data'] ?? '0000-00-00', $p['codigo']])
            ->values()
            ->all();
    }

    /**
     * Cobertura e desempenho por referência externa da questão — DCN, PPC,
     * Portaria INEP e matriz de prova (ver App\Models\QuestaoReferencia).
     * Esses valores já eram importados e guardados por questão, mas até aqui
     * só apareciam no cadastro e na exportação: é o recorte que o colegiado e
     * a avaliação externa (MEC/INEP) pedem — o que a prova cobriu e como foi
     * o desempenho em cada eixo.
     *
     * `questoes` mede a cobertura: um eixo com 3 questões e 90% de acerto diz
     * muito menos que um com 38 questões e 74%, e a tela precisa mostrar os
     * dois números lado a lado.
     *
     * @return array<string, array{rotulo: string, itens: array<int, array{valor: string, totalQuestoes: int, percentual: float}>}>
     */
    public function desempenhoPorReferencia(Avaliacao $avaliacao, string $periodo = ''): array
    {
        $linhas = $this->escopar(DB::table('respostas as r'), 'r.', $avaliacao->codigo)
            ->join('questoes as q', function ($join) use ($avaliacao) {
                Anulacao::excluirDistribuidas(
                    $join->on('q.numero', '=', 'r.questao_numero')
                        ->where('q.avaliacao_codigo', $avaliacao->codigo)
                        ->whereNull('q.deleted_at')
                        ->whereNotNull('q.gabarito')
                        ->where('q.gabarito', '!=', ''),
                    'q.anulada_modo',
                );
            })
            ->join('questao_referencias as qr', function ($join) {
                $join->on('qr.questao_id', '=', 'q.id')
                    ->whereNotNull('qr.valor')
                    ->where('qr.valor', '!=', '');
            })
            ->where('r.avaliacao_codigo', $avaliacao->codigo)
            ->whereNull('r.deleted_at')
            ->when($periodo !== '', fn ($q) => $q->where('r.periodo', $periodo))
            ->groupBy('qr.tipo', 'qr.valor')
            ->selectRaw('qr.tipo as tipo, qr.valor as valor')
            ->selectRaw('COUNT(DISTINCT q.numero) as total_questoes')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN '.Anulacao::condicaoAcertoSql('r.resposta', 'q.gabarito', 'q.anulada_modo').' THEN 1 ELSE 0 END) as acertos')
            ->get();

        $porTipo = [];
        foreach ($linhas as $linha) {
            $porTipo[$linha->tipo][] = [
                'valor' => (string) $linha->valor,
                'totalQuestoes' => (int) $linha->total_questoes,
                'percentual' => (int) $linha->total > 0
                    ? round((int) $linha->acertos / (int) $linha->total * 100, 1)
                    : 0.0,
            ];
        }

        $resultado = [];
        foreach (self::ROTULOS_REFERENCIA as $tipo => $rotulo) {
            if (! isset($porTipo[$tipo])) {
                continue;
            }

            $itens = $porTipo[$tipo];
            usort($itens, fn ($a, $b) => $a['percentual'] <=> $b['percentual']);

            $resultado[$tipo] = ['rotulo' => $rotulo, 'itens' => $itens];
        }

        return $resultado;
    }

    /**
     * Desempenho médio por recorte demográfico (sexo, cor/raça, faixa etária)
     * — monitoramento institucional de equidade, não análise de indivíduo.
     *
     * Dois cuidados deliberados: grupos com menos de MINIMO_POR_GRUPO
     * respondentes são SUPRIMIDOS (num recorte pequeno, "a média do grupo"
     * identifica a pessoa), e a contagem de suprimidos é devolvida junto para
     * a tela poder dizer que eles existem em vez de fingir que o recorte está
     * completo. Um recorte que sobra com menos de 2 grupos visíveis não é
     * devolvido: sem comparação não há o que ler.
     *
     * @return array<string, array{rotulo: string, grupos: array<int, array{valor: string, respondentes: int, media: float}>, suprimidos: int}>
     */
    public function equidadeDemografica(Avaliacao $avaliacao, string $periodo = ''): array
    {
        $resumos = $this->escoparResumos(DB::table('resultado_resumos'))
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->when($periodo !== '', fn ($q) => $q->where('periodo', $periodo))
            ->whereNotNull('percentual')
            ->select('aluno_chave', 'percentual')
            ->get();

        if ($resumos->isEmpty()) {
            return [];
        }

        $alunos = $this->dentroDoEscopo($this->alunoResolver->resolver($avaliacao->codigo, $periodo), $avaliacao->codigo);
        $dataReferencia = $avaliacao->data_avaliacao ?? now();

        $recortes = [
            'sexo' => ['rotulo' => 'Sexo', 'valor' => fn ($aluno) => $aluno->sexo],
            'cor_raca' => ['rotulo' => 'Cor/raça', 'valor' => fn ($aluno) => $aluno->cor_raca],
            // A forma de ingresso (Vestibular, ENEM, PROUNI...) influencia o desempenho? Comparada como qualquer recorte.
            'forma_ingresso' => ['rotulo' => 'Forma de ingresso', 'valor' => fn ($aluno) => $aluno->forma_ingresso],
            'faixa_etaria' => [
                'rotulo' => 'Faixa etária',
                'valor' => fn ($aluno) => FiltroDemografico::faixaEtariaDoAluno($aluno, $dataReferencia),
            ],
        ];

        $acumulado = [];
        foreach ($resumos as $resumo) {
            $aluno = $alunos->get($resumo->aluno_chave);
            if ($aluno === null) {
                continue;
            }

            foreach ($recortes as $chave => $recorte) {
                $valor = ($recorte['valor'])($aluno);
                if (empty($valor)) {
                    continue;
                }
                $acumulado[$chave][$valor][] = (float) $resumo->percentual;
            }
        }

        $resultado = [];
        foreach ($recortes as $chave => $recorte) {
            if (! isset($acumulado[$chave])) {
                continue;
            }

            $grupos = [];
            $suprimidos = 0;

            foreach ($acumulado[$chave] as $valor => $percentuais) {
                if (count($percentuais) < self::MINIMO_POR_GRUPO) {
                    $suprimidos++;

                    continue;
                }

                $grupos[] = [
                    'valor' => (string) $valor,
                    'respondentes' => count($percentuais),
                    'media' => round(array_sum($percentuais) / count($percentuais), 1),
                ];
            }

            if (count($grupos) < 2) {
                continue;
            }

            usort($grupos, fn ($a, $b) => $b['media'] <=> $a['media']);

            $resultado[$chave] = ['rotulo' => $recorte['rotulo'], 'grupos' => $grupos, 'suprimidos' => $suprimidos];
        }

        return $resultado;
    }

    /** @return array<string, float> */
    public function mediaPorBloom(Avaliacao $avaliacao, string $periodo = ''): array
    {
        return $this->mediaPorCampoDireto($avaliacao, 'bloom_nivel', $periodo);
    }

    /** @return array<string, float> */
    public function mediaPorMiller(Avaliacao $avaliacao, string $periodo = ''): array
    {
        return $this->mediaPorCampoDireto($avaliacao, 'miller_nivel', $periodo);
    }

    /** @return array<string, float> */
    private function mediaPorCampoDireto(Avaliacao $avaliacao, string $campo, string $periodo): array
    {
        $porQuestao = $this->acertosPorQuestaoComCampo($avaliacao, $campo, $periodo);

        $radar = [];
        foreach ($porQuestao as $linha) {
            if ($linha->campo === null || $linha->campo === '') {
                continue;
            }
            $radar[$linha->campo] = (int) $linha->total > 0
                ? round((int) $linha->acertos / (int) $linha->total * 100, 1)
                : 0.0;
        }

        return $radar;
    }

    /**
     * Respostas/acertos agrupados pelo valor de um campo da questão (área, bloom, miller, dificuldade...), somados
     * das questões elegíveis a partir da varredura compartilhada de `respostas`. Mesma elegibilidade de antes:
     * com gabarito, fora as de distribuição de pontuação, campo preenchido.
     *
     * @return Collection<int, object{campo: string, acertos: int, total: int, questoes: int}>
     */
    private function acertosPorQuestaoComCampo(Avaliacao $avaliacao, string $campo, string $periodo = '')
    {
        $questoes = $this->questoesDaAvaliacao($avaliacao);
        $grupos = [];

        foreach ($this->totaisPorQuestao($avaliacao, $periodo) as $numero => $t) {
            $q = $questoes->get($numero);
            if ($q === null || ! $this->questaoContaNaNota($q) || $q->{$campo} === null || $q->{$campo} === '') {
                continue;
            }

            // O banco agrupava sem diferenciar maiúsculas/acentos (collation unicode_ci): a chave faz o mesmo.
            $chave = NomeCurso::chave($q->{$campo});
            $grupos[$chave] ??= (object) ['campo' => $q->{$campo}, 'total' => 0, 'questoes' => 0, 'acertos' => 0];
            $grupos[$chave]->total += $t['total'];
            $grupos[$chave]->questoes++;
            $grupos[$chave]->acertos += $t['acertos'];
        }
        ksort($grupos);

        return collect(array_values($grupos));
    }

    /** Questão com gabarito e que não saiu da prova por distribuição de pontuação — a que entra em acertos/total. */
    private function questaoContaNaNota(object $questao): bool
    {
        return $questao->gabarito !== null && $questao->gabarito !== '' && ! Anulacao::distribuida($questao->anulada_modo);
    }

    /** @return Collection<int, object> as questões (não excluídas) da avaliação, por número */
    private function questoesDaAvaliacao(Avaliacao $avaliacao): Collection
    {
        return $this->questoesMemo[$avaliacao->codigo] ??= DB::table('questoes')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->whereNull('deleted_at')
            ->select('numero', 'gabarito', 'area', 'tema', 'bloom_nivel', 'miller_nivel', 'dificuldade_pedagogica', 'anulada_modo')
            ->get()
            ->keyBy('numero');
    }

    /**
     * Respostas e acertos (regra de anulação) de cada questão, somados da varredura compartilhada.
     *
     * @return array<int, array{total: int, acertos: int}> número da questão => totais
     */
    private function totaisPorQuestao(Avaliacao $avaliacao, string $periodo): array
    {
        $totais = [];
        foreach ($this->contagensPorQuestao($avaliacao, $periodo) as $linha) {
            $numero = (int) $linha['numero'];
            $totais[$numero]['total'] = ($totais[$numero]['total'] ?? 0) + (int) $linha['total'];
            $totais[$numero]['acertos'] = ($totais[$numero]['acertos'] ?? 0) + (int) $linha['acertos'];
        }

        return $totais;
    }

    /**
     * UMA varredura de `respostas` por (questão, resposta): quantas respostas e quantos acertos (regra de anulação),
     * no período, no escopo de cursos e, se houver, para as chaves de aluno do filtro demográfico. É a base de todos
     * os visuais "por questão"; antes cada um fazia a sua varredura (5 a 6 por visita ao Dashboard).
     *
     * @param  ?iterable<int, string>  $chaves  aluno_chave do filtro demográfico (null = sem filtro)
     * @return Collection<int, array{numero: int, resposta: ?string, total: int, acertos: int}>
     */
    private function contagensPorQuestao(Avaliacao $avaliacao, string $periodo, ?iterable $chaves = null): Collection
    {
        $chavesLista = $chaves === null ? null : collect($chaves)->values();
        $memo = $avaliacao->codigo.'|'.$periodo.'|'
            .($chavesLista === null ? '*' : md5($chavesLista->sort()->implode(',')))
            .'|'.($this->escopo === null ? '*' : implode(',', $this->escopo->cursos));

        return $this->contagensMemo[$memo] ??= collect(CacheDeAnalise::lembrar(
            'contagens-por-questao',
            $avaliacao->codigo,
            [
                'periodo' => $periodo,
                'chaves' => $chavesLista === null ? null : md5($chavesLista->sort()->implode(',')),
                'escopo' => $this->escopo === null ? '*' : $this->escopo->assinatura($avaliacao->codigo),
            ],
            // Só arrays: o cache não desserializa objetos (cache.serializable_classes = false).
            fn () => $this->varrerContagensPorQuestao($avaliacao, $periodo, $chavesLista)->map(fn ($l) => (array) $l)->all(),
        ));
    }

    /** @return Collection<int, object{numero: int, resposta: ?string, total: int, acertos: int}> */
    private function varrerContagensPorQuestao(Avaliacao $avaliacao, string $periodo, ?Collection $chavesLista): Collection
    {
        return $this->escopar(DB::table('respostas as r'), 'r.', $avaliacao->codigo)
            ->leftJoin('questoes as q', function ($join) use ($avaliacao) {
                $join->on('q.numero', '=', 'r.questao_numero')
                    ->where('q.avaliacao_codigo', $avaliacao->codigo)
                    ->whereNull('q.deleted_at');
            })
            ->where('r.avaliacao_codigo', $avaliacao->codigo)
            ->whereNull('r.deleted_at')
            ->when($periodo !== '', fn ($q) => $q->where('r.periodo', $periodo))
            ->when($chavesLista !== null, fn ($q) => $q->whereIn('r.aluno_chave', $chavesLista->all()))
            ->groupBy('r.questao_numero', 'r.resposta')
            ->selectRaw('r.questao_numero as numero, r.resposta as resposta, COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN '.Anulacao::condicaoAcertoSql('r.resposta', 'q.gabarito', 'q.anulada_modo').' THEN 1 ELSE 0 END) as acertos')
            ->get();
    }

    /** @param array<int, array{0: float, 1: float}> $pares */
    private function correlacaoPearson(array $pares): ?float
    {
        $n = count($pares);
        $somaX = $somaY = $somaXY = $somaX2 = $somaY2 = 0.0;

        foreach ($pares as [$x, $y]) {
            $somaX += $x;
            $somaY += $y;
            $somaXY += $x * $y;
            $somaX2 += $x * $x;
            $somaY2 += $y * $y;
        }

        $numerador = $n * $somaXY - $somaX * $somaY;
        $denominador = sqrt(($n * $somaX2 - $somaX ** 2) * ($n * $somaY2 - $somaY ** 2));

        if ($denominador == 0.0) {
            return null;
        }

        return round($numerador / $denominador, 3);
    }
}
