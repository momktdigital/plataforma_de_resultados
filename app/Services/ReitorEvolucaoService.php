<?php

namespace App\Services;

use App\Support\CacheDeAnalise;
use App\Support\NomeCurso;
use App\Support\PeriodoCurso;
use App\Support\Previstos;
use Illuminate\Support\Facades\DB;

/**
 * Evolução institucional entre períodos letivos ("estamos melhorando de um semestre para o outro?").
 *
 * Só compara avaliações da MESMA categoria (provas de categorias diferentes não são comparáveis — ver
 * CoordenadorDashboardService). Com o recorte por categoria, cada semestre reúne TODAS as avaliações da categoria
 * (a mesma prova nos vários cursos); com uma avaliação avulsa, vale a escolhida no semestre dela e a de mais
 * participantes nos demais — em qualquer caso o ponto do semestre em foco bate com o resto das telas.
 *
 * Os indicadores são os que saem de uma só consulta agregada (participação, média, proficiência); a mediana e os
 * quartis precisam do histograma e ficam nas telas de um semestre só. Avaliação sem categoria não tem série: ela
 * é só ela mesma.
 *
 * É uma foto de cada semestre, NÃO o acompanhamento da mesma turma: os estudantes de um semestre e do outro são
 * (em parte) pessoas diferentes.
 */
class ReitorEvolucaoService
{
    /** Variação (pontos percentuais) a partir da qual um curso "melhorou" ou "piorou" — abaixo disso é ruído. */
    public const VARIACAO_RELEVANTE = 3.0;

    /**
     * @param  array<string, mixed>  $ctx  saída de ReitorDashboardService::contexto()
     * @return array{categoria: string, semestres: array<int, array<string, mixed>>}
     */
    public function serie(array $ctx): array
    {
        $avaliacao = $ctx['avaliacao'];
        $corte = $ctx['corte'];
        $meta = $ctx['meta'];
        $selecionados = $ctx['cursosSelecionados'];

        $semestresDaSerie = $this->semestresDaSerie($ctx);
        if ($semestresDaSerie === []) {
            return ['categoria' => $avaliacao['categoria'], 'semestres' => []];
        }

        $codigos = array_values(array_unique(array_merge(...array_column($semestresDaSerie, 'codigos'))));
        $linhas = CacheDeAnalise::lembrarVarias('reitor-evolucao', $codigos, ['corte' => $corte], fn () => $this->agregar($codigos, $corte));

        $linhasPorAvaliacao = [];
        foreach ($linhas as $linha) {
            $linhasPorAvaliacao[$linha[0]][] = $linha;
        }

        $semestres = [];
        foreach ($semestresDaSerie as $semestre) {
            // Soma as avaliações do semestre por curso (em geral uma por curso; se houver mais, somam).
            $porCurso = [];
            foreach ($semestre['codigos'] as $codigo) {
                foreach ($linhasPorAvaliacao[$codigo] ?? [] as [, $curso, $periodo, $inscritos, $presentes, $comNota, $soma, $proficientes]) {
                    $chave = NomeCurso::chave($curso);
                    if (! in_array($chave, $selecionados, true)) {
                        continue;
                    }
                    $ordinal = PeriodoCurso::ordinal($periodo);
                    $c = &$porCurso[$chave];
                    $c ??= ['inscritos' => 0, 'presentes' => 0, 'comNota' => 0, 'soma' => 0.0, 'proficientes' => 0, 'ordinais' => [], 'semPeriodo' => 0];
                    $c['inscritos'] += $inscritos;
                    $c['presentes'] += $presentes;
                    $c['comNota'] += $comNota;
                    $c['soma'] += $soma;
                    $c['proficientes'] += $proficientes;
                    if ($ordinal === null) {
                        $c['semPeriodo'] += $inscritos;
                    } else {
                        $c['ordinais'][$ordinal] = ($c['ordinais'][$ordinal] ?? 0) + $inscritos;
                    }
                    unset($c);
                }
            }

            if ($porCurso === []) {
                continue;
            }

            $matriculas = Previstos::matriculasVigentes($semestre['periodoLetivo']);
            $cursos = [];
            $totais = ['previstos' => 0, 'presentes' => 0, 'comNota' => 0, 'soma' => 0.0, 'proficientes' => 0];

            foreach ($porCurso as $chave => $c) {
                $previsao = Previstos::calcular($matriculas[$chave]['ordinais'] ?? [], $c['ordinais'], $c['semPeriodo']);
                $cursos[$chave] = $this->indicadores($previsao['previstos'], $c['presentes'], $c['comNota'], $c['soma'], $c['proficientes'], $meta);
                $totais['previstos'] += $previsao['previstos'];
                $totais['presentes'] += $c['presentes'];
                $totais['comNota'] += $c['comNota'];
                $totais['soma'] += $c['soma'];
                $totais['proficientes'] += $c['proficientes'];
            }

            $semestres[] = [
                'periodoLetivo' => $semestre['periodoLetivo'],
                'avaliacao' => ['codigo' => $semestre['codigos'][0], 'codigos' => $semestre['codigos'], 'nome' => $semestre['nome']],
                'ehSelecionada' => $semestre['selecionada'],
                'total' => $this->indicadores($totais['previstos'], $totais['presentes'], $totais['comNota'], $totais['soma'], $totais['proficientes'], $meta),
                'cursos' => $cursos,
            ];
        }

        return ['categoria' => $avaliacao['categoria'], 'semestres' => $semestres];
    }

    /**
     * Os semestres da série, do mais antigo ao mais novo, cada um com as avaliações que valem nele:
     *  - categoria escolhida (ou "todas"): em cada período letivo, as avaliações dela (a mesma prova nos cursos);
     *  - uma avaliação avulsa de categoria conhecida: ela, no semestre dela; nos demais, a de mais participantes;
     *  - avulsa sem categoria, ou recorte sem período letivo: só ele mesmo — nada diz que outras são a mesma prova.
     *
     * @param  array<string, mixed>  $ctx
     * @return array<int, array{periodoLetivo: string, codigos: array<int, int>, nome: string, selecionada: bool}>
     */
    private function semestresDaSerie(array $ctx): array
    {
        $avaliacao = $ctx['avaliacao'];
        $propria = [['periodoLetivo' => (string) $avaliacao['periodoLetivo'], 'codigos' => $avaliacao['codigos'], 'nome' => $avaliacao['nome'], 'selecionada' => true]];

        if ($avaliacao['periodoLetivo'] === '' || ($avaliacao['avulsa'] && $avaliacao['categoriaId'] === null)) {
            return $propria;
        }

        $serie = [];
        $porPeriodo = $ctx['avaliacoes']
            ->filter(fn ($a) => $a['periodoLetivo'] !== ''
                && ($avaliacao['avulsa']
                    ? $a['categoriaId'] === $avaliacao['categoriaId']
                    : ($avaliacao['categoriaIds'] === null || in_array((int) ($a['categoriaId'] ?? 0), $avaliacao['categoriaIds'], true))))
            ->groupBy('periodoLetivo');

        foreach ($porPeriodo as $periodo => $grupo) {
            $periodo = (string) $periodo;
            if ($periodo === $avaliacao['periodoLetivo']) {
                $serie[$periodo] = $propria[0];
            } elseif (! $avaliacao['avulsa']) {
                $serie[$periodo] = ['periodoLetivo' => $periodo, 'codigos' => $grupo->pluck('codigo')->all(), 'nome' => $grupo->count() === 1 ? $grupo->first()['nome'] : $avaliacao['categoria'].' · '.$grupo->count().' avaliações', 'selecionada' => false];
            } else {
                $maior = $grupo->sortByDesc('presentes')->first();
                $serie[$periodo] = ['periodoLetivo' => $periodo, 'codigos' => [$maior['codigo']], 'nome' => $maior['nome'], 'selecionada' => false];
            }
        }
        ksort($serie);

        return array_values($serie);
    }

    /**
     * Uma consulta para todas as avaliações da série: por (avaliação, curso, período do curso).
     *
     * @param  array<int, int>  $codigos
     * @return array<int, array{0: int, 1: string, 2: string, 3: int, 4: int, 5: int, 6: float, 7: int}>
     */
    private function agregar(array $codigos, float $corte): array
    {
        return DB::table('resultado_resumos as rr')
            ->whereIn('rr.avaliacao_codigo', $codigos)
            ->whereNotNull('rr.curso')->where('rr.curso', '!=', '')
            ->groupBy('rr.avaliacao_codigo', 'rr.curso', 'rr.periodo')
            ->selectRaw('rr.avaliacao_codigo as codigo, rr.curso as curso, rr.periodo as periodo, COUNT(*) as inscritos')
            ->selectRaw('SUM(CASE WHEN rr.ausente = 0 THEN 1 ELSE 0 END) as presentes')
            ->selectRaw('SUM(CASE WHEN rr.ausente = 0 AND rr.percentual IS NOT NULL THEN 1 ELSE 0 END) as com_nota')
            ->selectRaw('SUM(CASE WHEN rr.ausente = 0 AND rr.percentual IS NOT NULL THEN rr.percentual ELSE 0 END) as soma')
            ->selectRaw('SUM(CASE WHEN rr.ausente = 0 AND rr.percentual IS NOT NULL AND rr.percentual >= ? THEN 1 ELSE 0 END) as proficientes', [$corte])
            ->get()
            ->map(fn ($l) => [(int) $l->codigo, (string) $l->curso, (string) $l->periodo, (int) $l->inscritos, (int) $l->presentes, (int) $l->com_nota, (float) $l->soma, (int) $l->proficientes])
            ->all();
    }

    /** @return array<string, mixed> */
    private function indicadores(int $previstos, int $presentes, int $comNota, float $soma, int $proficientes, float $meta): array
    {
        $previstos = max($previstos, $presentes);
        $participacao = $previstos > 0 ? round($presentes / $previstos * 100, 1) : null;

        return [
            'previstos' => $previstos,
            'fizeram' => $presentes,
            'participacao' => $participacao,
            'metaAtingida' => $participacao !== null && $participacao >= $meta,
            'n' => $comNota,
            'media' => $comNota > 0 ? round($soma / $comNota, 1) : null,
            'proficientes' => $proficientes,
            'proficienciaPct' => $comNota > 0 ? round($proficientes / $comNota * 100, 1) : null,
        ];
    }
}
