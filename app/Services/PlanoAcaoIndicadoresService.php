<?php

namespace App\Services;

use App\Support\NomeCurso;

/**
 * Os três números que abrem todo plano de ação — participação atual, meta de participação e proficiência atual — para UM
 * curso num recorte (período letivo + categoria ou avaliação).
 *
 * Não calcula nada de novo: pede ao painel da reitoria (ReitorDashboardService) o mesmo que ele mostra para o curso, para
 * que o plano e o painel nunca discordem. Participação = quem fez ÷ previstos pela matrícula; proficiência = % dos
 * presentes que alcançaram a meta; meta de participação = a de Configurações.
 *
 * PROFICIÊNCIA: quando a prova define o período das questões (`questoes.periodo_minimo`), é a % de alunos que alcançaram a META DO
 * PERÍODO deles — 60% do que cabe no período (PeriodoCurso::metaDoPeriodo): num 1º período com 10 de 100 questões ao alcance, são 6
 * questões, não 10. Sem essa informação, é o corte institucional sobre a prova inteira (60% por padrão), como no painel da reitoria.
 * `criterio` diz qual dos dois foi usado ('periodo' | 'corte').
 */
class PlanoAcaoIndicadoresService
{
    public function __construct(
        private readonly ReitorDashboardService $reitor,
        private readonly CoordenadorDashboardService $dashboard,
    ) {}

    /**
     * @return ?array{participacao: ?float, meta_participacao: float, proficiencia: ?float, criterio: string, corte: float, previstos: int, fizeram: int, com_nota: int, media: ?float, periodos: array<int, array<string, mixed>>, recorte: string, categoria_nome: ?string, avaliacoes: int, mistura: bool}
     *                                                                                                                                                                                                                                                                  null quando o curso não tem resultado nesse recorte
     */
    /**
     * Proficiência do curso nas avaliações do recorte. Havendo meta por período em alguma delas, conta quem alcançou a meta do
     * PRÓPRIO período (CoordenadorDashboardService::alunosDentroDoEsperado — a mesma conta de "dentro do esperado" do painel do
     * coordenador); senão, o valor do corte institucional.
     *
     * @param  array<int, int>  $codigos  avaliações do recorte (de todos os cursos; a conta filtra pelo curso)
     * @return array{0: ?float, 1: string} [% de proficientes, 'periodo' | 'corte']
     */
    private function proficiencia(string $curso, array $codigos, ?float $doCorte): array
    {
        if ($codigos === []) {
            return [$doCorte, 'corte'];
        }

        $porAvaliacao = $this->dashboard->alunosDentroDoEsperado(NomeCurso::variantes([$curso]), array_map('intval', $codigos));
        if (! collect($porAvaliacao)->contains(fn ($a) => $a['comMeta'])) {
            return [$doCorte, 'corte'];
        }

        $presentes = array_sum(array_column($porAvaliacao, 'presentes'));
        $dentro = array_sum(array_column($porAvaliacao, 'dentro'));

        return $presentes > 0 ? [round($dentro / $presentes * 100, 1), 'periodo'] : [$doCorte, 'corte'];
    }

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
        [$proficiencia, $criterio] = $this->proficiencia($curso, $avaliacao['codigos'] ?? [], $estatisticas['proficienciaPct']);

        return [
            'participacao' => $estatisticas['participacao'],
            'meta_participacao' => $contexto['meta'],
            'proficiencia' => $proficiencia,
            'criterio' => $criterio,
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
