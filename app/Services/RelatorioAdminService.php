<?php

namespace App\Services;

use App\Models\Avaliacao;
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
 * Toda agregação é feita em SQL (join + GROUP BY), no mesmo espírito de
 * BiDashboardService, para não trazer a tabela de respostas inteira pra uma
 * Collection do PHP.
 */
class RelatorioAdminService
{
    /** @return array<int, array{ra: ?string, cpf: ?string, periodo: string, acertos: int, total: int, percentual: ?float, aluno_nome: ?string, turma: ?string}> */
    public function rankingCompleto(Avaliacao $avaliacao, string $periodo = ''): array
    {
        return DB::table('resultado_resumos as rr')
            ->leftJoin('alunos as a', function ($join) {
                $join->on('a.id', '=', 'rr.aluno_id')->orOn('a.ra', '=', 'rr.ra');
            })
            ->where('rr.avaliacao_codigo', $avaliacao->codigo)
            ->when($periodo !== '', fn ($q) => $q->where('rr.periodo', $periodo))
            ->orderByDesc('rr.percentual')
            ->select([
                'rr.ra', 'rr.cpf', 'rr.periodo', 'rr.acertos', 'rr.total', 'rr.percentual',
                'a.nome as aluno_nome', 'a.turma',
            ])
            ->get()
            ->map(fn ($linha) => (array) $linha)
            ->all();
    }

    /** @return array<int, array{turma: string, respondentes: int, media: float, minimo: float, maximo: float}> */
    public function distribuicaoPorTurma(Avaliacao $avaliacao, string $periodo = ''): array
    {
        return DB::table('resultado_resumos as rr')
            ->join('alunos as a', function ($join) {
                $join->on('a.id', '=', 'rr.aluno_id')->orOn('a.ra', '=', 'rr.ra');
            })
            ->where('rr.avaliacao_codigo', $avaliacao->codigo)
            ->when($periodo !== '', fn ($q) => $q->where('rr.periodo', $periodo))
            ->whereNotNull('a.turma')
            ->where('a.turma', '!=', '')
            ->groupBy('a.turma')
            ->selectRaw('a.turma as turma')
            ->selectRaw('COUNT(*) as respondentes')
            ->selectRaw('AVG(rr.percentual) as media')
            ->selectRaw('MIN(rr.percentual) as minimo')
            ->selectRaw('MAX(rr.percentual) as maximo')
            ->orderByDesc('media')
            ->get()
            ->map(fn ($l) => [
                'turma' => $l->turma,
                'respondentes' => (int) $l->respondentes,
                'media' => round((float) $l->media, 1),
                'minimo' => round((float) $l->minimo, 1),
                'maximo' => round((float) $l->maximo, 1),
            ])
            ->all();
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

    /** @return array<string, array<string, float>> [habilidade => [turma => %acerto]] */
    public function heatmapHabilidadeTurma(Avaliacao $avaliacao): array
    {
        $linhas = DB::table('respostas as r')
            ->join('questoes as q', function ($join) use ($avaliacao) {
                $join->on('q.numero', '=', 'r.questao_numero')
                    ->where('q.avaliacao_codigo', $avaliacao->codigo)
                    ->whereNull('q.deleted_at')
                    ->whereNotNull('q.gabarito')
                    ->where('q.gabarito', '!=', '')
                    ->whereNotNull('q.habilidade')
                    ->where('q.habilidade', '!=', '');
            })
            ->join('alunos as a', function ($join) {
                $join->on('a.id', '=', 'r.aluno_id')->orOn('a.ra', '=', 'r.ra');
            })
            ->where('r.avaliacao_codigo', $avaliacao->codigo)
            ->whereNotNull('a.turma')
            ->where('a.turma', '!=', '')
            ->groupBy('q.habilidade', 'a.turma')
            ->selectRaw('q.habilidade as habilidade, a.turma as turma')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN r.resposta = q.gabarito THEN 1 ELSE 0 END) as acertos')
            ->get();

        $matriz = [];
        foreach ($linhas as $linha) {
            $matriz[$linha->habilidade][$linha->turma] = (int) $linha->total > 0
                ? round((int) $linha->acertos / (int) $linha->total * 100, 1)
                : 0.0;
        }

        return $matriz;
    }

    /** @return array{sexo: array<string, int>, cor_raca: array<string, int>, uf: array<string, int>} */
    public function perfilDemografico(Avaliacao $avaliacao): array
    {
        $contarPor = fn (string $campo) => DB::table('resultado_resumos as rr')
            ->join('alunos as a', function ($join) {
                $join->on('a.id', '=', 'rr.aluno_id')->orOn('a.ra', '=', 'rr.ra');
            })
            ->where('rr.avaliacao_codigo', $avaliacao->codigo)
            ->whereNotNull("a.{$campo}")
            ->where("a.{$campo}", '!=', '')
            ->groupBy("a.{$campo}")
            ->selectRaw("a.{$campo} as valor, COUNT(DISTINCT rr.aluno_chave) as total")
            ->orderByDesc('total')
            ->pluck('total', 'valor')
            ->map(fn ($v) => (int) $v)
            ->all();

        return [
            'sexo' => $contarPor('sexo'),
            'cor_raca' => $contarPor('cor_raca'),
            'uf' => $contarPor('uf'),
        ];
    }

    /** @return array<int, array{numero: int, alternativas: array<string, int>}> */
    public function analiseAlternativas(Avaliacao $avaliacao): array
    {
        $linhas = DB::table('respostas')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->whereNull('deleted_at')
            ->groupBy('questao_numero', 'resposta')
            ->selectRaw("questao_numero, COALESCE(NULLIF(resposta, ''), '(em branco)') as alternativa, COUNT(*) as total")
            ->orderBy('questao_numero')
            ->get();

        $porQuestao = [];
        foreach ($linhas as $linha) {
            $porQuestao[$linha->questao_numero]['numero'] = (int) $linha->questao_numero;
            $porQuestao[$linha->questao_numero]['alternativas'][$linha->alternativa] = (int) $linha->total;
        }

        return array_values($porQuestao);
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
