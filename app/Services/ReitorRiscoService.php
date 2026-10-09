<?php

namespace App\Services;

use App\Models\Avaliacao;
use App\Support\CacheDeAnalise;
use App\Support\NomeCurso;
use App\Support\PeriodoCurso;
use App\Support\RegraDeRisco;
use Illuminate\Support\Facades\DB;

/**
 * Estudantes em risco, EM AGREGADO (nunca nomes — a lista nominal é do coordenador): quantos estudantes de cada curso
 * se enquadram na regra de risco da instituição (`RegraDeRisco`: média de acerto abaixo de X% e/ou faltas, combinadas por
 * "ou"/"e"; cada avaliação pode sobrepor o limite de acerto e dispensar a falta). É a MESMA regra da lista de alunos em
 * atenção do coordenador, então o número daqui e a lista do curso contam as mesmas pessoas.
 *
 * A pessoa é o estudante: `aluno_id` quando o resultado está ligado ao cadastro, senão a chave (CPF/RA) — um estudante
 * com chaves diferentes em avaliações diferentes e sem vínculo conta como pessoas diferentes (ver CLAUDE.md sobre
 * `aluno_chave`). As contas são só em SQL sobre `resultado_resumos`.
 *
 *  - POR FALTA: faltou a N ou mais aplicações do recorte (prova inteira em branco; avaliações que dispensam a falta não contam).
 *  - POR ACERTO: média de acerto nas provas em que esteve presente abaixo do limite (média dos limites das provas, se
 *    cada uma tiver o seu).
 *  - EM RISCO: a regra combinada (os dois indicadores acima podem coexistir na mesma pessoa; o "em risco" conta uma vez).
 *
 * Percentual de um grupo com menos de MINIMO_PESSOAS pessoas não é mostrado (null): numa turma pequena o percentual
 * identifica gente.
 */
class ReitorRiscoService
{
    public const MINIMO_PESSOAS = 5;

    public function __construct(private readonly ReitorEvolucaoService $evolucao) {}

    /**
     * @param  array<string, mixed>  $ctx  saída de ReitorDashboardService::contexto()
     * @return array<string, mixed>
     */
    public function gerar(array $ctx): array
    {
        $nomes = $ctx['cursosDisponiveis'];
        $selecionados = $ctx['cursosSelecionados'];
        $regra = RegraDeRisco::atual();

        $agora = $this->consolidar($this->linhas($ctx['avaliacao']['codigos'], $regra), $selecionados);

        // Tendência: o mesmo cálculo no semestre anterior da série (só quando o recorte é de UM semestre).
        $anterior = null;
        $rotuloAnterior = null;
        if (empty($ctx['avaliacao']['todosPeriodos']) && empty($ctx['avaliacao']['avulsa'])) {
            $serie = $this->evolucao->semestresDaSerie($ctx);
            $indice = collect($serie)->search(fn ($s) => $s['selecionada']);
            if ($indice !== false && $indice > 0) {
                $previo = $serie[$indice - 1];
                $anterior = $this->consolidar($this->linhas($previo['codigos'], $regra), $selecionados);
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
            'temDados' => $agora['total']['pessoas'] >= self::MINIMO_PESSOAS,
            'semestreAnterior' => $rotuloAnterior,
            'periodos' => collect($cursos)->flatMap(fn ($c) => array_keys($c['periodos']))->unique()->sort()->values()->all(),
            'minimo' => self::MINIMO_PESSOAS,
            'regra' => [
                ...$regra->assinatura(),
                'descricao' => $regra->descricao(),
                'acertoAtivo' => $this->acertoAtivo($regra, $ctx['avaliacao']['codigos']),
                // Regra de faltas que ninguém pode atingir no recorte (ex.: 2 faltas com uma aplicação só): a tela avisa.
                'faltasInalcancavel' => $regra->faltas !== null && $agora['total']['maiorAplicacao'] < $regra->faltas,
            ],
        ];
    }

    private function delta(?float $atual, ?float $antes): ?float
    {
        return $atual !== null && $antes !== null ? round($atual - $antes, 1) : null;
    }

    /**
     * @param  array{pessoas: int, porFalta: int, porAcerto: int, risco: int}  $g
     * @return array<string, mixed>
     */
    private function indicadores(array $g): array
    {
        $ok = $g['pessoas'] >= self::MINIMO_PESSOAS;
        $pct = fn (int $n) => $ok ? round($n / $g['pessoas'] * 100, 1) : null;

        return [
            'pessoas' => $g['pessoas'],
            'porFalta' => $g['porFalta'],
            'porAcerto' => $g['porAcerto'],
            'risco' => $g['risco'],
            'pctPorFalta' => $pct($g['porFalta']),
            'pctPorAcerto' => $pct($g['porAcerto']),
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
        $vazio = ['pessoas' => 0, 'porFalta' => 0, 'porAcerto' => 0, 'risco' => 0];
        $cursos = [];
        $total = [...$vazio, 'maiorAplicacao' => 0];

        foreach ($linhas as [$curso, $periodo, $pessoas, $porFalta, $porAcerto, $risco, $aplicacoes]) {
            $chave = NomeCurso::chave($curso);
            if (! in_array($chave, $selecionados, true)) {
                continue;
            }
            $dados = ['pessoas' => $pessoas, 'porFalta' => $porFalta, 'porAcerto' => $porAcerto, 'risco' => $risco];
            $cursos[$chave] ??= [...$vazio, 'periodos' => []];
            foreach ($dados as $campo => $valor) {
                $cursos[$chave][$campo] += $valor;
                $total[$campo] += $valor;
            }
            $total['maiorAplicacao'] = max($total['maiorAplicacao'], $aplicacoes);
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
     * O critério de acerto vale quando há limite padrão OU alguma avaliação do recorte traz o limite dela (> 0).
     *
     * @param  array<int, int>  $codigos
     */
    private function acertoAtivo(RegraDeRisco $regra, array $codigos): bool
    {
        return $regra->acerto !== null
            || Avaliacao::whereIn('codigo', $codigos)->where('risco_acerto', '>', 0)->exists();
    }

    /**
     * Uma consulta agregada (cacheada): por (curso, período do curso), quantas pessoas e quantas em cada situação.
     * A pessoa é agrupada antes (uma linha por pessoa × curso) e só então contada.
     *
     * @param  array<int, int>  $codigos
     * @return array<int, array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int, 6: int}>
     */
    private function linhas(array $codigos, RegraDeRisco $regra): array
    {
        // A regra de cada avaliação (limite próprio, dispensa de falta) entra na chave: mudar uma delas não serve número velho.
        $proprias = Avaliacao::whereIn('codigo', $codigos)->orderBy('codigo')->get(['codigo', 'risco_acerto', 'risco_ignora_falta'])
            ->map(fn ($a) => $a->codigo.':'.($a->risco_acerto ?? '-').':'.(int) $a->risco_ignora_falta)->implode('|');
        $acertoAtivo = $this->acertoAtivo($regra, $codigos);

        return CacheDeAnalise::lembrarVarias('reitor-risco', $codigos, ['regra' => $regra->assinatura(), 'avaliacoes' => md5($proprias)], function () use ($codigos, $regra, $acertoAtivo) {
            $pessoa = DB::getDriverName() === 'sqlite'
                ? "CASE WHEN rr.aluno_id IS NOT NULL THEN 'a' || rr.aluno_id ELSE 'k' || rr.aluno_chave END"
                : "CASE WHEN rr.aluno_id IS NOT NULL THEN CONCAT('a', rr.aluno_id) ELSE CONCAT('k', rr.aluno_chave) END";

            // Limite de acerto de cada resultado: o da avaliação (0 = fora do critério), senão o padrão da instituição.
            $padrao = $regra->acerto === null ? 'NULL' : number_format($regra->acerto, 2, '.', '');
            $limite = "(CASE WHEN av.risco_acerto IS NOT NULL THEN (CASE WHEN av.risco_acerto > 0 THEN av.risco_acerto ELSE NULL END) ELSE {$padrao} END)";
            $conta = "(rr.ausente = 0 AND rr.percentual IS NOT NULL AND {$limite} IS NOT NULL)";

            $porPessoa = DB::table('resultado_resumos as rr')
                ->join('avaliacoes as av', 'av.codigo', '=', 'rr.avaliacao_codigo')
                ->whereIn('rr.avaliacao_codigo', $codigos)
                ->whereNotNull('rr.curso')->where('rr.curso', '!=', '')
                ->groupByRaw('1, 2')
                ->selectRaw("{$pessoa} as pessoa, rr.curso as curso, MAX(rr.periodo) as periodo")
                ->selectRaw('COUNT(*) as aplicacoes')
                ->selectRaw('SUM(CASE WHEN rr.ausente <> 0 AND COALESCE(av.risco_ignora_falta, 0) = 0 THEN 1 ELSE 0 END) as faltas')
                ->selectRaw("SUM(CASE WHEN {$conta} THEN 1 ELSE 0 END) as n_acerto")
                ->selectRaw("SUM(CASE WHEN {$conta} THEN rr.percentual ELSE 0 END) as soma_pc")
                ->selectRaw("SUM(CASE WHEN {$conta} THEN {$limite} ELSE 0 END) as soma_limite");

            $porFalta = $regra->faltas !== null ? '(p.faltas >= '.(int) $regra->faltas.')' : null;
            $porAcerto = $acertoAtivo ? '(p.n_acerto > 0 AND p.soma_pc < p.soma_limite)' : null;
            $criterios = array_values(array_filter([$porFalta, $porAcerto]));
            $risco = $criterios === [] ? '0 = 1' : implode($regra->operador === 'e' ? ' AND ' : ' OR ', $criterios);

            return DB::query()->fromSub($porPessoa, 'p')
                ->groupBy('p.curso', 'p.periodo')
                ->selectRaw('p.curso as curso, p.periodo as periodo, COUNT(*) as pessoas')
                ->selectRaw('SUM(CASE WHEN '.($porFalta ?? '0 = 1').' THEN 1 ELSE 0 END) as por_falta')
                ->selectRaw('SUM(CASE WHEN '.($porAcerto ?? '0 = 1').' THEN 1 ELSE 0 END) as por_acerto')
                ->selectRaw("SUM(CASE WHEN {$risco} THEN 1 ELSE 0 END) as risco")
                ->selectRaw('MAX(p.aplicacoes) as aplicacoes')
                ->get()
                ->map(fn ($l) => [(string) $l->curso, (string) $l->periodo, (int) $l->pessoas, (int) $l->por_falta, (int) $l->por_acerto, (int) $l->risco, (int) $l->aplicacoes])
                ->all();
        });
    }
}
