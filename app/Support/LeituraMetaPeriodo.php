<?php

namespace App\Support;

/**
 * "Leitura rápida" do gráfico de acerto x mínimo esperado do boletim: transforma o agregado de
 * AnaliseConsolidadaService::metaPorPeriodo() em poucas frases que o aluno entende sem ler o gráfico. Texto puro —
 * nenhum cálculo novo, nenhuma comparação com a turma (só com o mínimo esperado de cada prova).
 */
final class LeituraMetaPeriodo
{
    private const MAXIMO_CITADAS = 3;

    /**
     * @param  array{avaliacoes: array<int, array{nome: string, percentual: float, minimo: float, comMeta: bool, periodoAluno: ?int}>, comMeta: bool, areas: array<int, array{area: string, percentual: float, esperado: ?float}>, adiante: array{total: int, acertos: int}}|null  $meta
     * @return array<int, string>
     */
    public static function gerar(?array $meta): array
    {
        if ($meta === null || $meta['avaliacoes'] === []) {
            return [];
        }

        $frases = [];

        foreach ($meta['avaliacoes'] as $av) {
            $frases[] = self::frasePorAvaliacao($av, count($meta['avaliacoes']) > 1);
        }

        if ($meta['comMeta']) {
            $abaixo = array_values(array_filter($meta['areas'], fn ($a) => $a['esperado'] !== null && $a['percentual'] < $a['esperado']));

            if ($abaixo !== []) {
                $itens = array_map(
                    fn ($a) => "{$a['area']} (acertou {$a['percentual']}%, esperado {$a['esperado']}%)",
                    array_slice($abaixo, 0, self::MAXIMO_CITADAS)
                );
                $frases[] = 'Onde ficou abaixo do esperado: '.implode('; ', $itens).'. Convém começar a revisão por elas.';
            } elseif ($meta['areas'] !== []) {
                $frases[] = 'Em todas as áreas você ficou dentro do esperado para o seu período.';
            }

            $adiante = $meta['adiante'];
            if ($adiante['total'] > 0) {
                $frases[] = "{$adiante['total']} questão(ões) eram de períodos à frente do seu e você acertou {$adiante['acertos']} — "
                    .'o erro nessas questões não indica lacuna e o acerto é um ganho adicional.';
            }
        }

        return $frases;
    }

    /** @param  array{nome: string, percentual: float, minimo: float, comMeta: bool, periodoAluno: ?int}  $av */
    private static function frasePorAvaliacao(array $av, bool $citarNome): string
    {
        $origem = $av['comMeta'] && $av['periodoAluno'] !== null
            ? 'para o '.PeriodoCurso::rotulo($av['periodoAluno'])
            : 'mínimo padrão, esta prova não tem meta por período';
        $situacao = $av['percentual'] >= $av['minimo'] ? 'no mínimo esperado ou acima dele' : 'abaixo do mínimo esperado';
        $sujeito = $citarNome ? "Em {$av['nome']}, você" : 'Você';

        return "{$sujeito} acertou {$av['percentual']}% — {$situacao} ({$av['minimo']}%, {$origem}).";
    }
}
