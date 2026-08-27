<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Avaliacao;
use App\Services\BiDashboardService;
use App\Services\RelatorioAdminService;
use App\Services\Visualizacoes\VisualizacaoConfigService;
use App\Support\AlunoVinculoResolver;
use App\Support\FiltroDemografico;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BiController extends Controller
{
    public function index(
        Request $request,
        Avaliacao $avaliacao,
        BiDashboardService $biService,
        RelatorioAdminService $relatorioService,
        VisualizacaoConfigService $visualizacaoConfig,
        AlunoVinculoResolver $alunoResolver,
    ): View {
        $periodo = trim((string) $request->query('periodo', ''));
        $periodosDisponiveis = $avaliacao->resultados()->select('periodo')->distinct()->pluck('periodo');
        $filtro = FiltroDemografico::deQueryString($request->query());
        $opcoesFiltro = [
            ...$alunoResolver->opcoesDisponiveis($avaliacao->codigo),
            'faixasEtarias' => FiltroDemografico::faixasEtarias(),
        ];

        $estado = $visualizacaoConfig->estadoCompleto($avaliacao);
        $visivel = fn (string $chave) => $estado[$chave]['visivelAdmin'];

        // Sempre calculado (não só quando histograma/radar estão habilitados): o
        // aviso de "sem gabarito"/"sem respostas" precisa aparecer independente da
        // configuração de visuais, senão a página fica em branco sem explicar o motivo.
        $dados = $biService->gerar($avaliacao, $periodo, $filtro);

        return view('admin.avaliacoes.bi', [
            'avaliacao' => $avaliacao,
            'periodo' => $periodo,
            'periodosDisponiveis' => $periodosDisponiveis,
            'filtro' => $filtro,
            'opcoesFiltro' => $opcoesFiltro,
            'estado' => $estado,
            'dados' => $dados,
            'rankingCompleto' => $visivel('ranking_completo') ? $relatorioService->rankingCompleto($avaliacao, $periodo) : null,
            'distribuicaoTurma' => $visivel('distribuicao_turma') ? $relatorioService->distribuicaoPorTurma($avaliacao, $periodo, $filtro) : null,
            'curvaDificuldade' => $visivel('curva_dificuldade') ? $relatorioService->curvaDificuldade($avaliacao) : null,
            'dispersaoTri' => $visivel('dispersao_tri') ? $relatorioService->dispersaoTri($avaliacao) : null,
            'heatmapHabilidadeTurma' => $visivel('heatmap_habilidade_turma') ? $relatorioService->heatmapHabilidadeTurma($avaliacao, $periodo, $filtro) : null,
            'perfilDemografico' => $visivel('perfil_demografico') ? $relatorioService->perfilDemografico($avaliacao) : null,
            'analiseAlternativas' => $visivel('analise_alternativas') ? $relatorioService->analiseAlternativas($avaliacao, $periodo, $filtro) : null,
            'correlacaoMetricas' => $visivel('correlacao_metricas') ? $relatorioService->correlacaoMetricas($avaliacao, $periodo, $filtro) : null,
            'evolucaoCategoria' => $visivel('evolucao_categoria') ? $relatorioService->evolucaoCategoria($avaliacao) : null,
            'mediaPorBloom' => $visivel('desempenho_bloom') ? $relatorioService->mediaPorBloom($avaliacao, $periodo) : null,
            'mediaPorMiller' => $visivel('desempenho_miller') ? $relatorioService->mediaPorMiller($avaliacao, $periodo) : null,
        ]);
    }
}
