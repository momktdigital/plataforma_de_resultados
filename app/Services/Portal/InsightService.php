<?php

namespace App\Services\Portal;

use App\Models\Aluno;

/**
 * Transforma os agregados já calculados por RelatorioAlunoService/
 * AnaliseConsolidadaService em cards de texto acionáveis — "insights" no
 * sentido de PortalController::renderizarResultados() já ter todo o número
 * pronto; aqui só decide QUAIS variações são grandes o suficiente pra virar
 * frase e como redigir. Nenhum cálculo numérico novo aqui além da
 * comparação de área entre as duas avaliações mais recentes de cada
 * categoria (única coisa que ainda não existia como agregado).
 */
class InsightService
{
    /** Variação de pontos percentuais numa área, entre duas avaliações, pra virar insight — abaixo disso é ruído normal de prova pra prova. */
    private const LIMIAR_VARIACAO_AREA = 8.0;

    /** Quantas quedas seguidas na mesma categoria já valem um alerta. */
    private const LIMIAR_QUEDAS_CONSECUTIVAS = 3;

    public function __construct(
        private readonly ResultadoConsultaService $consultaService,
        private readonly RelatorioAlunoService $relatorioService,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $resultados  já filtrados pelo período letivo selecionado
     * @param  array<int, array{categoria_nome: string, pontos: array<int, array{percentual: float}>}>  $evolucaoPorCategoria
     * @param  array{turma: string, suaMedia: float, mediaTurma: float, avaliacoesComparadas: int}|null  $comparativoTurmaConsolidado
     * @param  array<string, float>  $coberturaHabilidade  já ordenado do pior pro melhor (ver AnaliseConsolidadaService::coberturaHabilidade())
     * @return array<int, array{tom: string, icone: string, texto: string}>
     */
    public function gerar(
        Aluno $aluno,
        array $resultados,
        array $evolucaoPorCategoria,
        ?array $comparativoTurmaConsolidado,
        array $coberturaHabilidade,
    ): array {
        return [
            ...$this->variacoesPorAreaEntreUltimasAvaliacoes($aluno, $resultados),
            ...$this->quedaConsecutivaPorCategoria($evolucaoPorCategoria),
            ...$this->comparativoTurma($comparativoTurmaConsolidado),
            ...$this->percentilConsolidado($aluno, $resultados),
            ...$this->extremosHabilidade($coberturaHabilidade),
        ];
    }

    /**
     * "Seu desempenho em Farmacologia caiu 12 pontos desde o último
     * simulado" — compara, DENTRO DE CADA CATEGORIA (nunca entre categorias
     * diferentes, mesmo problema do gráfico de evolução original), as duas
     * avaliações mais recentes e olha a variação por área entre elas.
     * Destaca só a maior alta e a maior queda entre todas as categorias,
     * pra não inundar a tela de cards quando o aluno tem várias categorias.
     *
     * @param  array<int, array<string, mixed>>  $resultados
     * @return array<int, array{tom: string, icone: string, texto: string}>
     */
    private function variacoesPorAreaEntreUltimasAvaliacoes(Aluno $aluno, array $resultados): array
    {
        $porCategoria = collect($resultados)
            ->filter(fn ($r) => $r['avaliacao']->categoria_id !== null && $r['avaliacao']->data_avaliacao !== null)
            ->groupBy(fn ($r) => $r['avaliacao']->categoria_id);

        $variacoes = [];

        foreach ($porCategoria as $doCategoria) {
            $ordenados = $doCategoria->sortByDesc(fn ($r) => $r['avaliacao']->data_avaliacao->format('Y-m-d'))->values();
            if ($ordenados->count() < 2) {
                continue;
            }

            $recente = $ordenados[0];
            $anterior = $ordenados[1];

            $dadosRecente = $this->consultaService->buscarUmaAvaliacao($aluno, $recente['avaliacao']->codigo, $recente['periodo']);
            $dadosAnterior = $this->consultaService->buscarUmaAvaliacao($aluno, $anterior['avaliacao']->codigo, $anterior['periodo']);
            if ($dadosRecente === null || $dadosAnterior === null) {
                continue;
            }

            $areaRecente = $this->relatorioService->desempenhoPorArea($dadosRecente['respostas'], $dadosRecente['gabaritos'], $dadosRecente['avaliacao']);
            $areaAnterior = $this->relatorioService->desempenhoPorArea($dadosAnterior['respostas'], $dadosAnterior['gabaritos'], $dadosAnterior['avaliacao']);

            foreach (array_intersect_key($areaRecente, $areaAnterior) as $area => $percentualRecente) {
                $delta = round($percentualRecente - $areaAnterior[$area], 1);

                // Entre categorias diferentes que tenham a mesma área, guarda
                // só a variação mais expressiva (maior módulo) já vista.
                if (! isset($variacoes[$area]) || abs($delta) > abs($variacoes[$area]['delta'])) {
                    $variacoes[$area] = [
                        'delta' => $delta,
                        'deAvaliacao' => $anterior['avaliacao']->nome ?? "Avaliação #{$anterior['avaliacao']->codigo}",
                        'paraAvaliacao' => $recente['avaliacao']->nome ?? "Avaliação #{$recente['avaliacao']->codigo}",
                    ];
                }
            }
        }

        if (empty($variacoes)) {
            return [];
        }

        $areas = array_keys($variacoes);
        usort($areas, fn ($a, $b) => $variacoes[$b]['delta'] <=> $variacoes[$a]['delta']);

        $cartoes = [];

        $areaMaiorAlta = $areas[array_key_first($areas)];
        $maiorAlta = $variacoes[$areaMaiorAlta];
        if ($maiorAlta['delta'] >= self::LIMIAR_VARIACAO_AREA) {
            $cartoes[] = [
                'tom' => 'positivo',
                'icone' => 'ph-trend-up',
                'texto' => "Seu desempenho em {$areaMaiorAlta} subiu {$maiorAlta['delta']} pontos desde a última avaliação ({$maiorAlta['deAvaliacao']} → {$maiorAlta['paraAvaliacao']}).",
            ];
        }

        $areaMaiorQueda = $areas[array_key_last($areas)];
        $maiorQueda = $variacoes[$areaMaiorQueda];
        if ($maiorQueda['delta'] <= -self::LIMIAR_VARIACAO_AREA) {
            $cartoes[] = [
                'tom' => 'atencao',
                'icone' => 'ph-trend-down',
                'texto' => "Seu desempenho em {$areaMaiorQueda} caiu ".abs($maiorQueda['delta'])." pontos desde a última avaliação ({$maiorQueda['deAvaliacao']} → {$maiorQueda['paraAvaliacao']}).",
            ];
        }

        return $cartoes;
    }

    /**
     * Alerta de risco: categoria caindo há N avaliações seguidas — sinal
     * mais forte que uma queda pontual entre duas provas.
     *
     * @param  array<int, array{categoria_nome: string, pontos: array<int, array{percentual: float}>}>  $evolucaoPorCategoria
     * @return array<int, array{tom: string, icone: string, texto: string}>
     */
    private function quedaConsecutivaPorCategoria(array $evolucaoPorCategoria): array
    {
        $cartoes = [];

        foreach ($evolucaoPorCategoria as $categoria) {
            $percentuais = array_column($categoria['pontos'], 'percentual');
            if (count($percentuais) < self::LIMIAR_QUEDAS_CONSECUTIVAS + 1) {
                continue;
            }

            $ultimos = array_slice($percentuais, -(self::LIMIAR_QUEDAS_CONSECUTIVAS + 1));
            $emQuedaContinua = true;
            for ($i = 1; $i < count($ultimos); $i++) {
                if ($ultimos[$i] >= $ultimos[$i - 1]) {
                    $emQuedaContinua = false;
                    break;
                }
            }

            if ($emQuedaContinua) {
                $cartoes[] = [
                    'tom' => 'atencao',
                    'icone' => 'ph-chart-line-down',
                    'texto' => "Seu desempenho em {$categoria['categoria_nome']} caiu nas últimas ".self::LIMIAR_QUEDAS_CONSECUTIVAS.' avaliações seguidas — vale revisar o que mudou.',
                ];
            }
        }

        return $cartoes;
    }

    /** @param  array{turma: string, suaMedia: float, mediaTurma: float, avaliacoesComparadas: int}|null  $comparativoTurmaConsolidado
     * @return array<int, array{tom: string, icone: string, texto: string}> */
    private function comparativoTurma(?array $comparativoTurmaConsolidado): array
    {
        if ($comparativoTurmaConsolidado === null) {
            return [];
        }

        $diferenca = round($comparativoTurmaConsolidado['suaMedia'] - $comparativoTurmaConsolidado['mediaTurma'], 1);
        if (abs($diferenca) < 1.0) {
            return [];
        }

        $turma = $comparativoTurmaConsolidado['turma'];

        if ($diferenca > 0) {
            return [[
                'tom' => 'positivo',
                'icone' => 'ph-trophy',
                'texto' => "Você está {$diferenca} pontos acima da média da turma {$turma} neste período.",
            ]];
        }

        return [[
            'tom' => 'atencao',
            'icone' => 'ph-warning-circle',
            'texto' => 'Você está '.abs($diferenca)." pontos abaixo da média da turma {$turma} neste período — vale reforçar os temas com menor aproveitamento.",
        ]];
    }

    /**
     * "Você está entre os 20% melhores da turma" — versão do período inteiro
     * (não de uma área específica). Só aparece quando é uma notícia boa: fica
     * quieto quando o aluno está abaixo da mediana, pra não duplicar o alerta
     * já dado por comparativoTurma().
     *
     * @param  array<int, array<string, mixed>>  $resultados
     * @return array<int, array{tom: string, icone: string, texto: string}>
     */
    private function percentilConsolidado(Aluno $aluno, array $resultados): array
    {
        $percentil = $this->relatorioService->percentilConsolidado($aluno, $resultados);
        if ($percentil === null || $percentil['avaliacoesComparadas'] < 2) {
            return [];
        }

        $topPercent = (int) round(100 - $percentil['percentil']);
        if ($topPercent > 50) {
            return [];
        }

        return [[
            'tom' => 'positivo',
            'icone' => 'ph-medal',
            'texto' => "Você está entre os {$topPercent}% melhores da turma neste período, considerando {$percentil['avaliacoesComparadas']} avaliação(ões) comparável(eis).",
        ]];
    }

    /**
     * @param  array<string, float>  $coberturaHabilidade  já ordenado do pior pro melhor
     * @return array<int, array{tom: string, icone: string, texto: string}>
     */
    private function extremosHabilidade(array $coberturaHabilidade): array
    {
        if (count($coberturaHabilidade) < 2) {
            return [];
        }

        $habilidades = array_keys($coberturaHabilidade);
        $pior = $habilidades[array_key_first($habilidades)];
        $melhor = $habilidades[array_key_last($habilidades)];

        $cartoes = [];

        if ($coberturaHabilidade[$pior] < 60.0) {
            $cartoes[] = [
                'tom' => 'atencao',
                'icone' => 'ph-target',
                'texto' => "Sua habilidade com menor aproveitamento no período é \"{$pior}\", com {$coberturaHabilidade[$pior]}% de acerto.",
            ];
        }

        if ($coberturaHabilidade[$melhor] >= 80.0) {
            $cartoes[] = [
                'tom' => 'positivo',
                'icone' => 'ph-star',
                'texto' => "Você tem ótimo domínio em \"{$melhor}\", com {$coberturaHabilidade[$melhor]}% de acerto — a maior do período.",
            ];
        }

        return $cartoes;
    }
}
