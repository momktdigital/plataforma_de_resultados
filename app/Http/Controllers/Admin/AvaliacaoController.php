<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAvaliacaoRequest;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Services\EstatisticaErroService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AvaliacaoController extends Controller
{
    public function index(): View
    {
        $avaliacoes = Avaliacao::with('categoria')
            ->withCount(['questoes', 'resultados'])
            ->latest('codigo')
            ->paginate(20);

        return view('admin.avaliacoes.index', [
            'avaliacoes' => $avaliacoes,
            'opcoesCategoria' => Categoria::opcoesSelect(),
        ]);
    }

    public function store(StoreAvaliacaoRequest $request): RedirectResponse
    {
        $avaliacao = Avaliacao::create([
            ...$this->comDataConvertida($request),
            'criado_por' => Auth::guard('admin')->id(),
        ]);

        return redirect()
            ->route('avaliacoes.show', $avaliacao)
            ->with('status', "Avaliação #{$avaliacao->codigo} criada com sucesso.");
    }

    public function show(Avaliacao $avaliacao): View
    {
        $avaliacao->loadCount(['questoes', 'resultados', 'metricas']);

        $questoes = $avaliacao->questoes()->withTrashed()->with(['matrizes', 'referencias'])->orderBy('numero')->get();

        return view('admin.avaliacoes.show', [
            'avaliacao' => $avaliacao,
            'questoes' => $questoes,
            'estatisticasErro' => (new EstatisticaErroService)->calcular($avaliacao),
            'opcoesCategoria' => Categoria::opcoesSelect(),
        ]);
    }

    public function update(StoreAvaliacaoRequest $request, Avaliacao $avaliacao): RedirectResponse
    {
        $avaliacao->update($this->comDataConvertida($request));

        return redirect()
            ->route('avaliacoes.show', $avaliacao)
            ->with('status', 'Configurações da avaliação atualizadas com sucesso.');
    }

    /** @return array<string, mixed> */
    private function comDataConvertida(StoreAvaliacaoRequest $request): array
    {
        $dados = $request->validated();
        $dados['data_avaliacao'] = $dados['data_avaliacao'] ?? null;

        if ($dados['data_avaliacao'] !== null) {
            $dados['data_avaliacao'] = Carbon::createFromFormat('d/m/Y', $dados['data_avaliacao'])->format('Y-m-d');
        }

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
