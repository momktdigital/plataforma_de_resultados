<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * "Aluno com desempenho esperado" — a regra única (CLAUDE.md, "A meta por período é 60% do que cabe no período").
 *
 * O aluno tem desempenho esperado quando o acerto dele na prova alcançou o MÍNIMO do período do curso em que estava:
 * 60% da fatia da prova que já cabe nesse período (`questoes.periodo_minimo`, PeriodoCurso::metaDoPeriodo). Prova sem
 * `periodo_minimo` em nenhuma questão válida (ou aluno com período do curso irreconhecível) é medida pelo mínimo padrão,
 * 60% da prova inteira. Só conta quem fez a prova (presente, com nota).
 *
 * É o que a instituição usa no lugar de "proficiência": o painel da reitoria, o Dashboard da avaliação, o painel do
 * coordenador e o plano de ação precisam contar as mesmas pessoas — por isso a conta mora aqui e as telas só a chamam.
 *
 * Duas portas para a mesma regra: minimo() (em PHP, para quem já tem o resultado agregado por período) e sqlMinimo()
 * (um fragmento SQL, para quem agrega no banco por aluno sem carregar linhas).
 */
final class DesempenhoEsperado
{
    /** Mínimo (% da prova) de quem não tem meta por período: o limiar de sempre. */
    public const MINIMO_PADRAO = 60.0;

    /**
     * 0,005 = a precisão (2 casas) em que `resultado_resumos.percentual` é gravado: 59,996 não chega a ser 60, mas o valor
     * gravado, 60,00, é o que se compara.
     */
    public const TOLERANCIA = 0.005;

    /**
     * Questões válidas (com gabarito, fora as de distribuição de pontuação) de cada avaliação, agrupadas por período mínimo:
     * a fatia esperada de um período do curso sai daqui.
     *
     * @param  array<int, int>  $codigos
     * @return array<int, array{comMeta: bool, total: int, fatias: array<int, array{minimo: ?int, n: int}>}>  por código da avaliação
     */
    public static function provas(array $codigos): array
    {
        if ($codigos === []) {
            return [];
        }

        $linhas = Anulacao::excluirDistribuidas(
            DB::table('questoes')
                ->whereIn('avaliacao_codigo', $codigos)
                ->whereNull('deleted_at')
                ->whereNotNull('gabarito')->where('gabarito', '!=', '')
        )
            ->groupBy('avaliacao_codigo', 'periodo_minimo')
            ->selectRaw('avaliacao_codigo as codigo, periodo_minimo as minimo, COUNT(*) as n')
            ->get()
            ->groupBy(fn ($l) => (int) $l->codigo);

        $provas = [];
        foreach ($codigos as $codigo) {
            $fatias = $linhas->get((int) $codigo, collect())
                ->map(fn ($l) => ['minimo' => $l->minimo !== null ? (int) $l->minimo : null, 'n' => (int) $l->n])
                ->values()
                ->all();
            $total = array_sum(array_column($fatias, 'n'));

            $provas[(int) $codigo] = [
                'comMeta' => $total > 0 && array_filter($fatias, fn ($f) => $f['minimo'] !== null) !== [],
                'total' => $total,
                'fatias' => $fatias,
            ];
        }

        return $provas;
    }

    /**
     * O mínimo (% da prova) para o aluno de um período do curso (`$periodoDoAluno` = texto da planilha: "3º", "P3"...).
     *
     * @param  array{comMeta: bool, total: int, fatias: array<int, array{minimo: ?int, n: int}>}|null  $prova  uma linha de provas()
     */
    public static function minimo(?array $prova, ?string $periodoDoAluno): float
    {
        $ordinal = PeriodoCurso::ordinal($periodoDoAluno);
        if ($prova === null || ! $prova['comMeta'] || $ordinal === null) {
            return self::MINIMO_PADRAO;
        }

        $aFrente = 0;
        foreach ($prova['fatias'] as $f) {
            $aFrente += PeriodoCurso::aFrente($ordinal, $f['minimo']) ? $f['n'] : 0;
        }

        // A meta é 60% do que cabe no período do aluno (PeriodoCurso::metaDoPeriodo), não 100% dele.
        return PeriodoCurso::metaDoPeriodo($prova['total'] - $aFrente, $prova['total']);
    }

    /** O percentual gravado alcança o mínimo? (a tolerância é a precisão com que o percentual é gravado) */
    public static function alcancou(float $percentual, float $minimo): bool
    {
        return $percentual + self::TOLERANCIA >= $minimo;
    }

    /**
     * Fragmento SQL com o MÍNIMO de cada linha de `resultado_resumos` (um número, % da prova), para agregar no banco sem
     * carregar os resultados: `SUM(CASE WHEN rr.percentual + 0.005 >= {minimo} THEN 1 ELSE 0 END)`. Devolve também
     * os bindings, na ordem em que aparecem.
     *
     * Só as combinações (avaliação × período do curso) cujo mínimo difere do padrão viram `WHEN`; o resto cai no `ELSE`, então
     * recorte sem meta por período gera só o número 60.
     *
     * @param  array<int, int>  $codigos  avaliações do recorte
     * @return array{0: string, 1: array<int, mixed>}  [fragmento, bindings]
     */
    public static function sqlMinimo(array $codigos, string $colunaCodigo = 'rr.avaliacao_codigo', string $colunaPeriodo = 'rr.periodo'): array
    {
        $provas = self::provas($codigos);
        $comMeta = array_keys(array_filter($provas, fn ($p) => $p['comMeta']));
        if ($comMeta === []) {
            return [self::numeroSql(self::MINIMO_PADRAO), []];
        }

        $combinacoes = DB::table('resultado_resumos')
            ->whereIn('avaliacao_codigo', $comMeta)
            ->groupBy('avaliacao_codigo', 'periodo')
            ->get(['avaliacao_codigo', 'periodo']);

        // avaliação => mínimo => períodos (textos) que têm esse mínimo
        $grupos = [];
        foreach ($combinacoes as $c) {
            $periodo = (string) $c->periodo;
            $minimo = self::minimo($provas[(int) $c->avaliacao_codigo], $periodo);
            if (abs($minimo - self::MINIMO_PADRAO) > 1e-9) {
                $grupos[(int) $c->avaliacao_codigo][self::numeroSql($minimo)][] = $periodo;
            }
        }

        $sql = '';
        $bindings = [];
        foreach ($grupos as $codigo => $porMinimo) {
            foreach ($porMinimo as $minimo => $periodos) {
                $sql .= " WHEN {$colunaCodigo} = ? AND {$colunaPeriodo} IN (".implode(',', array_fill(0, count($periodos), '?')).") THEN {$minimo}";
                $bindings[] = $codigo;
                array_push($bindings, ...$periodos);
            }
        }

        return $sql === ''
            ? [self::numeroSql(self::MINIMO_PADRAO), []]
            : ['(CASE'.$sql.' ELSE '.self::numeroSql(self::MINIMO_PADRAO).' END)', $bindings];
    }

    /**
     * Fragmento booleano "este resumo alcançou o esperado" (ignora ausência e nota ausente — filtre no WHERE).
     *
     * @param  array<int, int>  $codigos
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function sqlAlcancou(array $codigos, string $colunaPercentual = 'rr.percentual', string $colunaCodigo = 'rr.avaliacao_codigo', string $colunaPeriodo = 'rr.periodo'): array
    {
        [$minimo, $bindings] = self::sqlMinimo($codigos, $colunaCodigo, $colunaPeriodo);

        return ["({$colunaPercentual} + ".self::numeroSql(self::TOLERANCIA)." >= {$minimo})", $bindings];
    }

    /** Float para dentro de SQL cru sempre com ponto decimal, independente do locale. */
    private static function numeroSql(float $valor): string
    {
        return rtrim(rtrim(sprintf('%.3F', $valor), '0'), '.') ?: '0';
    }
}
