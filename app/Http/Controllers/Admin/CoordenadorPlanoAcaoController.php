<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\SalvarPlanoAcaoRequest;
use App\Models\Admin;
use App\Models\PlanoAcao;
use App\Services\PlanoAcaoBancoDeAcoes;
use App\Services\PlanoAcaoExportService;
use App\Services\PlanoAcaoOrigemService;
use App\Services\PlanoAcaoResultadoService;
use App\Services\PlanoAcaoService;
use App\Support\AtividadeLogger;
use App\Support\PlanoAcaoChecagem;
use App\Support\PlanoAcaoComparacao;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Os planos de ação do curso do coordenador: iniciar a partir de um dado do painel (o ícone nos visuais), preencher o
 * roteiro, enviar ao colaborador e acompanhar. A decisão e a análise ficam em ColaboradorPlanoAcaoController; o
 * acompanhamento das ações, em CoordenadorPlanoExecucaoController.
 *
 * Plano de outro curso responde 404 (como a ficha do aluno): não revela que existe. O reitor olhando um curso
 * (VisaoDeCursoDoReitor) abre a lista e o plano, só para ler — qualquer gravação é recusada pelo middleware e, por garantia,
 * aqui também.
 */
class CoordenadorPlanoAcaoController extends PlanoAcaoPainelController
{
    private const POR_PAGINA = 20;

    public function __construct(private readonly PlanoAcaoService $servico) {}

    public function index(Request $request): View|RedirectResponse
    {
        if (($usuario = $this->coordenador()) === null) {
            return redirect()->route('avaliacoes.index');
        }

        $filtro = (string) $request->query('status', 'todos');
        $filtro = isset(PlanoAcao::STATUS[$filtro]) ? $filtro : 'todos';

        $doCurso = PlanoAcao::dosCursos($usuario->cursos());
        $contagens = (clone $doCurso)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n)->all();

        $planos = (clone $doCurso)
            ->when($filtro !== 'todos', fn ($q) => $q->where('status', $filtro))
            ->with('acoes')
            ->orderByRaw("CASE status WHEN 'ajustes' THEN 0 WHEN 'rascunho' THEN 1 WHEN 'em_analise' THEN 2 WHEN 'aprovado' THEN 3 ELSE 4 END")
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();

        return view('plano.index', [
            'usuario' => $usuario,
            'planos' => $planos,
            'contagens' => $contagens,
            'filtro' => $filtro,
            'podeCriar' => ! $usuario->emVisaoDeCurso,
        ]);
    }

    /** A planilha dos planos do curso (com os rascunhos dele), na situação filtrada. */
    public function exportar(Request $request, PlanoAcaoExportService $exportacao): StreamedResponse|RedirectResponse
    {
        if (($usuario = $this->coordenador()) === null) {
            return redirect()->route('avaliacoes.index');
        }

        $filtro = (string) $request->query('status', 'todos');
        $planos = PlanoAcao::dosCursos($usuario->cursos())
            ->when(isset(PlanoAcao::STATUS[$filtro]), fn ($q) => $q->where('status', $filtro))
            ->with(['acoes', 'autor'])->orderBy('curso')->orderBy('id')->get();

        AtividadeLogger::registrar('plano_acao.exportado', 'PlanoAcao', null, ['cursos' => $usuario->cursos(), 'planos' => $planos->count()]);

        $writer = new Xlsx($exportacao->planilha($planos));

        return response()->streamDownload(fn () => $writer->save('php://output'), 'planos-de-acao-'.now()->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Abre o roteiro já preenchido com o que o painel sabe (indicadores, dados do visual); nada é gravado ainda. */
    public function novo(Request $request, PlanoAcaoOrigemService $origemServico, PlanoAcaoBancoDeAcoes $banco): View|RedirectResponse
    {
        $usuario = $this->coordenadorQueEscreve();
        $origem = $origemServico->montar($usuario, $request->query());

        return view('plano.form', [
            'usuario' => $usuario,
            'plano' => new PlanoAcao(['status' => PlanoAcao::RASCUNHO]),
            'origem' => $origem,
            'etapa' => 1,
            'bancoDeAcoes' => $banco->sugerir($origem['visual'], $origem['item'] ?? null, $origem['categoria_id'] ?? null),
            'semelhantes' => ($origem['curso'] ?? null) !== null && empty($origem['semDados'])
                ? $this->servico->semelhantes($origem['curso'], (string) ($origem['periodo_letivo'] ?? ''), $origem['categoria_id'] ?? null, $origem['visual'], $origem['item'] ?? null)
                : collect(),
            'avaliacoesAcessiveis' => $this->avaliacoesAcessiveis($usuario, array_column($origem['avaliacoes'] ?? [], 'codigo')),
        ]);
    }

    public function store(SalvarPlanoAcaoRequest $request, PlanoAcaoOrigemService $origemServico): RedirectResponse
    {
        $usuario = $this->coordenadorQueEscreve();

        // Os números NÃO vêm do navegador: só a "coordenada" de onde o coordenador estava (curso, período, categoria,
        // visual, item); o resto é recalculado aqui, só para os cursos dele.
        $origem = $origemServico->montar($usuario, (array) $request->input('origem', []));
        if (($origem['curso'] ?? null) === null || ($origem['indicadores'] ?? null) === null) {
            return back()->withInput()->withErrors(['origem' => 'Não há resultados deste curso no recorte escolhido: escolha outro curso, período ou categoria.']);
        }

        $plano = $this->servico->criar($usuario, $origem, $request->validated());

        return $this->depoisDeSalvar($request, $plano, $usuario);
    }

    public function show(PlanoAcao $plano, PlanoAcaoResultadoService $resultados): View
    {
        $usuario = $this->doCoordenador($plano);
        $plano->load(['acoes', 'eventos.admin', 'eventos.anexos', 'anexos', 'autor', 'decididoPor']);

        return view('plano.show', [
            'usuario' => $usuario,
            'plano' => $plano,
            'lacunas' => $plano->editavel() ? PlanoAcaoChecagem::lacunas($plano) : [],
            'alertas' => PlanoAcaoChecagem::alertas($plano),
            'mudancasDoEnvio' => PlanoAcaoComparacao::doUltimoEnvio($plano),
            'resultado' => in_array($plano->status, [PlanoAcao::APROVADO, PlanoAcao::CONCLUIDO], true) ? $resultados->calcular($plano) : null,
            'podeEscrever' => ! $usuario->emVisaoDeCurso,
            'avaliacoesAcessiveis' => $this->avaliacoesAcessiveis($usuario, array_column($plano->avaliacoesDoRecorte(), 'codigo')),
            'urlDoPainel' => route('coordenador.desempenho', [
                'curso' => $plano->curso,
                'periodo_letivo' => $plano->periodo_letivo,
                ...($plano->categoria_id !== null ? ['categoria' => $plano->categoria_id] : []),
            ]),
        ]);
    }

    public function edit(Request $request, PlanoAcao $plano, PlanoAcaoOrigemService $origemServico, PlanoAcaoBancoDeAcoes $banco): View|RedirectResponse
    {
        $usuario = $this->coordenadorQueEscreve();
        $this->doCoordenador($plano);

        if (! $plano->editavel()) {
            return redirect()->route('coordenador.planos.show', $plano)->with('erro', 'Este plano não pode mais ser editado.');
        }

        $plano->load('acoes');

        return view('plano.form', [
            'usuario' => $usuario,
            'plano' => $plano,
            'origem' => $this->origemDoPlano($usuario, $plano, $origemServico),
            'etapa' => max(1, min(5, (int) $request->query('etapa', 1))),
            'bancoDeAcoes' => $banco->sugerir($plano->origem_visual, $plano->origem_item, $plano->categoria_id !== null ? (int) $plano->categoria_id : null, $plano->id),
            'avaliacoesAcessiveis' => $this->avaliacoesAcessiveis($usuario, array_column($plano->avaliacoesDoRecorte(), 'codigo')),
        ]);
    }

    public function update(SalvarPlanoAcaoRequest $request, PlanoAcao $plano): RedirectResponse
    {
        $usuario = $this->coordenadorQueEscreve();
        $this->doCoordenador($plano);

        try {
            $this->servico->atualizar($plano, $request->validated());
        } catch (\DomainException $e) {
            return redirect()->route('coordenador.planos.show', $plano)->with('erro', $e->getMessage());
        }

        return $this->depoisDeSalvar($request, $plano, $usuario);
    }

    public function destroy(PlanoAcao $plano): RedirectResponse
    {
        $this->coordenadorQueEscreve();
        $this->doCoordenador($plano);

        try {
            $this->servico->excluir($plano);
        } catch (\DomainException $e) {
            return redirect()->route('coordenador.planos.show', $plano)->with('erro', $e->getMessage());
        }

        return redirect()->route('coordenador.planos.index')->with('status', 'Rascunho excluído.');
    }

    public function retirar(PlanoAcao $plano): RedirectResponse
    {
        $usuario = $this->coordenadorQueEscreve();
        $this->doCoordenador($plano);

        try {
            $this->servico->retirar($plano, $usuario);
        } catch (\DomainException $e) {
            return redirect()->route('coordenador.planos.show', $plano)->with('erro', $e->getMessage());
        }

        return redirect()->route('coordenador.planos.edit', $plano)->with('status', 'O plano saiu da análise e voltou a ser rascunho.');
    }

    /** Cria um rascunho novo a partir deste plano (para o ciclo seguinte, ou para refazer um plano recusado). */
    public function duplicar(PlanoAcao $plano, PlanoAcaoOrigemService $origemServico): RedirectResponse
    {
        $usuario = $this->coordenadorQueEscreve();
        $this->doCoordenador($plano);

        $plano->load('acoes');
        $origem = $this->origemDoPlano($usuario, $plano, $origemServico);
        if (($origem['curso'] ?? null) === null || ($origem['indicadores'] ?? null) === null) {
            return redirect()->route('coordenador.planos.show', $plano)->with('erro', 'Não há resultados atuais deste curso no recorte do plano para iniciar uma cópia.');
        }

        $novo = $this->servico->duplicar($plano, $usuario, $origem);

        return redirect()->route('coordenador.planos.edit', $novo)->with('status', 'Cópia criada como rascunho: revise o que mudou e envie.');
    }

    // ---------------------------------------------------------------------------------------------------------------

    /** Salvar rascunho (volta ao formulário, na etapa em que estava) ou enviar (vai à página do plano). */
    private function depoisDeSalvar(SalvarPlanoAcaoRequest $request, PlanoAcao $plano, Admin $usuario): RedirectResponse
    {
        $etapa = max(1, min(5, (int) $request->input('etapa_atual', 1)));

        if ($request->input('acao') !== 'enviar') {
            return redirect()->route('coordenador.planos.edit', [$plano, 'etapa' => $etapa])->with('status', 'Rascunho salvo.');
        }

        try {
            $this->servico->enviar($plano->fresh(), $usuario);
        } catch (\DomainException $e) {
            return redirect()->route('coordenador.planos.show', $plano)->with('erro', $e->getMessage());
        }

        return redirect()->route('coordenador.planos.show', $plano)->with('status', 'Plano enviado para análise. Você será avisado quando houver uma decisão.');
    }

    /** Recalcula o ponto de partida de um plano existente (curso, período, categoria, visual e item do plano). */
    private function origemDoPlano(Admin $usuario, PlanoAcao $plano, PlanoAcaoOrigemService $origemServico): array
    {
        return $origemServico->montar($usuario, array_filter([
            'curso' => $plano->curso,
            'periodo_letivo' => $plano->periodo_letivo,
            'categoria' => $plano->categoria_id,
            'avaliacao' => $plano->avaliacao_codigo,
            'visual' => $plano->origem_visual,
            'item' => $plano->origem_item,
        ], fn ($v) => $v !== null));
    }
}
