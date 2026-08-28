<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoriaRequest;
use App\Models\Categoria;
use App\Support\AtividadeLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function edit(Categoria $categoria): View
    {
        return view('admin.categorias.edit', [
            'categoria' => $categoria,
            'opcoesSelect' => collect(Categoria::opcoesSelect())->reject(fn ($opcao) => $opcao['id'] === $categoria->id)->values(),
        ]);
    }

    public function update(StoreCategoriaRequest $request, Categoria $categoria): RedirectResponse
    {
        if ((int) $request->validated('categoria_pai_id') === $categoria->id) {
            return back()->withErrors(['categoria_pai_id' => 'Uma categoria não pode ser mãe dela mesma.']);
        }

        $nomeAntes = $categoria->nome;
        $categoria->update($request->validated());

        AtividadeLogger::registrar('categoria.editada', 'Categoria', $categoria->id, [
            'nome_antes' => $nomeAntes,
            'nome_depois' => $categoria->nome,
        ]);

        return redirect()->route('categorias.index')->with('status', 'Categoria atualizada com sucesso.');
    }

    /**
     * Uma categoria com nome digitado errado que já tem avaliações
     * vinculadas antes ficava permanentemente presa — não dava pra renomear
     * (sem edit()) nem excluir (bloqueado enquanto tivesse avaliações). Em
     * vez de só bloquear, permite escolher pra onde mover as avaliações
     * vinculadas (ou deixá-las sem categoria) antes de excluir.
     */
    public function destroy(Request $request, Categoria $categoria): RedirectResponse
    {
        if ($categoria->filhos()->exists()) {
            return back()->withErrors(['categoria' => 'Esta categoria tem subcategorias — exclua-as primeiro.']);
        }

        $totalAvaliacoes = $categoria->avaliacoes()->count();

        if ($totalAvaliacoes > 0) {
            $destino = $request->input('mover_avaliacoes_para');
            $destino = ($destino !== null && $destino !== '') ? (int) $destino : null;

            if ($destino === $categoria->id) {
                return back()->withErrors(['categoria' => 'Escolha uma categoria de destino diferente da que está sendo excluída.']);
            }

            if ($destino !== null && ! Categoria::where('id', $destino)->exists()) {
                return back()->withErrors(['categoria' => 'Categoria de destino inválida.']);
            }

            $categoria->avaliacoes()->update(['categoria_id' => $destino]);
        }

        $nome = $categoria->nome;
        $categoria->delete();

        AtividadeLogger::registrar('categoria.excluida', 'Categoria', null, [
            'nome' => $nome,
            'avaliacoes_realocadas' => $totalAvaliacoes,
        ]);

        return redirect()->route('categorias.index')->with('status', "Categoria '{$nome}' excluída com sucesso.");
    }
}
