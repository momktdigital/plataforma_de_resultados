<?php

namespace App\Support;

/**
 * Fórmulas de análise de itens (psicometria clássica), sem nenhuma dependência
 * de banco — a parte que varre `respostas` fica em App\Services\PsicometriaService,
 * que agrega em SQL e só chama estas funções sobre os totais já somados.
 *
 * Duas medidas por questão:
 * - DISCRIMINAÇÃO (D): diferença entre o % de acerto dos 27% melhores e dos
 *   27% piores respondentes. Mede se a questão separa quem sabe de quem não
 *   sabe. D negativo é o sinal mais grave que existe: quem foi melhor na prova
 *   acertou MENOS aquela questão — quase sempre gabarito errado ou enunciado
 *   ambíguo. O corte de 27% é o clássico de Kelley: maximiza a diferença entre
 *   os grupos sem deixá-los pequenos demais.
 * - PONTO-BISSERIAL: correlação entre acertar a questão e a nota total. Mais
 *   estável que D (usa todos os respondentes, não só os extremos), mas menos
 *   intuitiva de explicar num colegiado — por isso as duas aparecem juntas.
 *
 * E uma medida da prova inteira:
 * - KR-20: consistência interna. Responde "as questões estão medindo a mesma
 *   coisa?". Abaixo de 0,70 a prova mede demais ao acaso para ranquear aluno.
 */
final class Psicometria
{
    public const FAIXA_OTIMO = 'otimo';

    public const FAIXA_BOM = 'bom';

    public const FAIXA_MARGINAL = 'marginal';

    public const FAIXA_REVISAR = 'revisar';

    /**
     * Faixas de Ebel para D, na ordem decrescente de qualidade — `minimo` é
     * inclusivo. Só mexa aqui com respaldo da literatura: esses cortes são a
     * referência que a comissão de prova usa para decidir o que sai do banco
     * de itens.
     *
     * @var array<string, array{minimo: float, rotulo: string, acao: string}>
     */
    public const FAIXAS = [
        self::FAIXA_OTIMO => [
            'minimo' => 0.40,
            'rotulo' => 'Ótimo',
            'acao' => 'Discrimina muito bem — manter no banco de itens.',
        ],
        self::FAIXA_BOM => [
            'minimo' => 0.30,
            'rotulo' => 'Bom',
            'acao' => 'Item bom, pode ser reaproveitado como está.',
        ],
        self::FAIXA_MARGINAL => [
            'minimo' => 0.20,
            'rotulo' => 'Marginal',
            'acao' => 'Funciona, mas vale revisar o enunciado e os distratores.',
        ],
        self::FAIXA_REVISAR => [
            'minimo' => -1.0,
            'rotulo' => 'Revisar',
            'acao' => 'Não separa quem sabe de quem não sabe — revisar ou descartar.',
        ],
    ];

    /** Abaixo disto o item entra na simulação de remoção (ver PsicometriaService::simularRemocao()). */
    public const D_MINIMO_ACEITAVEL = 0.20;

    /** Proporção de Kelley usada nos grupos superior/inferior. */
    public const FRACAO_GRUPO_EXTREMO = 0.27;

    public static function classificar(?float $d): string
    {
        if ($d === null) {
            return self::FAIXA_REVISAR;
        }

        foreach (self::FAIXAS as $chave => $faixa) {
            if ($d >= $faixa['minimo']) {
                return $chave;
            }
        }

        return self::FAIXA_REVISAR;
    }

    public static function rotulo(string $faixa): string
    {
        return self::FAIXAS[$faixa]['rotulo'] ?? self::FAIXAS[self::FAIXA_REVISAR]['rotulo'];
    }

    public static function acao(string $faixa): string
    {
        return self::FAIXAS[$faixa]['acao'] ?? self::FAIXAS[self::FAIXA_REVISAR]['acao'];
    }

    /**
     * D = p(grupo superior) - p(grupo inferior). Null quando algum dos dois
     * grupos ficou vazio (avaliação com pouquíssimos respondentes).
     */
    public static function discriminacao(int $acertosSuperior, int $totalSuperior, int $acertosInferior, int $totalInferior): ?float
    {
        if ($totalSuperior <= 0 || $totalInferior <= 0) {
            return null;
        }

        return round($acertosSuperior / $totalSuperior - $acertosInferior / $totalInferior, 4);
    }

    /**
     * r_pbis = ((M1 - M0) / desvio) * sqrt(p * q), onde M1/M0 são as notas
     * médias de quem acertou e de quem errou. Null quando a prova inteira tem
     * desvio zero (todo mundo com a mesma nota) ou quando ninguém acertou/errou
     * o item — nos três casos não há variação para correlacionar.
     */
    public static function pontoBisserial(?float $mediaAcertou, ?float $mediaErrou, float $desvioTotal, float $proporcaoAcerto): ?float
    {
        if ($mediaAcertou === null || $mediaErrou === null || $desvioTotal <= 0.0) {
            return null;
        }

        if ($proporcaoAcerto <= 0.0 || $proporcaoAcerto >= 1.0) {
            return null;
        }

        $r = ($mediaAcertou - $mediaErrou) / $desvioTotal * sqrt($proporcaoAcerto * (1 - $proporcaoAcerto));

        return round(max(-1.0, min(1.0, $r)), 4);
    }

    /**
     * KR-20 = (k / (k-1)) * (1 - Σ(p·q) / variância das notas totais).
     *
     * $somaPQ soma p·q de CADA questão considerada; $varianciaTotal é a
     * variância do total de acertos (não do percentual) dos respondentes.
     * Null com menos de 2 questões ou variância zero — sem variação entre
     * respondentes o coeficiente é indefinido, não zero.
     */
    public static function kr20(int $questoes, float $somaPQ, float $varianciaTotal): ?float
    {
        if ($questoes < 2 || $varianciaTotal <= 0.0) {
            return null;
        }

        $kr = ($questoes / ($questoes - 1)) * (1 - $somaPQ / $varianciaTotal);

        // Amostras pequenas com itens muito homogêneos produzem valores fora
        // de [0,1]; a convenção é reportar o limite, não o número impossível.
        return round(max(0.0, min(1.0, $kr)), 4);
    }

    /** @param array<int, float|int> $valores */
    public static function variancia(array $valores): float
    {
        $n = count($valores);
        if ($n < 2) {
            return 0.0;
        }

        $media = array_sum($valores) / $n;
        $soma = 0.0;
        foreach ($valores as $v) {
            $soma += ($v - $media) ** 2;
        }

        // Populacional (÷ n): os respondentes da avaliação são a população
        // inteira daquela prova, não uma amostra dela.
        return $soma / $n;
    }

    /** @param array<int, float|int> $valores */
    public static function desvioPadrao(array $valores): float
    {
        return sqrt(self::variancia($valores));
    }

    /** @param array<int, float|int> $valores */
    public static function mediana(array $valores): float
    {
        $n = count($valores);
        if ($n === 0) {
            return 0.0;
        }

        sort($valores);
        $meio = intdiv($n, 2);

        return $n % 2 === 1
            ? (float) $valores[$meio]
            : ((float) $valores[$meio - 1] + (float) $valores[$meio]) / 2;
    }

    /**
     * Percentil por interpolação linear sobre a lista JÁ ORDENADA de forma
     * crescente. $fracao vai de 0 a 1.
     *
     * @param  array<int, float|int>  $ordenados
     */
    public static function percentil(array $ordenados, float $fracao): float
    {
        $n = count($ordenados);
        if ($n === 0) {
            return 0.0;
        }
        if ($n === 1) {
            return (float) $ordenados[0];
        }

        $pos = $fracao * ($n - 1);
        $baixo = (int) floor($pos);
        $alto = (int) ceil($pos);

        if ($baixo === $alto) {
            return (float) $ordenados[$baixo];
        }

        $peso = $pos - $baixo;

        return (float) $ordenados[$baixo] * (1 - $peso) + (float) $ordenados[$alto] * $peso;
    }
}
