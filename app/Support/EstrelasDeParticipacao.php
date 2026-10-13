<?php

namespace App\Support;

/**
 * Estrelas de participação do estudante: 5 estrelas = fez TODAS as provas previstas; cada meia estrela vale 10% de
 * participação. Sempre arredonda para BAIXO (95% = 4,5 estrelas) — cinco estrelas cheias só aparecem com 100%.
 *
 * "Previstas" são as provas em que o estudante tem resultado registrado (presente ou ausente); a falta é o resumo
 * com `ausente` (nenhuma resposta na prova inteira — ver ResumoResultadoService). Conta em inteiros para não
 * errar a fronteira por ponto flutuante (9/10 de 5 estrelas = 4,5 exato).
 */
final class EstrelasDeParticipacao
{
    public const TOTAL = 5;

    /**
     * @param  int  $previstas  provas que o estudante deveria ter feito
     * @param  int  $feitas  das previstas, as que ele fez (não faltou)
     * @return array{estrelas: float, cheias: int, meia: bool, vazias: int, feitas: int, previstas: int, percentual: float}|null  null sem nenhuma prova prevista
     */
    public static function calcular(int $previstas, int $feitas): ?array
    {
        if ($previstas <= 0) {
            return null;
        }

        $feitas = max(0, min($feitas, $previstas));
        $meias = intdiv($feitas * self::TOTAL * 2, $previstas); // 0..10 meias estrelas
        $cheias = intdiv($meias, 2);
        $meia = $meias % 2 === 1;

        return [
            'estrelas' => $meias / 2.0,
            'cheias' => $cheias,
            'meia' => $meia,
            'vazias' => self::TOTAL - $cheias - ($meia ? 1 : 0),
            'feitas' => $feitas,
            'previstas' => $previstas,
            'percentual' => round($feitas / $previstas * 100, 1),
        ];
    }

    /**
     * @param  array<int, array{ausente?: bool}>  $resultados  os de ResultadoConsultaService::buscarPorAluno()
     */
    public static function dosResultados(array $resultados): ?array
    {
        $previstas = count($resultados);
        $faltas = count(array_filter($resultados, fn ($r) => ! empty($r['ausente'])));

        return self::calcular($previstas, $previstas - $faltas);
    }
}
