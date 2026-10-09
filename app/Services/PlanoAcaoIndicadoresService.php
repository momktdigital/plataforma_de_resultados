<?php

namespace App\Services;

use App\Support\NomeCurso;

/**
 * Os três números que abrem todo plano de ação — participação atual, meta de participação e proficiência atual — para UM
 * curso num recorte (período letivo + categoria ou avaliação).
 *
 * Não calcula nada de novo: pede ao painel da reitoria (ReitorDashboardService) o mesmo que ele mostra para o curso, para
 * que o plano e o painel nunca discordem. Participação = quem fez ÷ previstos pela matrícula; proficiência = % dos
 * presentes com acerto no corte institucional (60% por padrão); meta = a de Configurações.
 */
class PlanoAcaoIndicadoresService
{
    public function __construct(private readonly ReitorDashboardService $reitor) {}

    /**
     * @return ?array{participacao: ?float, meta_participacao: float, proficiencia: ?float, corte: float, previstos: int, fizeram: int, com_nota: int, media: ?float, periodos: array<int, array<string, mixed>>, recorte: string, categoria_nome: ?string, avaliacoes: int, mistura: bool}
     *                                                                                                                                                                                                                                                                  null quando o curso não tem resultado nesse recorte
     */
    public function calcular(string $curso, string $periodoLetivo, ?int $categoriaId = null, ?int $avaliacaoCodigo = null): ?array
    {
        $contexto = $this->reitor->contexto([
            // '*' = todos os períodos letivos; o painel do coordenador usa '' para "Todos".
            'periodo' => $periodoLetivo === '' ? '*' : $periodoLetivo,
            'categoria' => $categoriaId !== null ? (string) $categoriaId : '',
            'avaliacao' => $avaliacaoCodigo,
        ], [NomeCurso::chave($curso)]);

        if (! empty($contexto['semResultados'])) {
            return null;
        }

        // O painel ignora, em silêncio, uma categoria que não existe no período: aqui isso seria um número de OUTRO
        // recorte com o rótulo deste. Sem resultado é melhor do que um número que não é o que o rótulo diz.
        if ($categoriaId !== null && $avaliacaoCodigo === null && ($contexto['filtro']['categoria'] ?? '') !== (string) $categoriaId) {
            return null;
        }

        // Idem para o período letivo (cai no mais recente quando o pedido não existe) e para a avaliação pedida.
        if ($avaliacaoCodigo !== null ? empty($contexto['avaliacao']['avulsa']) : ($periodoLetivo !== '' && ($contexto['avaliacao']['periodoLetivo'] ?? null) !== $periodoLetivo)) {
            return null;
        }

        $chave = NomeCurso::chave($curso);
        $estatisticas = $this->reitor->estatisticas($contexto)['cursos'][$chave] ?? null;
        if ($estatisticas === null) {
            return null;
        }

        $avaliacao = $contexto['avaliacao'];

        return [
            'participacao' => $estatisticas['participacao'],
            'meta_participacao' => $contexto['meta'],
            'proficiencia' => $estatisticas['proficienciaPct'],
            'corte' => $contexto['corte'],
            'previstos' => $estatisticas['previstos'],
            'fizeram' => $estatisticas['fizeram'],
            'com_nota' => $estatisticas['n'],
            'media' => $estatisticas['media'],
            // Por período do curso (1º, 2º...): onde a participação ou a proficiência estão piores.
            'periodos' => array_values(array_map(fn ($p) => [
                'ordinal' => (int) $p['ordinal'],
                'rotulo' => (string) $p['rotulo'],
                'participacao' => $p['participacao'],
                'proficiencia' => $p['proficienciaPct'],
                'fizeram' => (int) $p['fizeram'],
                'previstos' => (int) $p['previstos'],
            ], $estatisticas['periodos'])),
            'recorte' => $avaliacao['nome'] ?? '',
            'categoria_nome' => $avaliacao['categoria'] ?? null,
            'avaliacoes' => $avaliacao['qtdAvaliacoes'] ?? 1,
            'mistura' => (bool) ($avaliacao['mistura'] ?? false),
        ];
    }
}
