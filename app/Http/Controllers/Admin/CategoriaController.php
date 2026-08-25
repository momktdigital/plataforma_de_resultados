<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoriaRequest;
use App\Models\Categoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CategoriaController extends Controller
{
    public function index(): View
    {
        $todas = Categoria::withCount('avaliacoes')->orderBy('nome')->get();
        $porPai = $todas->groupBy('categoria_pai_id');

        return view('admin.categorias.index', [
            'raizes' => $porPai->get(null, collect()),
            'porPai' => $porPai,
            'opcoesSelect' => Categoria::opcoesSelect(),
        ]);
    }

    public function store(StoreCategoriaRequest $request): RedirectResponse
    {
        Categoria::create($request->validated());

        return redirect()->route('categorias.index')->with('status', 'Categoria criada com sucesso.');
    }

    public function destroy(Categoria $categoria): RedirectResponse
    {
        if ($categoria->filhos()->exists()) {
            return back()->withErrors(['categoria' => 'Esta categoria tem subcategorias — exclua-as primeiro.']);
        }

        if ($categoria->avaliacoes()->exists()) {
            return back()->withErrors(['categoria' => 'Esta categoria tem avaliacoes vinculadas — mude a categoria delas primeiro.']);
        }

        $categoria->delete();

        return redirect()->route('categorias.index')->with('status', 'Categoria excluída com sucesso.');
    }
}
