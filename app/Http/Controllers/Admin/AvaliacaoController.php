<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAvaliacaoRequest;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\Resposta;
use App\Services\EstatisticaErroService;
use App\Services\Visualizacoes\VisualizacaoConfigService;
use App\Support\AtividadeLogger;
use App\Support\GabaritoComentadoUploader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

class AvaliacaoController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $categoriaId = $request->query('categoria_id') !== null && $request->query('categoria_id') !== ''
            ? (int) $request->query('categoria_id')
            : null;

        $avaliacoes = Avaliacao::with('categoria')
            ->withCount('questoes')
            // Nº de alunos distintos, não nº de linhas de resposta (uma por
            // questão respondida) — subquery correlacionada, sem N+1.
            ->addSelect(['alunos_count' => Resposta::selectRaw('count(distinct aluno_chave)')
                ->whereColumn('avaliacao_codigo', 'avaliacoes.codigo')
                ->whereNull('deleted_at'),
            ])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('nome', 'like', "%{$search}%")
                        ->orWhere('tipo', 'like', "%{$search}%")
                        ->when(is_numeric($search), fn ($query) => $query->orWhere('codigo', (int) $search));
                });
            })
            ->when($categoriaId !== null, fn ($query) => $query->where('categoria_id', $categoriaId))
            ->latest('codigo')
            ->paginate(20)
            ->withQueryString();

        return view('admin.avaliacoes.index', [
            'avaliacoes' => $avaliacoes,
            'search' => $search,
            'categoriaId' => $categoriaId,
            'opcoesCategoria' => Categoria::opcoesSelect(),
        ]);
    }

    public function store(StoreAvaliacaoRequest $request): RedirectResponse
    {
        try {
            $dados = $this->comDataConvertida($request);
        } catch (RuntimeException $e) {
            return back()->withErrors(['gabarito_comentado_arquivo' => $e->getMessage()]);
        }

        $avaliacao = Avaliacao::create([
            ...$dados,
            'criado_por' => Auth::guard('admin')->id(),
        ]);

        return redirect()
            ->route('avaliacoes.show', $avaliacao)
            ->with('status', "Avaliação #{$avaliacao->codigo} criada com sucesso.");
    }

    public function show(Avaliacao $avaliacao, VisualizacaoConfigService $visualizacaoConfig): View
    {
        $avaliacao->loadCount(['questoes', 'resultados', 'metricas']);

        $questoes = $avaliacao->questoes()->withTrashed()->with(['matrizes', 'referencias'])->orderBy('numero')->get();

        // Editar gabarito/anulação recalcula a nota de todo mundo que já
        // respondeu na hora — a tela usa isso pra só pedir confirmação
        // quando a questão editada já tem resposta de verdade (ver
        // admin.avaliacoes.show, form-editor-questao).
        $respostasPorNumero = DB::table('respostas')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->whereNull('deleted_at')
            ->select('questao_numero')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('questao_numero')
            ->pluck('total', 'questao_numero');

        $estadoVisualizacoes = $visualizacaoConfig->estadoCompleto($avaliacao);

        return view('admin.avaliacoes.show', [
            'avaliacao' => $avaliacao,
            'questoes' => $questoes,
            'respostasPorNumero' => $respostasPorNumero,
            'estatisticasErro' => $estadoVisualizacoes['questoes_criticas']['visivelAdmin']
                ? (new EstatisticaErroService)->calcular($avaliacao)
                : [],
            'opcoesCategoria' => Categoria::opcoesSelect(),
        ]);
    }

    public function update(StoreAvaliacaoRequest $request, Avaliacao $avaliacao): RedirectResponse
    {
        try {
            $dados = $this->comDataConvertida($request);
        } catch (RuntimeException $e) {
            return back()->withErrors(['gabarito_comentado_arquivo' => $e->getMessage()]);
        }

        $statusAntes = $avaliacao->status;
        $avaliacao->update($dados);

        if (isset($dados['status']) && $statusAntes !== $avaliacao->status) {
            AtividadeLogger::registrar('avaliacao.status_alterado', 'Avaliacao', $avaliacao->codigo, [
                'status_antes' => $statusAntes,
                'status_depois' => $avaliacao->status,
            ]);
        }

        return redirect()
            ->route('avaliacoes.show', $avaliacao)
            ->with('status', 'Configurações da avaliação atualizadas com sucesso.');
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RuntimeException se o upload do gabarito comentado falhar
     */
    private function comDataConvertida(StoreAvaliacaoRequest $request): array
    {
        $dados = $request->validated();
        $dados['data_avaliacao'] = $dados['data_avaliacao'] ?? null;

        if ($dados['data_avaliacao'] !== null) {
            $dados['data_avaliacao'] = Carbon::createFromFormat('d/m/Y', $dados['data_avaliacao'])->format('Y-m-d');
        }

        // Enviar um arquivo é uma ALTERNATIVA ao link — substitui o valor
        // colado no campo de texto, nunca os dois juntos.
        if ($request->hasFile('gabarito_comentado_arquivo')) {
            $dados['link_comentado'] = GabaritoComentadoUploader::salvar($request->file('gabarito_comentado_arquivo'));
        }
        unset($dados['gabarito_comentado_arquivo']);

        return $dados;
    }

    public function destroy(Avaliacao $avaliacao): RedirectResponse
    {
        $avaliacao->questoes()->delete();
        $avaliacao->resultados()->delete();
        $avaliacao->metricas()->delete();
        $avaliacao->delete();

        return redirect()
            ->route('avaliacoes.index')
            ->with('status', "Avaliação #{$avaliacao->codigo} e todos os dados associados foram excluídos.");
    }
}
