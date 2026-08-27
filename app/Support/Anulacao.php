<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;

/**
 * Regra única de "anular questão", reaplicada em todo lugar que soma
 * acertos/total a partir de respostas x questoes (ResumoResultadoService é
 * o cálculo central, mas BiDashboardService/RelatorioAdminService/
 * RelatorioAlunoService/EstatisticaErroService/ResultadoConsultaService
 * fazem a mesma comparação resposta=gabarito direto, sem passar por
 * resultado_resumos — ver o mapeamento completo no histórico do commit).
 *
 * Duas modalidades (Questao::anulada_modo, nullable — null = não anulada):
 * - MODO_DAR_PONTO: todo mundo é creditado como se tivesse acertado essa
 *   questão, mas ela continua contando no total (prova de 100 continua /100).
 * - MODO_DISTRIBUIR_PONTUACAO: a questão sai do cálculo inteiro — nem soma
 *   acerto nem entra no total (prova de 100 vira /99).
 */
final class Anulacao
{
    public const MODO_DAR_PONTO = 'dar_ponto';

    public const MODO_DISTRIBUIR_PONTUACAO = 'distribuir_pontuacao';

    /** @return array<int, string> */
    public static function modos(): array
    {
        return [self::MODO_DAR_PONTO, self::MODO_DISTRIBUIR_PONTUACAO];
    }

    /**
     * Aplica em qualquer query que filtra a tabela `questoes` (Eloquent ou
     * DB::table, direto ou via JOIN) pra excluir as anuladas com
     * distribuir_pontuacao — elas não devem contar nem no total nem nos
     * acertos, em lugar nenhum. Questões com dar_ponto (ou não anuladas)
     * passam normalmente.
     */
    public static function excluirDistribuidas(BuilderContract $query, string $coluna = 'anulada_modo'): BuilderContract
    {
        return $query->where(fn ($q) => $q
            ->whereNull($coluna)
            ->orWhere($coluna, '!=', self::MODO_DISTRIBUIR_PONTUACAO));
    }

    /**
     * Fragmento SQL booleano pra usar dentro de `SUM(CASE WHEN {$expr} THEN
     * 1 ELSE 0 END)`: conta como acerto quando a questão tem dar_ponto
     * (credita todo mundo) OU quando a resposta bate com o gabarito.
     * Nunca inclui questões distribuir_pontuacao — sempre combine com
     * excluirDistribuidas() na mesma query, senão elas ficam de fora da
     * soma mas continuam contando no total via outra parte da query.
     */
    public static function condicaoAcertoSql(string $colunaResposta, string $colunaGabarito, string $colunaModo): string
    {
        return "({$colunaModo} = '".self::MODO_DAR_PONTO."' OR {$colunaResposta} = {$colunaGabarito})";
    }

    /** Equivalente em PHP de condicaoAcertoSql(), pras comparações feitas fora de SQL (ex.: sobre a Collection de respostas de um único aluno). */
    public static function acertou(?string $resposta, ?string $gabarito, ?string $anuladaModo): bool
    {
        return $anuladaModo === self::MODO_DAR_PONTO || $resposta === $gabarito;
    }

    public static function distribuida(?string $anuladaModo): bool
    {
        return $anuladaModo === self::MODO_DISTRIBUIR_PONTUACAO;
    }
}
