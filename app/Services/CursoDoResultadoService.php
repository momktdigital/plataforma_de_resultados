<?php

namespace App\Services;

use App\Models\AlunoMatricula;
use App\Support\NomeCurso;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Descobre, para cada resultado (`resultado_resumos`), em que CURSO o aluno
 * estava quando fez a prova, e grava em `resultado_resumos.curso`. É esse
 * curso — e não o curso atual do aluno — que define quem enxerga o resultado
 * (coordenador, dashboards, BI): um aluno transferido de Medicina para
 * Odontologia continua nos números de Medicina nas provas que fez como aluno
 * de Medicina, e passa a contar para Odontologia nas provas seguintes.
 *
 * A decisão é DETERMINÍSTICA a partir do histórico (`aluno_matriculas`) — não
 * é um "instantâneo" do dia da importação —, então reimportar planilhas de
 * matrícula antigas corrige resultados já gravados (ver atualizarAlunos()).
 *
 * Regra, na ordem (a primeira que achar matrícula(s) decide):
 *  1. matrícula VÁLIDA NA DATA DA PROVA. Cada matrícula vale de `data_inicio`
 *     (Dt. Ativação; na falta, o início do período letivo) até `data_fim`
 *     (Dt. Ocorrência, só nas que NÃO estão ativas) — na falta, o fim do
 *     período letivo. Uma matrícula ATIVA de um período vale só até o fim
 *     dele; quem continua tem a linha do período seguinte.
 *  2. sem data da prova (ou sem matrícula válida nela): matrícula do MESMO
 *     PERÍODO LETIVO da prova;
 *  3. matrícula de um curso marcado À MÃO na avaliação (`avaliacao_cursos`
 *     origem manual);
 *  4. o curso atual do aluno (`alunos.curso`) — o mesmo comportamento de
 *     antes de existir histórico.
 * Com mais de uma candidata (ex.: dois cursos ativos ao mesmo tempo), vence a
 * de curso marcado à mão na avaliação; depois a ATIVA, a de início mais
 * recente e a gravada por último.
 */
class CursoDoResultadoService
{
    private const LOTE = 500;

    /** Recalcula o curso de TODOS os resultados de uma avaliação. */
    public function atualizarAvaliacao(int $avaliacaoCodigo): void
    {
        $this->aplicar(
            DB::table('resultado_resumos')->where('avaliacao_codigo', $avaliacaoCodigo)->get(['id', 'avaliacao_codigo', 'aluno_id', 'ra'])
        );
    }

    /**
     * Recalcula o curso dos resultados de certos alunos, em qualquer
     * avaliação — chamado quando o histórico de matrículas deles muda.
     *
     * @param  array<int, int>  $alunoIds
     * @return array<int, int> códigos das avaliações cujos resultados foram reavaliados
     */
    public function atualizarAlunos(array $alunoIds): array
    {
        $avaliacoes = [];

        foreach (array_chunk(array_values(array_unique($alunoIds)), self::LOTE) as $lote) {
            $resumos = DB::table('resultado_resumos')->whereIn('aluno_id', $lote)->get(['id', 'avaliacao_codigo', 'aluno_id', 'ra']);
            $avaliacoes = [...$avaliacoes, ...$resumos->pluck('avaliacao_codigo')->all()];
            $this->aplicar($resumos);
        }

        return array_values(array_unique(array_map('intval', $avaliacoes)));
    }

    /** @param Collection<int, object> $resumos linhas com id, avaliacao_codigo, aluno_id, ra */
    private function aplicar(Collection $resumos): void
    {
        if ($resumos->isEmpty()) {
            return;
        }

        $avaliacoes = DB::table('avaliacoes')->whereIn('codigo', $resumos->pluck('avaliacao_codigo')->unique()->all())
            ->get(['codigo', 'data_avaliacao'])->keyBy('codigo');

        $cursosManuais = DB::table('avaliacao_cursos')->where('origem', 'manual')
            ->whereIn('avaliacao_codigo', $resumos->pluck('avaliacao_codigo')->unique()->all())
            ->get(['avaliacao_codigo', 'curso'])->groupBy('avaliacao_codigo')->map->pluck('curso');

        // Resultado cujo aluno ainda não estava na matrícula na hora da
        // importação não tem aluno_id: casa pelo RA (como o AlunoVinculoResolver).
        // RAs vão como parâmetro — `alunos` tem collation diferente de `resultado_resumos`.
        $alunoPorRa = [];
        $semId = $resumos->whereNull('aluno_id')->pluck('ra')->filter()->unique()->values();
        foreach ($semId->chunk(1000) as $ras) {
            foreach (DB::table('alunos')->whereIn('ra', $ras->all())->get(['id', 'ra']) as $a) {
                $alunoPorRa[$a->ra] = (int) $a->id;
            }
        }

        $idDoResumo = fn ($r) => $r->aluno_id !== null ? (int) $r->aluno_id : ($alunoPorRa[$r->ra] ?? null);
        $alunoIds = $resumos->map($idDoResumo)->filter()->unique()->values()->all();

        $matriculas = $this->carregarMatriculas($alunoIds);
        $cursoAtual = [];
        foreach (array_chunk($alunoIds, 1000) as $lote) {
            foreach (DB::table('alunos')->whereIn('id', $lote)->get(['id', 'curso']) as $a) {
                $cursoAtual[(int) $a->id] = $a->curso;
            }
        }

        // (curso, matrícula) => ids dos resumos — um UPDATE por combinação.
        $grupos = [];
        foreach ($resumos as $resumo) {
            $alunoId = $idDoResumo($resumo);
            $data = $avaliacoes[$resumo->avaliacao_codigo]->data_avaliacao ?? null;

            [$curso, $matriculaId] = $alunoId === null
                ? [null, null]
                : $this->resolver(
                    $matriculas[$alunoId] ?? [],
                    $data ? substr((string) $data, 0, 10) : null,
                    $cursosManuais[$resumo->avaliacao_codigo] ?? collect(),
                    $cursoAtual[$alunoId] ?? null,
                );

            $grupos[($curso ?? '').'|'.($matriculaId ?? '')]['ids'][] = $resumo->id;
            $grupos[($curso ?? '').'|'.($matriculaId ?? '')]['valor'] = ['curso' => $curso, 'matricula_id' => $matriculaId];
        }

        foreach ($grupos as $grupo) {
            foreach (array_chunk($grupo['ids'], 1000) as $ids) {
                DB::table('resultado_resumos')->whereIn('id', $ids)->update($grupo['valor']);
            }
        }
    }

    /**
     * @param  array<int, int>  $alunoIds
     * @return array<int, array<int, array<string, mixed>>> aluno_id => matrículas normalizadas
     */
    private function carregarMatriculas(array $alunoIds): array
    {
        $porAluno = [];

        foreach (array_chunk($alunoIds, 1000) as $lote) {
            foreach (DB::table('aluno_matriculas')->whereIn('aluno_id', $lote)->get() as $m) {
                $ativa = AlunoMatricula::estaAtiva($m->status);
                $pl = (string) $m->periodo_letivo;

                $porAluno[(int) $m->aluno_id][] = [
                    'id' => (int) $m->id,
                    'curso' => (string) $m->curso,
                    'ativa' => $ativa,
                    'periodo_letivo' => $pl,
                    'inicio' => $m->data_inicio ? substr((string) $m->data_inicio, 0, 10) : ($this->inicioDoPeriodo($pl) ?? '0000-01-01'),
                    // Dt. Ocorrência só encerra matrícula que NÃO está ativa.
                    'fim' => (! $ativa && $m->data_fim) ? substr((string) $m->data_fim, 0, 10) : ($this->fimDoPeriodo($pl) ?? '9999-12-31'),
                    'ordem' => (string) $m->updated_at.'|'.str_pad((string) $m->id, 12, '0', STR_PAD_LEFT),
                ];
            }
        }

        return $porAluno;
    }

    /**
     * @param  array<int, array<string, mixed>>  $matriculas
     * @param  Collection<int, string>  $cursosManuais
     * @return array{0: ?string, 1: ?int} [curso, id da matrícula]
     */
    private function resolver(array $matriculas, ?string $dataProva, Collection $cursosManuais, ?string $cursoAtual): array
    {
        $candidatas = [];

        if ($dataProva !== null) {
            $candidatas = array_filter($matriculas, fn ($m) => $m['inicio'] <= $dataProva && $dataProva <= $m['fim']);

            if ($candidatas === []) {
                $pl = $this->periodoLetivoDaData($dataProva);
                $candidatas = array_filter($matriculas, fn ($m) => $m['periodo_letivo'] === $pl);
            }
        }

        if ($candidatas === [] && $cursosManuais->isNotEmpty()) {
            $candidatas = array_filter($matriculas, fn ($m) => NomeCurso::estaEm($m['curso'], $cursosManuais));
        }

        if ($candidatas === []) {
            // Sem pista nenhuma: o curso atual; se nem esse existir, a matrícula mais recente.
            if ($cursoAtual !== null && $cursoAtual !== '') {
                $doAtual = array_values(array_filter($matriculas, fn ($m) => NomeCurso::mesmo($m['curso'], $cursoAtual)));

                return [$cursoAtual, $doAtual === [] ? null : $this->melhor($doAtual)['id']];
            }

            if ($matriculas === []) {
                return [null, null];
            }

            $maisRecente = $this->melhor($matriculas, true);

            return [$maisRecente['curso'], $maisRecente['id']];
        }

        $candidatas = array_values($candidatas);

        // Mais de um curso possível: o curso marcado à mão na avaliação desempata.
        if ($cursosManuais->isNotEmpty() && count(array_unique(array_map(fn ($m) => NomeCurso::chave($m['curso']), $candidatas))) > 1) {
            $preferidas = array_values(array_filter($candidatas, fn ($m) => NomeCurso::estaEm($m['curso'], $cursosManuais)));
            if ($preferidas !== []) {
                $candidatas = $preferidas;
            }
        }

        $escolhida = $this->melhor($candidatas);

        return [$escolhida['curso'], $escolhida['id']];
    }

    /**
     * Desempate: ativa > início mais recente > gravada por último. Com
     * $porPeriodoLetivo, o período letivo mais recente vem antes de tudo.
     *
     * @param  array<int, array<string, mixed>>  $matriculas
     * @return array<string, mixed>
     */
    private function melhor(array $matriculas, bool $porPeriodoLetivo = false): array
    {
        usort($matriculas, fn ($a, $b) => [
            $porPeriodoLetivo ? $b['periodo_letivo'] : '', (int) $b['ativa'], $b['inicio'], $b['ordem'],
        ] <=> [
            $porPeriodoLetivo ? $a['periodo_letivo'] : '', (int) $a['ativa'], $a['inicio'], $a['ordem'],
        ]);

        return $matriculas[0];
    }

    private function periodoLetivoDaData(string $data): string
    {
        return substr($data, 0, 4).'/'.((int) substr($data, 5, 2) <= 6 ? 1 : 2);
    }

    /** "2026/2" → "2026-07-01"; null se não for um período letivo. */
    private function inicioDoPeriodo(string $periodoLetivo): ?string
    {
        return preg_match('#^(\d{4})/([12])$#', $periodoLetivo, $m) ? $m[1].($m[2] === '1' ? '-01-01' : '-07-01') : null;
    }

    /** "2026/2" → "2026-12-31". */
    private function fimDoPeriodo(string $periodoLetivo): ?string
    {
        return preg_match('#^(\d{4})/([12])$#', $periodoLetivo, $m) ? $m[1].($m[2] === '1' ? '-06-30' : '-12-31') : null;
    }
}
