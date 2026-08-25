<?php

namespace App\Services\Portal;

use App\Models\Aluno;
use App\Models\Avaliacao;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Análises individuais mostradas no boletim do aluno (comparativo com a
 * turma, radar por disciplina, evolução histórica...) — complementam a nota/
 * grade de questões já existentes em Portal\ResultadoConsultaService.
 *
 * Diferente de RelatorioAdminService (que agrega em SQL por escalar a milhares
 * de respondentes), aqui a base é sempre as respostas de UM aluno numa
 * avaliação — no máximo algumas centenas de questões — então agrupar em PHP
 * sobre a Collection já carregada é seguro e evita consultas extras.
 */
class RelatorioAlunoService
{
    /** @return array{turma: string, suaMedia: float, mediaTurma: float, respondentesTurma: int}|null */
    public function comparativoTurma(Aluno $aluno, Avaliacao $avaliacao, string $periodo): ?array
    {
        if (empty($aluno->turma)) {
            return null;
        }

        $suaMedia = DB::table('resultado_resumos')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->where('periodo', $periodo)
            ->where(fn ($q) => $q->where('ra', $aluno->ra)->orWhere('cpf', $aluno->cpf))
            ->value('percentual');

        if ($suaMedia === null) {
            return null;
        }

        $turma = DB::table('resultado_resumos as rr')
            ->join('alunos as a', function ($join) {
                $join->on('a.id', '=', 'rr.aluno_id')->orOn('a.ra', '=', 'rr.ra');
            })
            ->where('rr.avaliacao_codigo', $avaliacao->codigo)
            ->where('rr.periodo', $periodo)
            ->where('a.turma', $aluno->turma)
            ->whereNotNull('rr.percentual')
            ->selectRaw('AVG(rr.percentual) as media, COUNT(*) as total')
            ->first();

        if ($turma === null || (int) $turma->total === 0) {
            return null;
        }

        return [
            'turma' => $aluno->turma,
            'suaMedia' => (float) $suaMedia,
            'mediaTurma' => round((float) $turma->media, 1),
            'respondentesTurma' => (int) $turma->total,
        ];
    }

    /** @return array{posicao: int, totalRespondentes: int, percentil: float}|null */
    public function rankingPercentil(Aluno $aluno, Avaliacao $avaliacao, string $periodo): ?array
    {
        $percentuais = DB::table('resultado_resumos')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->where('periodo', $periodo)
            ->whereNotNull('percentual')
            ->orderByDesc('percentual')
            ->pluck('percentual', 'aluno_chave');

        $chave = $aluno->cpf ?: $aluno->ra;
        if (! $percentuais->has($chave) || $percentuais->count() < 2) {
            return null;
        }

        $suaNota = (float) $percentuais->get($chave);
        $total = $percentuais->count();
        $posicao = $percentuais->filter(fn ($p) => (float) $p > $suaNota)->count() + 1;

        return [
            'posicao' => $posicao,
            'totalRespondentes' => $total,
            'percentil' => round(($total - $posicao + 1) / $total * 100, 1),
        ];
    }

    /** @return array<string, float> */
    public function radarDisciplina(Collection $respostas, Collection $gabaritos, Avaliacao $avaliacao): array
    {
        $disciplinasPorNumero = DB::table('questao_matrizes as m')
            ->join('questoes as q', 'q.id', '=', 'm.questao_id')
            ->where('q.avaliacao_codigo', $avaliacao->codigo)
            ->whereNull('q.deleted_at')
            ->whereNotNull('m.disciplina')
            ->where('m.disciplina', '!=', '')
            ->select('q.numero', 'm.disciplina')
            ->get()
            ->groupBy('numero')
            ->map(fn ($grupo) => $grupo->pluck('disciplina')->unique()->all());

        return $this->mediaPorAgrupamentoMultiplo($respostas, $gabaritos, $disciplinasPorNumero);
    }

    /** @return array<string, float> */
    public function desempenhoPorBloom(Collection $respostas, Collection $gabaritos, Avaliacao $avaliacao): array
    {
        return $this->desempenhoPorCampoDireto($respostas, $gabaritos, $avaliacao, 'bloom_nivel');
    }

    /** @return array<string, float> */
    public function desempenhoPorMiller(Collection $respostas, Collection $gabaritos, Avaliacao $avaliacao): array
    {
        return $this->desempenhoPorCampoDireto($respostas, $gabaritos, $avaliacao, 'miller_nivel');
    }

    /** @return array<int, array{numero: int, sua_resposta: string, gabarito: string, acertou: bool, taxa_acerto_turma: float}> */
    public function comparativoQuestao(Avaliacao $avaliacao, string $periodo, Collection $respostas, Collection $gabaritos): array
    {
        $taxasPorQuestao = DB::table('respostas as r')
            ->join('questoes as q', function ($join) use ($avaliacao) {
                $join->on('q.numero', '=', 'r.questao_numero')
                    ->where('q.avaliacao_codigo', $avaliacao->codigo)
                    ->whereNull('q.deleted_at')
                    ->whereNotNull('q.gabarito')
                    ->where('q.gabarito', '!=', '');
            })
            ->where('r.avaliacao_codigo', $avaliacao->codigo)
            ->where('r.periodo', $periodo)
            ->groupBy('r.questao_numero')
            ->selectRaw('r.questao_numero as numero')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN r.resposta = q.gabarito THEN 1 ELSE 0 END) as acertos')
            ->get()
            ->keyBy('numero');

        $resultado = [];
        foreach ($respostas as $resposta) {
            $gabarito = $gabaritos->get($resposta->questao_numero);
            if ($gabarito === null || $gabarito === '') {
                continue;
            }

            $taxa = $taxasPorQuestao->get($resposta->questao_numero);

            $resultado[] = [
                'numero' => (int) $resposta->questao_numero,
                'sua_resposta' => (string) ($resposta->resposta ?: ''),
                'gabarito' => $gabarito,
                'acertou' => $resposta->resposta === $gabarito,
                'taxa_acerto_turma' => $taxa !== null && (int) $taxa->total > 0
                    ? round((int) $taxa->acertos / (int) $taxa->total * 100, 1)
                    : 0.0,
            ];
        }

        return $resultado;
    }

    /**
     * @param  array<int, array<string, mixed>>  $resultadosDoAluno  saída de ResultadoConsultaService::buscarPorAluno()
     * @return array<int, array{codigo: int, nome: string, data: ?string, percentual: ?float}>
     */
    public function evolucaoHistorica(array $resultadosDoAluno, ?int $categoriaId): array
    {
        if ($categoriaId === null) {
            return [];
        }

        return collect($resultadosDoAluno)
            ->filter(fn ($r) => $r['avaliacao']->categoria_id === $categoriaId)
            ->sortBy(fn ($r) => $r['avaliacao']->data_avaliacao?->format('Y-m-d') ?? '0000-00-00')
            ->map(fn ($r) => [
                'codigo' => $r['avaliacao']->codigo,
                'nome' => $r['avaliacao']->nome ?? "Avaliação #{$r['avaliacao']->codigo}",
                'data' => $r['avaliacao']->data_avaliacao?->format('d/m/Y'),
                'percentual' => $r['percentual'],
            ])
            ->values()
            ->all();
    }

    /** @return array<string, float> */
    private function desempenhoPorCampoDireto(Collection $respostas, Collection $gabaritos, Avaliacao $avaliacao, string $campo): array
    {
        $valoresPorNumero = DB::table('questoes')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->whereNull('deleted_at')
            ->whereNotNull($campo)
            ->where($campo, '!=', '')
            ->pluck($campo, 'numero')
            ->map(fn ($v) => [$v]);

        return $this->mediaPorAgrupamentoMultiplo($respostas, $gabaritos, $valoresPorNumero);
    }

    /**
     * Cada questão pode entrar em mais de um grupo (uma questão com 2 disciplinas
     * na matriz conta o acerto/erro pra cada uma) — por isso o mapa é
     * numero => lista de grupos, não numero => grupo único.
     *
     * @param  Collection<int, array<int, string>>  $gruposPorNumero
     * @return array<string, float>
     */
    private function mediaPorAgrupamentoMultiplo(Collection $respostas, Collection $gabaritos, Collection $gruposPorNumero): array
    {
        $acumulado = [];

        foreach ($respostas as $resposta) {
            $grupos = $gruposPorNumero->get($resposta->questao_numero);
            $gabarito = $gabaritos->get($resposta->questao_numero);

            if (empty($grupos) || $gabarito === null || $gabarito === '') {
                continue;
            }

            $acertou = $resposta->resposta === $gabarito;

            foreach ($grupos as $grupo) {
                $acumulado[$grupo] ??= ['acertos' => 0, 'total' => 0];
                $acumulado[$grupo]['total']++;
                $acumulado[$grupo]['acertos'] += $acertou ? 1 : 0;
            }
        }

        $resultado = [];
        foreach ($acumulado as $grupo => $s) {
            $resultado[$grupo] = $s['total'] > 0 ? round($s['acertos'] / $s['total'] * 100, 1) : 0.0;
        }

        return $resultado;
    }
}
