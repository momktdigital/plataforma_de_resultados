<?php

namespace App\Services;

use App\Models\Avaliacao;
use App\Support\AlunoVinculoResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Análises administrativas adicionais por avaliação, complementares ao
 * histograma/radar/Top5 de BiDashboardService e às questões críticas de
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

    /** @return array<int, array{turma: string, respondentes: int, media: float, minimo: float, maximo: float}> */
    public function distribuicaoPorTurma(Avaliacao $avaliacao, string $periodo = ''): array
    {
        $resumos = DB::table('resultado_resumos')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->when($periodo !== '', fn ($q) => $q->where('periodo', $periodo))
            ->whereNotNull('percentual')
            ->select('aluno_chave', 'percentual')
            ->get();

        $alunos = $this->alunoResolver->resolver($avaliacao->codigo, $periodo);

        $porTurma = [];
        foreach ($resumos as $r) {
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
        $porQuestao = DB::table('questoes')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->whereNull('deleted_at')
            ->whereNotNull('gabarito')
            ->where('gabarito', '!=', '')
            ->whereNotNull('dificuldade_tri')
            ->select('numero', 'dificuldade_tri')
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
            ->selectRaw('SUM(CASE WHEN r.resposta = q.gabarito THEN 1 ELSE 0 END) as acertos')
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
     * @return array<string, array<string, float>>
     */
    public function heatmapHabilidadeTurma(Avaliacao $avaliacao): array
    {
        $porAlunoHabilidade = DB::table('respostas as r')
            ->join('questoes as q', function ($join) use ($avaliacao) {
                $join->on('q.numero', '=', 'r.questao_numero')
                    ->where('q.avaliacao_codigo', $avaliacao->codigo)
                    ->whereNull('q.deleted_at')
                    ->whereNotNull('q.gabarito')
                    ->where('q.gabarito', '!=', '')
                    ->whereNotNull('q.habilidade')
                    ->where('q.habilidade', '!=', '');
            })
            ->where('r.avaliacao_codigo', $avaliacao->codigo)
            ->groupBy('r.aluno_chave', 'q.habilidade')
            ->selectRaw('r.aluno_chave as aluno_chave, q.habilidade as habilidade')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN r.resposta = q.gabarito THEN 1 ELSE 0 END) as acertos')
            ->get();

        if ($porAlunoHabilidade->isEmpty()) {
            return [];
        }

        $alunos = $this->alunoResolver->resolver($avaliacao->codigo);

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
     * Matriz questão x alternativa: uma coluna por questão, pra comparar de
     * relance qual foi a alternativa mais marcada em cada uma e se ela bate
     * com o gabarito — a view usa 'gabarito' e 'maisMarcada' pra destacar a
     * célula certa (borda) e a mais popular (cor de fundo) de cada coluna.
     *
     * @return array{alternativas: array<int, string>, questoes: array<int, array{numero: int, gabarito: ?string, maisMarcada: ?string, contagens: array<string, int>}>}
     */
    public function analiseAlternativas(Avaliacao $avaliacao): array
    {
        $gabaritos = DB::table('questoes')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->whereNull('deleted_at')
            ->pluck('gabarito', 'numero');

        $linhas = DB::table('respostas')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->whereNull('deleted_at')
            ->groupBy('questao_numero', 'resposta')
            ->selectRaw("questao_numero, COALESCE(NULLIF(resposta, ''), '—') as alternativa, COUNT(*) as total")
            ->get();

        if ($linhas->isEmpty()) {
            return ['alternativas' => [], 'questoes' => []];
        }

        // Linhas do grid em ordem fixa: letras simples (A, B, C...) primeiro,
        // qualquer marcação composta (ex.: "B,D", vindas de gabarito múltiplo)
        // depois, e "em branco" sempre por último — assim toda coluna usa a
        // mesma ordem de linhas, o que é o que faz a matriz ser comparável.
        $todas = $linhas->pluck('alternativa')->unique();
        $simples = $todas->filter(fn ($a) => preg_match('/^[A-Z]$/', $a))->sort()->values();
        $compostas = $todas->diff($simples)->diff(['—'])->sort()->values();
        $ordemAlternativas = $simples->concat($compostas)
            ->when($todas->contains('—'), fn ($c) => $c->push('—'))
            ->values()->all();

        $porQuestao = [];
        foreach ($linhas as $linha) {
            $numero = (int) $linha->questao_numero;
            $porQuestao[$numero]['numero'] ??= $numero;
            $porQuestao[$numero]['gabarito'] ??= $gabaritos->get($numero);
            $porQuestao[$numero]['contagens'][$linha->alternativa] = (int) $linha->total;
        }

        foreach ($porQuestao as &$questao) {
            $questao['maisMarcada'] = collect($questao['contagens'])->sortDesc()->keys()->first();
        }
        unset($questao);

        ksort($porQuestao);

        return ['alternativas' => $ordemAlternativas, 'questoes' => array_values($porQuestao)];
    }

    /** @return array<int, array{nome_metrica: string, n: int, correlacao: ?float}> */
    public function correlacaoMetricas(Avaliacao $avaliacao, string $periodo = ''): array
    {
        $percentuais = DB::table('resultado_resumos')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->when($periodo !== '', fn ($q) => $q->where('periodo', $periodo))
            ->whereNotNull('percentual')
            ->pluck('percentual', 'aluno_chave');

        if ($percentuais->isEmpty()) {
            return [];
        }

        $metricas = DB::table('resultado_metricas')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->whereNull('deleted_at')
            ->when($periodo !== '', fn ($q) => $q->where('periodo', $periodo))
            ->select('nome_metrica', 'ra', 'cpf', 'valor')
            ->get()
            ->groupBy('nome_metrica');

        $resultado = [];
        foreach ($metricas as $nomeMetrica => $linhas) {
            $pares = [];
            foreach ($linhas as $linha) {
                $chave = $linha->cpf ?: $linha->ra;
                $percentual = $percentuais->get($chave);
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
                $join->on('q.numero', '=', 'r.questao_numero')
                    ->where('q.avaliacao_codigo', $avaliacao->codigo)
                    ->whereNull('q.deleted_at')
                    ->whereNotNull('q.gabarito')
                    ->where('q.gabarito', '!=', '')
                    ->whereNotNull("q.{$campo}")
                    ->where("q.{$campo}", '!=', '');
            })
            ->where('r.avaliacao_codigo', $avaliacao->codigo)
            ->when($periodo !== '', fn ($q) => $q->where('r.periodo', $periodo))
            ->groupBy("q.{$campo}")
            ->selectRaw("q.{$campo} as campo")
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN r.resposta = q.gabarito THEN 1 ELSE 0 END) as acertos')
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
