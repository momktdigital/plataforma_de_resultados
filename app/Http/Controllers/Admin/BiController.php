<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Avaliacao;
use App\Services\BiDashboardService;
use App\Services\ComparacaoAvaliacoesService;
use App\Services\ExplicacaoBiService;
use App\Services\PsicometriaService;
use App\Services\RelatorioAdminService;
use App\Services\Visualizacoes\VisualizacaoConfigService;
use App\Support\AlunoVinculoResolver;
use App\Support\EscopoCurso;
use App\Support\FiltroDemografico;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BiController extends Controller
{
    /** Linhas da lista nominal que já vão no HTML; as demais são carregadas sob demanda. */
    public const LINHAS_POR_PAGINA = 100;

    public function index(
        Request $request,
        Avaliacao $avaliacao,
        BiDashboardService $biService,
        RelatorioAdminService $relatorioService,
        VisualizacaoConfigService $visualizacaoConfig,
        AlunoVinculoResolver $alunoResolver,
        PsicometriaService $psicometriaService,
        ExplicacaoBiService $explicacaoService,
        ComparacaoAvaliacoesService $comparacaoService,
    ): View {
        // Coordenador só abre o BI de avaliação com aluno de um curso dele (ou
        // com acesso excepcional). 404 em vez de 403: não confirma que existe.
        $usuario = Auth::guard('admin')->user();
        abort_unless($avaliacao->acessivelPara($usuario), 404);
        $coordenador = $usuario->ehCoordenador();

        // Coordenador vê o BI SÓ com os alunos dos cursos dele: cada serviço de
        // análise ganha uma cópia restrita a esses cursos.
        $cursosDoEscopo = $coordenador ? $usuario->cursos() : null;
        $biService = $biService->paraCursos($cursosDoEscopo);
        $relatorioService = $relatorioService->paraCursos($cursosDoEscopo);
        $psicometriaService = $psicometriaService->paraCursos($cursosDoEscopo);

        $periodo = trim((string) $request->query('periodo', ''));
        // Dos resumos (uma linha por respondente), não de `respostas`: um DISTINCT sobre centenas de milhares de linhas.
        $periodosDisponiveis = DB::table('resultado_resumos')->where('avaliacao_codigo', $avaliacao->codigo)->distinct()->orderBy('periodo')->pluck('periodo');
        $filtro = FiltroDemografico::deQueryString($request->query());
        $opcoesFiltro = [
            ...$alunoResolver->opcoesDisponiveis($avaliacao->codigo, $coordenador ? new EscopoCurso($cursosDoEscopo) : null),
            'faixasEtarias' => FiltroDemografico::faixasEtarias(),
        ];

        $estado = $visualizacaoConfig->estadoCompleto($avaliacao);
        $visivel = fn (string $chave) => $estado[$chave]['visivelAdmin'];

        // Sempre calculado (não só quando histograma/radar estão habilitados): o
        // aviso de "sem gabarito"/"sem respostas" precisa aparecer independente da
        // configuração de visuais, senão a página fica em branco sem explicar o motivo.
        $dados = $biService->gerar($avaliacao, $periodo, $filtro);

        // Uma análise só alimenta os dois visuais psicométricos — o cabeçalho
        // de números e o mapa de itens saem da mesma varredura de `respostas`.
        $psicometria = ($visivel('estatisticas_gerais') || $visivel('mapa_itens'))
            ? $psicometriaService->analisar($avaliacao, $periodo)
            : null;

        // Códigos vêm da query string (?comparar[]=123) — a validação de
        // quantidade/existência é responsabilidade do serviço, não da rota.
        $codigosComparar = array_map('intval', (array) $request->query('comparar', []));
        $comparacaoAvaliacoes = $visivel('comparacao_avaliacoes')
            ? $comparacaoService->comparar($avaliacao, $codigosComparar, $usuario)
            : null;

        $resumoRanking = $visivel('ranking_completo') ? $relatorioService->resumoDoRanking($avaliacao, $periodo) : null;

        $painel = [
            'psicometria' => $psicometria,
            'bi' => $dados,
            // Só a primeira página da lista nominal vai no HTML; o resto vem sob demanda (BiListaController::linhas).
            'ranking' => $visivel('ranking_completo') ? $relatorioService->rankingCompleto($avaliacao, $periodo, self::LINHAS_POR_PAGINA) : null,
            'distribuicaoTurma' => $visivel('distribuicao_turma') ? $relatorioService->distribuicaoPorTurma($avaliacao, $periodo, $filtro) : null,
            'curvaDificuldade' => $visivel('curva_dificuldade') ? $relatorioService->curvaDificuldade($avaliacao) : null,
            'dispersaoTri' => $visivel('dispersao_tri') ? $relatorioService->dispersaoTri($avaliacao) : null,
            'heatmap' => $visivel('heatmap_habilidade_turma') ? $relatorioService->heatmapHabilidadeTurma($avaliacao, $periodo, $filtro) : null,
            'perfilDemografico' => $visivel('perfil_demografico') ? $relatorioService->perfilDemografico($avaliacao) : null,
            'analiseAlternativas' => $visivel('analise_alternativas') ? $relatorioService->analiseAlternativas($avaliacao, $periodo, $filtro) : null,
            'correlacaoMetricas' => $visivel('correlacao_metricas') ? $relatorioService->correlacaoMetricas($avaliacao, $periodo, $filtro) : null,
            'evolucaoCategoria' => $visivel('evolucao_categoria') ? $relatorioService->evolucaoCategoria($avaliacao) : null,
            'evolucaoPorPeriodo' => $visivel('evolucao_categoria') ? $relatorioService->evolucaoCategoriaPorPeriodo($avaliacao) : null,
            'mediaPorArea' => $visivel('desempenho_area') ? $relatorioService->mediaPorArea($avaliacao, $periodo) : null,
            'desempenhoPorTema' => $visivel('desempenho_tema') ? $relatorioService->desempenhoPorTema($avaliacao, $periodo) : null,
            'mediaPorBloom' => $visivel('desempenho_bloom') ? $relatorioService->mediaPorBloom($avaliacao, $periodo) : null,
            'mediaPorMiller' => $visivel('desempenho_miller') ? $relatorioService->mediaPorMiller($avaliacao, $periodo) : null,
            'alinhamento' => $visivel('alinhamento_referencias') ? $relatorioService->desempenhoPorReferencia($avaliacao, $periodo) : null,
            'equidade' => $visivel('equidade_demografica') ? $relatorioService->equidadeDemografica($avaliacao, $periodo) : null,
            'comparacao' => $comparacaoAvaliacoes,
        ];

        return view('admin.avaliacoes.bi', [
            'avaliacao' => $avaliacao,
            'periodo' => $periodo,
            'periodosDisponiveis' => $periodosDisponiveis,
            'filtro' => $filtro,
            'opcoesFiltro' => $opcoesFiltro,
            'estado' => $estado,
            'dados' => $dados,
            'rankingCompleto' => $painel['ranking'],
            'rankingTotal' => $resumoRanking['total'] ?? 0,
            'linhasPorPagina' => self::LINHAS_POR_PAGINA,
            'distribuicaoTurma' => $painel['distribuicaoTurma'],
            'curvaDificuldade' => $painel['curvaDificuldade'],
            'dispersaoTri' => $painel['dispersaoTri'],
            'heatmapHabilidadeTurma' => $painel['heatmap'],
            'perfilDemografico' => $painel['perfilDemografico'],
            'analiseAlternativas' => $painel['analiseAlternativas'],
            'correlacaoMetricas' => $painel['correlacaoMetricas'],
            'evolucaoCategoria' => $painel['evolucaoCategoria'],
            'evolucaoPorPeriodo' => $painel['evolucaoPorPeriodo'],
            'mediaPorArea' => $painel['mediaPorArea'],
            'desempenhoPorTema' => $painel['desempenhoPorTema'],
            'mediaPorBloom' => $painel['mediaPorBloom'],
            'mediaPorMiller' => $painel['mediaPorMiller'],
            'psicometria' => $psicometria,
            'presenca' => $psicometria !== null ? $psicometriaService->presenca($avaliacao, $periodo) : null,
            'curvasItens' => ($visivel('mapa_itens') && $psicometria !== null)
                ? $psicometriaService->curvasCaracteristicas($avaliacao, $periodo)
                : null,
            'alinhamentoReferencias' => $painel['alinhamento'],
            'equidade' => $painel['equidade'],
            'somenteLeitura' => $coordenador,
            'opcoesComparacao' => $visivel('comparacao_avaliacoes') ? $comparacaoService->opcoesDisponiveis($avaliacao, $usuario) : collect(),
            'comparacaoSelecionada' => $codigosComparar,
            'comparacaoAvaliacoes' => $comparacaoAvaliacoes,
            // Redigido por cima dos agregados já calculados acima — nenhuma
            // consulta a mais só para explicar os gráficos.
            'explicacoes' => $explicacaoService->gerar([
                ...$painel,
                // O texto sobre a lista fala do conjunto inteiro (primeiro e último colocado), não só da página.
                'ranking' => $resumoRanking !== null ? array_map(fn ($p) => ['percentual' => $p], $resumoRanking['percentuais']) : null,
            ]),
        ]);
    }
}
