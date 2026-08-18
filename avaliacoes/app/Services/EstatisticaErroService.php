<?php

namespace App\Services;

use App\Models\Prova;

/**
 * Taxa de erro por questão de uma Prova — equivalente ao painel "Questões
 * críticas" de admin/avaliacao_editar.php, recalculado aqui a partir de
 * `respostas` (formato longo) em vez do antigo JSON por aluno.
 */
class EstatisticaErroService
{
    private const MAXIMO_EXIBIDO = 10;

    /** @return array<int, array{numero: int, acertos: int, erros: int, em_branco: int, taxa_erro: float}> */
    public function calcular(Prova $prova): array
    {
        $gabaritos = $prova->questoes()
            ->whereNotNull('gabarito')
            ->where('gabarito', '!=', '')
            ->pluck('gabarito', 'numero');

        if ($gabaritos->isEmpty()) {
            return [];
        }

        $stats = $gabaritos->keys()->mapWithKeys(fn ($numero) => [
            $numero => ['numero' => $numero, 'acertos' => 0, 'erros' => 0, 'em_branco' => 0],
        ])->all();

        $prova->resultados()
            ->whereIn('questao_numero', $gabaritos->keys())
            ->select(['questao_numero', 'resposta'])
            ->chunk(1000, function ($respostas) use (&$stats, $gabaritos) {
                foreach ($respostas as $resposta) {
                    $correta = $gabaritos[$resposta->questao_numero];
                    $marcada = $resposta->resposta ?? '';

                    if ($marcada === '') {
                        $stats[$resposta->questao_numero]['em_branco']++;
                    } elseif ($marcada === $correta) {
                        $stats[$resposta->questao_numero]['acertos']++;
                    } else {
                        $stats[$resposta->questao_numero]['erros']++;
                    }
                }
            });

        foreach ($stats as $numero => &$s) {
            $totalRespondido = $s['acertos'] + $s['erros'];
            $s['taxa_erro'] = $totalRespondido > 0 ? round(($s['erros'] / $totalRespondido) * 100, 1) : 0.0;
        }
        unset($s);

        $stats = array_filter($stats, fn ($s) => $s['taxa_erro'] > 0);
        usort($stats, fn ($a, $b) => $b['taxa_erro'] <=> $a['taxa_erro']);

        return array_slice($stats, 0, self::MAXIMO_EXIBIDO);
    }
}
