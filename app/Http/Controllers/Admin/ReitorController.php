<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\VisaoDeCursoDoReitor;
use App\Models\Curso;
use App\Services\ReitorCompetenciasService;
use App\Services\ReitorDashboardService;
use App\Services\ReitorEvolucaoService;
use App\Services\ReitorExportService;
use App\Services\ReitorItensService;
use App\Services\ReitorLeituraService;
use App\Services\ReitorRiscoService;
use App\Support\AtividadeLogger;
use App\Support\NomeCurso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Painel da reitoria: a visão institucional de TODOS os cursos, só com indicadores agregados (nunca dado nominal
 * de aluno). Cinco telas sobre o mesmo recorte — período letivo, categoria ("todas" por padrão) e avaliação (em geral
 * cada avaliação é de um curso, então a categoria reúne os cursos) e, se quiser, um subconjunto de cursos — escolhido
 * na barra de filtros e mantido ao trocar de tela:
 *
 *  - visao:        participação por curso × meta, proficiência institucional, pontos de atenção;
 *  - desempenho:   média e mediana, distribuição por faixa, dispersão, patamares;
 *  - trajetoria:   desempenho ao longo dos períodos do curso, mapa curso × período, crescimento, cobertura;
 *  - competencias: níveis de Bloom e áreas de conhecimento;
 *  - evolucao:     série entre períodos letivos (mesma categoria de avaliação) e variação de cada curso.
 *
 * Acessível ao reitor e ao administrador (ver routes/web.php); o coordenador não entra por aqui.
 */
class ReitorController extends Controller
{
    public function __construct(
        private readonly ReitorDashboardService $dashboard,
        private readonly ReitorLeituraService $leitura,
    ) {}

    /** Participação, proficiência e o que merece atenção. */
    public function visao(Request $request, ReitorEvolucaoService $evolucaoServico): View
    {
        [$ctx, $est] = $this->recorte($request);
        if ($est === null) {
            return $this->semResultados($ctx, 'visao');
        }

        $evolucao = $evolucaoServico->serie($ctx);

        return view('reitor.visao', $this->comuns($ctx, $est, 'visao') + [
            'evolucao' => $evolucao,
            'alertas' => $this->leitura->pontosDeAtencao($ctx, $est, $evolucao),
            'leituras' => $this->leitura->leituras($ctx, $est, null, $evolucao),
        ]);
    }

    public function desempenho(Request $request): View
    {
        [$ctx, $est] = $this->recorte($request);
        if ($est === null) {
            return $this->semResultados($ctx, 'desempenho');
        }

        return view('reitor.desempenho', $this->comuns($ctx, $est, 'desempenho') + [
            'leituras' => $this->leitura->leituras($ctx, $est),
        ]);
    }

    public function trajetoria(Request $request): View
    {
        [$ctx, $est] = $this->recorte($request);
        if ($est === null) {
            return $this->semResultados($ctx, 'trajetoria');
        }

        return view('reitor.trajetoria', $this->comuns($ctx, $est, 'trajetoria') + [
            'leituras' => $this->leitura->leituras($ctx, $est),
            'crescimentos' => $this->leitura->crescimentos(array_values($est['cursos'])),
            'crescimentoTotal' => $this->leitura->crescimentos([$est['total']])[0] ?? null,
        ]);
    }

    public function competencias(Request $request, ReitorCompetenciasService $competenciasServico): View
    {
        [$ctx, $est] = $this->recorte($request);
        if ($est === null) {
            return $this->semResultados($ctx, 'competencias');
        }

        $competencias = $competenciasServico->gerar($ctx);

        return view('reitor.competencias', $this->comuns($ctx, $est, 'competencias') + [
            'competencias' => $competencias,
            'leituras' => $this->leitura->leituras($ctx, $est, $competencias),
        ]);
    }

    public function evolucao(Request $request, ReitorEvolucaoService $evolucaoServico): View
    {
        [$ctx, $est] = $this->recorte($request);
        if ($est === null) {
            return $this->semResultados($ctx, 'evolucao');
        }

        $evolucao = $evolucaoServico->serie($ctx);

        return view('reitor.evolucao', $this->comuns($ctx, $est, 'evolucao') + [
            'evolucao' => $evolucao,
            'leituras' => $this->leitura->leituras($ctx, $est, null, $evolucao),
        ]);
    }

    /** Lista dos cursos, cada um com a entrada para a visão do coordenador (ver ReitorCursoController). */
    public function cursos(Request $request): View
    {
        [$ctx, $est] = $this->recorte($request);

        return view('reitor.cursos', [
            'usuario' => Auth::guard('admin')->user(),
            'ctx' => $ctx,
            'est' => $est ?? [],
            'aba' => 'cursos',
            'cursos' => collect(Curso::nomesDisponiveis())->map(fn ($nome) => ['nome' => $nome, 'chave' => NomeCurso::chave($nome)])->all(),
        ]);
    }

    /**
     * Relatório institucional do recorte para reunião: capa, resumo, participação, proficiência, desempenho, trajetória,
     * competências e evolução, cada um com a leitura. Imprime em A4 (salvar como PDF) e exporta PowerPoint (no navegador).
     */
    public function relatorio(Request $request, ReitorEvolucaoService $evolucaoServico, ReitorCompetenciasService $competenciasServico): View
    {
        [$ctx, $est] = $this->recorte($request);
        if ($est === null) {
            return $this->semResultados($ctx, 'visao');
        }

        $evolucao = $evolucaoServico->serie($ctx);
        $competencias = $competenciasServico->gerar($ctx);

        AtividadeLogger::registrar('reitor.relatorio_aberto', 'Admin', Auth::guard('admin')->id(), [
            'avaliacoes' => $ctx['avaliacao']['codigos'],
            'periodo_letivo' => $ctx['avaliacao']['periodoLetivo'],
        ]);

        return view('reitor.relatorio', $this->comuns($ctx, $est, 'relatorio') + [
            'evolucao' => $evolucao,
            'competencias' => $competencias,
            'alertas' => $this->leitura->pontosDeAtencao($ctx, $est, $evolucao),
            'leituras' => $this->leitura->leituras($ctx, $est, $competencias, $evolucao),
        ]);
    }

    /** Análise institucional dos itens: acerto × discriminação, possíveis erros de gabarito, problema da questão × lacuna de formação. */
    public function itens(Request $request, ReitorItensService $itensServico): View
    {
        [$ctx, $est] = $this->recorte($request);
        if ($est === null) {
            return $this->semResultados($ctx, 'itens');
        }

        $analise = $itensServico->gerar($ctx);

        return view('reitor.itens', $this->comuns($ctx, $est, 'itens') + [
            'analise' => $analise,
            'leituras' => $this->leitura->leiturasDeItens($analise),
        ]);
    }

    /** Estudantes em risco, só em agregado: ausência recorrente e baixo desempenho persistente por curso e período. */
    public function risco(Request $request, ReitorRiscoService $riscoServico): View
    {
        [$ctx, $est] = $this->recorte($request);
        if ($est === null) {
            return $this->semResultados($ctx, 'risco');
        }

        $risco = $riscoServico->gerar($ctx);

        return view('reitor.risco', $this->comuns($ctx, $est, 'risco') + [
            'risco' => $risco,
            'leituras' => $this->leitura->leiturasDeRisco($risco),
        ]);
    }

    public function xlsx(Request $request, ReitorExportService $exportService): StreamedResponse
    {
        [$ctx, $est] = $this->recorte($request);
        abort_if($est === null, 404);

        $usuario = Auth::guard('admin')->user();
        AtividadeLogger::registrar('reitor.painel_exportado', 'Admin', $usuario->id, [
            'filtro' => $ctx['filtro'],
            'avaliacoes' => $ctx['avaliacao']['codigos'],
            'periodo_letivo' => $ctx['avaliacao']['periodoLetivo'],
            'cursos' => count($ctx['cursosSelecionados']),
        ]);

        $writer = new Xlsx($exportService->planilha($ctx, $est));
        $arquivo = 'reitoria-'.($ctx['avaliacao']['todosPeriodos'] ? 'todos-os-periodos' : ($ctx['avaliacao']['periodoLetivo'] !== '' ? str_replace('/', '-', $ctx['avaliacao']['periodoLetivo']) : 'avaliacao-'.$ctx['avaliacao']['codigo'])).($ctx['avaliacao']['ehGrupo'] ? '-categoria' : '').'.xlsx';

        return response()->streamDownload(
            fn () => $writer->save('php://output'),
            $arquivo,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /**
     * O recorte pedido na barra de filtros: `periodo`, `categoria`, `avaliacao` e `cursos[]` (chaves). Sem resultados importados,
     * `estatisticas` vem null e a tela mostra o aviso.
     *
     * @return array{0: array<string, mixed>, 1: ?array<string, mixed>}
     */
    private function recorte(Request $request): array
    {
        // Voltou ao painel da reitoria: a visão de um curso (como coordenador) não fica "grudada" na sessão.
        $request->session()->forget(VisaoDeCursoDoReitor::SESSAO);

        // período letivo, categoria ('' = todas) e avaliação ('' = todas da categoria); só o código da avaliação também vale.
        $texto = fn (string $nome) => is_string($valor = $request->query($nome)) ? mb_substr($valor, 0, 30) : null;
        $cursos = $request->query('cursos', []);

        $ctx = $this->dashboard->contexto(
            ['periodo' => $texto('periodo'), 'categoria' => $texto('categoria'), 'avaliacao' => $texto('avaliacao')],
            is_array($cursos) ? array_values(array_filter($cursos, 'is_string')) : [],
        );

        return [$ctx, empty($ctx['semResultados']) ? $this->dashboard->estatisticas($ctx) : null];
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $est
     * @return array<string, mixed>
     */
    private function comuns(array $ctx, array $est, string $aba): array
    {
        // O histograma bruto não vai para a view (nem para o JSON dos gráficos).
        unset($ctx['bruto']);

        return [
            'usuario' => Auth::guard('admin')->user(),
            'ctx' => $ctx,
            'est' => $est,
            'aba' => $aba,
            'sobre' => $this->leitura->sobre($ctx['corte'], $ctx['meta']),
            'faixas' => ReitorDashboardService::faixas($ctx['corte']),
            'patamares' => ReitorDashboardService::patamares($ctx['corte']),
            'minimoPeriodo' => ReitorDashboardService::MINIMO_PERIODO,
        ];
    }

    /** @param array<string, mixed> $ctx */
    private function semResultados(array $ctx, string $aba): View
    {
        return view('reitor.sem-resultados', [
            'usuario' => Auth::guard('admin')->user(),
            'ctx' => $ctx,
            'aba' => $aba,
        ]);
    }
}
