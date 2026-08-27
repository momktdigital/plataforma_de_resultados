<?php

namespace App\Services;

use App\Models\Avaliacao;
use App\Models\Resposta;
use App\Support\AlunoVinculoResolver;
use App\Support\Anulacao;
use App\Support\FiltroDemografico;
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
    public function __construct(
        private readonly AlunoVinculoResolver $alunoResolver = new AlunoVinculoResolver,
    ) {}

    /** @return array<int, array{ra: ?string, cpf: ?string, periodo: string, acertos: int, total: int, percentual: ?float, aluno_nome: ?string, turma: ?string}> */
    public function rankingCompleto(Avaliacao $avaliacao, string $periodo = ''): array
    {
        $resumos = DB::table('resultado_resumos')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->when($periodo !== '', fn ($q) => $q->where('periodo', $periodo))
            ->orderByDesc('percentual')
            ->select('aluno_chave', 'ra', 'cpf', 'periodo', 'acertos', 'total', 'percentual')
            ->get();

        $alunos = $this->alunoResolver->resolver($avaliacao->codigo, $periodo);

        return $resumos->map(fn ($r) => [
            'ra' => $r->ra,
            'cpf' => $r->cpf,
            'periodo' => $r->periodo,
            'acertos' => (int) $r->acertos,
            'total' => (int) $r->total,
            'percentual' => $r->percentual !== null ? (float) $r->percentual : null,
            'aluno_nome' => $alunos->get($r->aluno_chave)?->nome,
            'turma' => $alunos->get($r->aluno_chave)?->turma,
        ])->all();
    }

    /**
     * $filtro nunca restringe por turma aqui — turma já É a dimensão de
     * agrupamento deste visual (ver FiltroDemografico::semTurma()).
     *
     * @return array<int, array{turma: string, respondentes: int, media: float, minimo: float, maximo: float}>
     */
    public function distribuicaoPorTurma(Avaliacao $avaliacao, string $periodo = '', ?FiltroDemografico $filtro = null): array
    {
        $resumos = DB::table('resultado_resumos')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->when($periodo !== '', fn ($q) => $q->where('periodo', $periodo))
            ->whereNotNull('percentual')
            ->select('aluno_chave', 'percentual')
            ->get();

        $alunos = $this->alunoResolver->resolver($avaliacao->codigo, $periodo);
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

    /** @return array<string, array{esperado: string, observado: float, questoes: int}> facil/medio/dificil */
    public function curvaDificuldade(Avaliacao $avaliacao): array
    {
        $porQuestao = $this->acertosPorQuestaoComCampo($avaliacao, 'dificuldade_pedagogica');

        $ordem = ['facil' => 'Fácil', 'medio' => 'Médio', 'dificil' => 'Difícil'];
        $acumulado = [];

        foreach ($porQuestao as $linha) {
            $chave = $linha->campo;
            if (! isset($ordem[$chave])) {
                continue;
            }
            $acumulado[$chave] ??= ['acertos' => 0, 'total' => 0, 'questoes' => 0];
            $acumulado[$chave]['acertos'] += (int) $linha->acertos;
            $acumulado[$chave]['total'] += (int) $linha->total;
            $acumulado[$chave]['questoes']++;
        }

        $resultado = [];
        foreach ($ordem as $chave => $label) {
            if (! isset($acumulado[$chave])) {
                continue;
            }
            $s = $acumulado[$chave];
            $resultado[$chave] = [
                'esperado' => $label,
                'observado' => $s['total'] > 0 ? round($s['acertos'] / $s['total'] * 100, 1) : 0.0,
                'questoes' => $s['questoes'],
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

        $stats = DB::table('respostas as r')
            ->join('questoes as q', function ($join) use ($avaliacao) {
                $join->on('q.numero', '=', 'r.questao_numero')
                    ->where('q.avaliacao_codigo', $avaliacao->codigo)
                    ->whereNull('q.deleted_at');
            })
            ->where('r.avaliacao_codigo', $avaliacao->codigo)
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

        $porAlunoHabilidade = DB::table('respostas as r')
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

        $alunos = $this->alunoResolver->resolver($avaliacao->codigo, $periodo);

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

    /** @return array{sexo: array<string, int>, cor_raca: array<string, int>, uf: array<string, int>} */
    public function perfilDemografico(Avaliacao $avaliacao): array
    {
        $alunos = $this->alunoResolver->resolver($avaliacao->codigo);

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
        $questoes = DB::table('questoes')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->whereNull('deleted_at')
            ->select('numero', 'gabarito', 'area', 'tema', 'anulada_modo')
            ->get()
            ->keyBy('numero');

        $chaves = $filtro !== null
            ? $this->alunoResolver->chavesFiltradas($avaliacao->codigo, $periodo, $filtro, $avaliacao->data_avaliacao)
            : null;

        $linhas = DB::table('respostas')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->whereNull('deleted_at')
            ->when($periodo !== '', fn ($q) => $q->where('periodo', $periodo))
            ->when($chaves !== null, fn ($q) => $q->whereIn('aluno_chave', $chaves))
            ->groupBy('questao_numero', 'resposta')
            ->selectRaw("questao_numero, COALESCE(NULLIF(resposta, ''), '—') as alternativa, COUNT(*) as total")
            ->get();

        if ($linhas->isEmpty()) {
            return [];
        }

        $contagensPorQuestao = [];
        foreach ($linhas as $linha) {
            $contagensPorQuestao[(int) $linha->questao_numero][$linha->alternativa] = (int) $linha->total;
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

            $distrator = collect($contagens)
                ->reject(fn ($total, $alternativa) => $alternativa === $gabarito || $semResposta($alternativa) || $total === 0)
                ->sortDesc()
                ->keys()
                ->first();

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
        $linhas = DB::table('respostas as r')
            ->join('questoes as q', function ($join) use ($avaliacao) {
                Anulacao::excluirDistribuidas(
                    $join->on('q.numero', '=', 'r.questao_numero')
                        ->where('q.avaliacao_codigo', $avaliacao->codigo)
                        ->whereNull('q.deleted_at')
                        ->whereNotNull('q.gabarito')
                        ->where('q.gabarito', '!=', '')
                        ->whereNotNull('q.tema')
                        ->where('q.tema', '!=', ''),
                    'q.anulada_modo',
                );
            })
            ->where('r.avaliacao_codigo', $avaliacao->codigo)
            ->when($periodo !== '', fn ($q) => $q->where('r.periodo', $periodo))
            ->groupBy('q.area', 'q.tema')
            ->selectRaw('q.area as area, q.tema as tema')
            ->selectRaw('COUNT(DISTINCT q.numero) as total_questoes')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN '.Anulacao::condicaoAcertoSql('r.resposta', 'q.gabarito', 'q.anulada_modo').' THEN 1 ELSE 0 END) as acertos')
            ->get();

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

        $percentuais = DB::table('resultado_resumos')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->when($periodo !== '', fn ($q) => $q->where('periodo', $periodo))
            ->when($chaves !== null, fn ($q) => $q->whereIn('aluno_chave', $chaves))
            ->whereNotNull('percentual')
            ->pluck('percentual', 'aluno_chave');

        if ($percentuais->isEmpty()) {
            return [];
        }

        $metricas = DB::table('resultado_metricas')
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

    /** @return array<int, array{codigo: int, nome: string, data: ?string, media: float, respondentes: int}> */
    public function evolucaoCategoria(Avaliacao $avaliacao): array
    {
        if ($avaliacao->categoria_id === null) {
            return [];
        }

        return DB::table('avaliacoes as av')
            ->join('resultado_resumos as rr', 'rr.avaliacao_codigo', '=', 'av.codigo')
            ->where('av.categoria_id', $avaliacao->categoria_id)
            ->whereNull('av.deleted_at')
            ->groupBy('av.codigo', 'av.nome', 'av.data_avaliacao')
            ->selectRaw('av.codigo as codigo, av.nome as nome, av.data_avaliacao as data')
            ->selectRaw('AVG(rr.percentual) as media')
            ->selectRaw('COUNT(DISTINCT rr.aluno_chave) as respondentes')
            ->orderBy('av.data_avaliacao')
            ->get()
            ->map(fn ($l) => [
                'codigo' => (int) $l->codigo,
                'nome' => $l->nome,
                'data' => $l->data,
                'media' => round((float) $l->media, 1),
                'respondentes' => (int) $l->respondentes,
            ])
            ->all();
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

    /** @return Collection<int, object{campo: ?string, acertos: int, total: int}> */
    private function acertosPorQuestaoComCampo(Avaliacao $avaliacao, string $campo, string $periodo = '')
    {
        return DB::table('respostas as r')
            ->join('questoes as q', function ($join) use ($avaliacao, $campo) {
                Anulacao::excluirDistribuidas(
                    $join->on('q.numero', '=', 'r.questao_numero')
                        ->where('q.avaliacao_codigo', $avaliacao->codigo)
                        ->whereNull('q.deleted_at')
                        ->whereNotNull('q.gabarito')
                        ->where('q.gabarito', '!=', '')
                        ->whereNotNull("q.{$campo}")
                        ->where("q.{$campo}", '!=', ''),
                    'q.anulada_modo',
                );
            })
            ->where('r.avaliacao_codigo', $avaliacao->codigo)
            ->when($periodo !== '', fn ($q) => $q->where('r.periodo', $periodo))
            ->groupBy("q.{$campo}")
            ->selectRaw("q.{$campo} as campo")
            ->selectRaw('COUNT(*) as total')
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
