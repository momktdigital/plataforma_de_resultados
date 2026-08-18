<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProvaRequest;
use App\Models\Categoria;
use App\Models\Prova;
use App\Services\EstatisticaErroService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ProvaController extends Controller
{
    public function index(): View
    {
        $provas = Prova::with('categoria')
            ->withCount(['questoes', 'resultados'])
            ->latest('codigo')
            ->paginate(20);

        return view('admin.provas.index', [
            'provas' => $provas,
            'opcoesCategoria' => Categoria::opcoesSelect(),
        ]);
    }

    public function store(StoreProvaRequest $request): RedirectResponse
    {
        $prova = Prova::create([
            ...$this->comDataConvertida($request),
            'criado_por' => Auth::guard('admin')->id(),
        ]);

        return redirect()
            ->route('provas.show', $prova)
            ->with('status', "Prova #{$prova->codigo} criada com sucesso.");
    }

    public function show(Prova $prova): View
    {
        $prova->loadCount(['questoes', 'resultados', 'metricas']);

        $questoes = $prova->questoes()->withTrashed()->orderBy('numero')->get();

        return view('admin.provas.show', [
            'prova' => $prova,
            'questoes' => $questoes,
            'estatisticasErro' => (new EstatisticaErroService)->calcular($prova),
            'opcoesCategoria' => Categoria::opcoesSelect(),
        ]);
    }

    public function update(StoreProvaRequest $request, Prova $prova): RedirectResponse
    {
        $prova->update($this->comDataConvertida($request));

        return redirect()
            ->route('provas.show', $prova)
            ->with('status', 'Configurações da prova atualizadas com sucesso.');
    }

    /** @return array<string, mixed> */
    private function comDataConvertida(StoreProvaRequest $request): array
    {
        $dados = $request->validated();
        $dados['data_prova'] = $dados['data_prova'] ?? null;

        if ($dados['data_prova'] !== null) {
            $dados['data_prova'] = Carbon::createFromFormat('d/m/Y', $dados['data_prova'])->format('Y-m-d');
        }

        return $dados;
    }

    public function destroy(Prova $prova): RedirectResponse
    {
        $prova->questoes()->delete();
        $prova->resultados()->delete();
        $prova->metricas()->delete();
        $prova->delete();

        return redirect()
            ->route('provas.index')
            ->with('status', "Prova #{$prova->codigo} e todos os dados associados foram excluídos.");
    }
}
