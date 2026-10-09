<?php

namespace App\Services\Portal;

use App\Models\Aluno;
use App\Support\BloomExplicado;

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

    /** Questões mínimas de um nível de Bloom para ele valer como "o mais difícil" — com 1 ou 2 questões é sorte/azar. */
    private const MINIMO_QUESTOES_BLOOM = 3;

    /** Quantas quedas seguidas na mesma categoria já valem um alerta. */
    private const LIMIAR_QUEDAS_CONSECUTIVAS = 3;

    public function __construct(
        private readonly ResultadoConsultaService $consultaService,
        private readonly RelatorioAlunoService $relatorioService,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $resultados  já filtrados pelo período letivo selecionado
     * @param  array<int, array{categoria_nome: string, pontos: array<int, array{percentual: float}>}>  $evolucaoPorCategoria
     * @param  array<string, float>  $coberturaHabilidade  já ordenado do pior pro melhor (ver AnaliseConsolidadaService::coberturaHabilidade())
     * @param  array<string, array{percentual: float, total: int}>  $bloom  ver AnaliseConsolidadaService::bloomComContagem()
     * @return array<int, array{tom: string, icone: string, texto: string}>
     */
    public function gerar(
        Aluno $aluno,
        array $resultados,
        array $evolucaoPorCategoria,
        array $coberturaHabilidade,
        array $bloom = [],
    ): array {
        // Nenhum card compara o aluno com a turma (média, posição, "entre os X% melhores"): o boletim só mostra o
        // percentual dele e o que se espera para o período dele (ver LeituraMetaPeriodo).
        return [
            ...$this->variacoesPorAreaEntreUltimasAvaliacoes($aluno, $resultados),
            ...$this->quedaConsecutivaPorCategoria($evolucaoPorCategoria),
            ...$this->nivelDeBloomMaisDificil($bloom),
            ...$this->extremosHabilidade($coberturaHabilidade),
        ];
    }

    /**
     * "Seu rendimento em Farmacologia caiu de 62% para 50% desde o último
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
                        'de' => round($areaAnterior[$area], 1),
                        'para' => round($percentualRecente, 1),
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
                'texto' => "Seu rendimento em {$areaMaiorAlta} subiu de {$maiorAlta['de']}% para {$maiorAlta['para']}% de acerto desde a última avaliação ({$maiorAlta['deAvaliacao']} → {$maiorAlta['paraAvaliacao']}).",
            ];
        }

        $areaMaiorQueda = $areas[array_key_last($areas)];
        $maiorQueda = $variacoes[$areaMaiorQueda];
        if ($maiorQueda['delta'] <= -self::LIMIAR_VARIACAO_AREA) {
            $cartoes[] = [
                'tom' => 'atencao',
                'icone' => 'ph-trend-down',
                'texto' => "Seu rendimento em {$areaMaiorQueda} caiu de {$maiorQueda['de']}% para {$maiorQueda['para']}% de acerto desde a última avaliação ({$maiorQueda['deAvaliacao']} → {$maiorQueda['paraAvaliacao']}).",
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
                    'texto' => "Seu rendimento em {$categoria['categoria_nome']} caiu nas últimas ".self::LIMIAR_QUEDAS_CONSECUTIVAS.' avaliações seguidas — convém revisar o que mudou.',
                ];
            }
        }

        return $cartoes;
    }

    /**
     * "As questões que pedem para relacionar dados de um caso foram as mais difíceis para você" — traduz o nível de Bloom
     * de menor acerto em linguagem de estudante, com uma dica de como treinar (ver BloomExplicado). Só afirma quando há
     * mais de um nível com questões suficientes e uma diferença que não seja ruído.
     *
     * @param  array<string, array{percentual: float, total: int}>  $bloom
     * @return array<int, array{tom: string, icone: string, texto: string}>
     */
    private function nivelDeBloomMaisDificil(array $bloom): array
    {
        $niveis = array_filter($bloom, fn ($n) => $n['total'] >= self::MINIMO_QUESTOES_BLOOM);
        if (count($niveis) < 2) {
            return [];
        }

        $percentuais = array_map(fn ($n) => $n['percentual'], $niveis);
        $percentualPior = min($percentuais);
        $nomePior = (string) array_search($percentualPior, $percentuais, true);

        if (max($percentuais) - $percentualPior < self::LIMIAR_VARIACAO_AREA) {
            return [];
        }

        if ($percentualPior >= 70.0) {
            return [[
                'tom' => 'positivo',
                'icone' => 'ph-brain',
                'texto' => "Seu rendimento foi bom em todos os tipos de raciocínio cobrados — o mais difícil para você foi \"{$nomePior}\", ainda com {$percentualPior}% de acerto.",
            ]];
        }

        $explicado = BloomExplicado::para($nomePior);
        $texto = $explicado !== null
            ? "As questões que pedem para {$explicado['pede']} foram as mais difíceis para você ({$nomePior}: {$percentualPior}% de acerto). {$explicado['dica']}"
            : "As questões do tipo \"{$nomePior}\" foram as mais difíceis para você ({$percentualPior}% de acerto). Convém treinar esse tipo de questão com o professor ou o monitor.";

        return [['tom' => 'atencao', 'icone' => 'ph-brain', 'texto' => $texto]];
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
