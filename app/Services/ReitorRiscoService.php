<?php

namespace App\Services;

use App\Support\CacheDeAnalise;
use App\Support\NomeCurso;
use App\Support\PeriodoCurso;
use Illuminate\Support\Facades\DB;

/**
 * Estudantes em risco, EM AGREGADO (nunca nomes — a lista nominal é do coordenador): quantos estudantes de cada curso
 * faltaram a duas ou mais aplicações ou ficaram abaixo do critério em todas as aplicações em que estiveram presentes.
 *
 * A pessoa é o estudante: `aluno_id` quando o resultado está ligado ao cadastro, senão a chave (CPF/RA) — um estudante
 * com chaves diferentes em avaliações diferentes e sem vínculo conta como pessoas diferentes (subestima a recorrência;
 * ver CLAUDE.md sobre `aluno_chave`). As contas são só em SQL sobre `resultado_resumos`.
 *
 *  - ELEGÍVEL: tem resultado em DUAS ou mais aplicações do recorte (sem isso não existe "recorrente"). Os percentuais
 *    são sobre os elegíveis; por isso a tela faz sentido com uma categoria de várias avaliações (os simulados) ou com
 *    "Todos os períodos" — num Diagnóstico de uma aplicação por semestre ela fica vazia, e a tela avisa.
 *  - AUSÊNCIA RECORRENTE: prova inteira em branco em duas ou mais aplicações.
 *  - BAIXO DESEMPENHO PERSISTENTE: presente em duas ou mais e abaixo do critério em TODAS em que esteve presente.
 *  - EM RISCO: um ou outro.
 *
 * Percentual de um grupo com menos de MINIMO_ELEGIVEIS pessoas não é mostrado (null): numa turma pequena o percentual
 * identifica gente.
 */
class ReitorRiscoService
{
    public const MINIMO_ELEGIVEIS = 5;

    public function __construct(private readonly ReitorEvolucaoService $evolucao) {}

    /**
     * @param  array<string, mixed>  $ctx  saída de ReitorDashboardService::contexto()
     * @return array<string, mixed>
     */
    public function gerar(array $ctx): array
    {
        $nomes = $ctx['cursosDisponiveis'];
        $selecionados = $ctx['cursosSelecionados'];

        $agora = $this->consolidar($this->linhas($ctx['avaliacao']['codigos'], $ctx['corte']), $selecionados);

        // Tendência: o mesmo cálculo no semestre anterior da série (só quando o recorte é de UM semestre).
        $anterior = null;
        $rotuloAnterior = null;
        if (empty($ctx['avaliacao']['todosPeriodos']) && empty($ctx['avaliacao']['avulsa'])) {
            $serie = $this->evolucao->semestresDaSerie($ctx);
            $indice = collect($serie)->search(fn ($s) => $s['selecionada']);
            if ($indice !== false && $indice > 0) {
                $previo = $serie[$indice - 1];
                $anterior = $this->consolidar($this->linhas($previo['codigos'], $ctx['corte']), $selecionados);
                $rotuloAnterior = $previo['periodoLetivo'];
            }
        }

        $cursos = [];
        foreach ($nomes as $chave => $nome) {
            if (! isset($agora['cursos'][$chave])) {
                continue;
            }
            $c = $agora['cursos'][$chave];
            $antes = $anterior['cursos'][$chave] ?? null;
            $cursos[$chave] = ['chave' => $chave, 'nome' => $nome, ...$this->indicadores($c)]
                + [
                    'periodos' => array_map(fn ($p) => ['ordinal' => $p['ordinal'], 'rotulo' => $p['ordinal'].'º', ...$this->indicadores($p)], $c['periodos']),
                    'anterior' => $antes !== null ? $this->indicadores($antes) : null,
                    'deltaRisco' => $antes !== null ? $this->delta($this->indicadores($c)['pctRisco'], $this->indicadores($antes)['pctRisco']) : null,
                ];
        }

        $total = ['chave' => '__total', 'nome' => 'Total da visão', ...$this->indicadores($agora['total'])];
        $total['anterior'] = $anterior !== null ? $this->indicadores($anterior['total']) : null;
        $total['deltaRisco'] = $total['anterior'] !== null ? $this->delta($total['pctRisco'], $total['anterior']['pctRisco']) : null;

        return [
            'cursos' => $cursos,
            'total' => $total,
            'temRecorrencia' => $agora['total']['elegiveis'] >= self::MINIMO_ELEGIVEIS,
            'semestreAnterior' => $rotuloAnterior,
            'periodos' => collect($cursos)->flatMap(fn ($c) => array_keys($c['periodos']))->unique()->sort()->values()->all(),
            'minimo' => self::MINIMO_ELEGIVEIS,
        ];
    }

    private function delta(?float $atual, ?float $antes): ?float
    {
        return $atual !== null && $antes !== null ? round($atual - $antes, 1) : null;
    }

    /**
     * @param  array{pessoas: int, elegiveis: int, recorrente: int, persistente: int, risco: int}  $g
     * @return array<string, mixed>
     */
    private function indicadores(array $g): array
    {
        $ok = $g['elegiveis'] >= self::MINIMO_ELEGIVEIS;
        $pct = fn (int $n) => $ok ? round($n / $g['elegiveis'] * 100, 1) : null;

        return [
            'pessoas' => $g['pessoas'],
            'elegiveis' => $g['elegiveis'],
            'recorrente' => $g['recorrente'],
            'persistente' => $g['persistente'],
            'risco' => $g['risco'],
            'pctRecorrente' => $pct($g['recorrente']),
            'pctPersistente' => $pct($g['persistente']),
            'pctRisco' => $pct($g['risco']),
        ];
    }

    /**
     * Soma as linhas (curso × período do curso) dos cursos selecionados: por curso, por período do curso e no total.
     *
     * @param  array<int, array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int, 6: int}>  $linhas
     * @param  array<int, string>  $selecionados
     * @return array{cursos: array<string, array<string, mixed>>, total: array<string, int>}
     */
    private function consolidar(array $linhas, array $selecionados): array
    {
        $vazio = ['pessoas' => 0, 'elegiveis' => 0, 'recorrente' => 0, 'persistente' => 0, 'risco' => 0];
        $cursos = [];
        $total = $vazio;

        foreach ($linhas as [$curso, $periodo, $pessoas, $elegiveis, $recorrente, $persistente, $risco]) {
            $chave = NomeCurso::chave($curso);
            if (! in_array($chave, $selecionados, true)) {
                continue;
            }
            $dados = ['pessoas' => $pessoas, 'elegiveis' => $elegiveis, 'recorrente' => $recorrente, 'persistente' => $persistente, 'risco' => $risco];
            $cursos[$chave] ??= [...$vazio, 'periodos' => []];
            foreach ($dados as $campo => $valor) {
                $cursos[$chave][$campo] += $valor;
                $total[$campo] += $valor;
            }
            if (($ordinal = PeriodoCurso::ordinal($periodo)) !== null) {
                $cursos[$chave]['periodos'][$ordinal] ??= [...$vazio, 'ordinal' => $ordinal];
                foreach ($dados as $campo => $valor) {
                    $cursos[$chave]['periodos'][$ordinal][$campo] += $valor;
                }
            }
        }
        foreach ($cursos as &$c) {
            ksort($c['periodos']);
        }
        unset($c);

        return ['cursos' => $cursos, 'total' => $total];
    }

    /**
     * Uma consulta agregada (cacheada): por (curso, período do curso), quantas pessoas, quantas elegíveis e quantas
     * em cada situação. A pessoa é agrupada antes (uma linha por pessoa × curso) e só então contada.
     *
     * @param  array<int, int>  $codigos
     * @return array<int, array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int, 6: int}>
     */
    private function linhas(array $codigos, float $corte): array
    {
        return CacheDeAnalise::lembrarVarias('reitor-risco', $codigos, ['corte' => $corte], function () use ($codigos, $corte) {
            $pessoa = DB::getDriverName() === 'sqlite'
                ? "CASE WHEN rr.aluno_id IS NOT NULL THEN 'a' || rr.aluno_id ELSE 'k' || rr.aluno_chave END"
                : "CASE WHEN rr.aluno_id IS NOT NULL THEN CONCAT('a', rr.aluno_id) ELSE CONCAT('k', rr.aluno_chave) END";

            $porPessoa = DB::table('resultado_resumos as rr')
                ->whereIn('rr.avaliacao_codigo', $codigos)
                ->whereNotNull('rr.curso')->where('rr.curso', '!=', '')
                ->groupByRaw('1, 2')
                ->selectRaw("{$pessoa} as pessoa, rr.curso as curso, MAX(rr.periodo) as periodo")
                ->selectRaw('SUM(CASE WHEN rr.ausente <> 0 THEN 1 ELSE 0 END) as faltas')
                ->selectRaw('SUM(CASE WHEN rr.ausente = 0 THEN 1 ELSE 0 END) as presentes')
                ->selectRaw('SUM(CASE WHEN rr.ausente = 0 AND rr.percentual IS NOT NULL AND rr.percentual < ? THEN 1 ELSE 0 END) as abaixo', [$corte]);

            $persistente = '(p.presentes >= 2 AND p.abaixo = p.presentes)';

            return DB::query()->fromSub($porPessoa, 'p')
                ->groupBy('p.curso', 'p.periodo')
                ->selectRaw('p.curso as curso, p.periodo as periodo, COUNT(*) as pessoas')
                ->selectRaw('SUM(CASE WHEN p.faltas + p.presentes >= 2 THEN 1 ELSE 0 END) as elegiveis')
                ->selectRaw('SUM(CASE WHEN p.faltas >= 2 THEN 1 ELSE 0 END) as recorrente')
                ->selectRaw("SUM(CASE WHEN {$persistente} THEN 1 ELSE 0 END) as persistente")
                ->selectRaw("SUM(CASE WHEN p.faltas >= 2 OR {$persistente} THEN 1 ELSE 0 END) as risco")
                ->get()
                ->map(fn ($l) => [(string) $l->curso, (string) $l->periodo, (int) $l->pessoas, (int) $l->elegiveis, (int) $l->recorrente, (int) $l->persistente, (int) $l->risco])
                ->all();
        });
    }
}
