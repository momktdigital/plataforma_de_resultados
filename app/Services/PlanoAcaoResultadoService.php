<?php

namespace App\Services;

use App\Models\PlanoAcao;
use App\Support\NomeCurso;

/**
 * "Funcionou?": compara a linha de base do plano (a foto dos indicadores quando ele foi criado) com o que o curso
 * alcançou na avaliação seguinte — o primeiro período letivo POSTERIOR ao do plano que tem resultado na mesma categoria — e com
 * as metas pactuadas.
 *
 * É um sinal, não uma prova: o grupo de estudantes muda de um período para outro e o resultado tem muitas causas. As telas
 * dizem isso (ver o roteiro: "atribuir causalidade à ação exige cautela").
 */
class PlanoAcaoResultadoService
{
    public function __construct(
        private readonly CoordenadorDashboardService $dashboard,
        private readonly PlanoAcaoIndicadoresService $indicadores,
    ) {}

    /**
     * @return array{base: array{participacao: ?float, esperado: ?float}, metas: array{participacao: ?float, esperado: ?float}, proximo: ?array{periodo_letivo: string, participacao: ?float, esperado: ?float, dParticipacao: ?float, dEsperado: ?float, metaParticipacao: ?bool, metaEsperado: ?bool}}
     */
    public function calcular(PlanoAcao $plano): array
    {
        $resultado = [
            'base' => ['participacao' => $plano->participacao_atual, 'esperado' => $plano->esperado_atual],
            'metas' => ['participacao' => $plano->meta_participacao, 'esperado' => $plano->meta_esperado],
            'proximo' => null,
        ];

        // Plano sobre "todos os períodos" não tem um "próximo": não há para onde olhar.
        if ($plano->periodo_letivo === '') {
            return $resultado;
        }

        $proximo = $this->dashboard->avaliacoesDoCurso(NomeCurso::variantes([$plano->curso]))
            ->filter(fn ($a) => $a['periodoLetivo'] !== '' && strcmp($a['periodoLetivo'], $plano->periodo_letivo) > 0
                && ($plano->categoria_id === null || $a['categoriaId'] === (int) $plano->categoria_id))
            ->pluck('periodoLetivo')->unique()->sort()->first();
        if ($proximo === null) {
            return $resultado;
        }

        $ind = $this->indicadores->calcular($plano->curso, $proximo, $plano->categoria_id !== null ? (int) $plano->categoria_id : null);
        if ($ind === null) {
            return $resultado;
        }

        $dif = fn (?float $agora, ?float $antes) => $agora !== null && $antes !== null ? round($agora - $antes, 1) : null;
        $bateu = fn (?float $agora, ?float $meta) => $agora !== null && $meta !== null ? $agora >= $meta : null;

        $resultado['proximo'] = [
            'periodo_letivo' => $proximo,
            'participacao' => $ind['participacao'],
            'esperado' => $ind['esperado'],
            'dParticipacao' => $dif($ind['participacao'], $plano->participacao_atual),
            'dEsperado' => $dif($ind['esperado'], $plano->esperado_atual),
            // A meta de participação do plano é a institucional do momento; a de agora pode ter mudado — vale a de agora.
            'metaParticipacao' => $bateu($ind['participacao'], $ind['meta_participacao']),
            'metaEsperado' => $bateu($ind['esperado'], $plano->meta_esperado),
        ];

        return $resultado;
    }
}
