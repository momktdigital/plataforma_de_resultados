<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuestaoRequest;
use App\Models\Prova;
use App\Models\Questao;
use Illuminate\Http\RedirectResponse;

/**
 * Editor manual de uma questão por vez — equivalente ao "Editor de Gabarito
 * Manual" de admin/avaliacao_editar.php, adaptado ao novo schema (número de
 * questão livre, não uma grade fixa Q1..Q100).
 */
class QuestaoController extends Controller
{
    public function store(StoreQuestaoRequest $request, Prova $prova): RedirectResponse
    {
        $dados = $request->validated();

        $questao = Questao::withTrashed()
            ->where('prova_codigo', $prova->codigo)
            ->where('numero', $dados['numero'])
            ->first();

        if ($questao === null) {
            $questao = new Questao(['prova_codigo' => $prova->codigo, 'numero' => $dados['numero']]);
        } elseif ($questao->trashed()) {
            $questao->restore();
        }

        $questao->gabarito = mb_strtoupper($dados['gabarito'], 'UTF-8');
        $questao->save();

        return redirect()
            ->route('provas.show', $prova)
            ->with('status', "Questão {$dados['numero']} salva com sucesso.");
    }

    public function destroy(Prova $prova, Questao $questao): RedirectResponse
    {
        $questao->delete();

        return redirect()
            ->route('provas.show', $prova)
            ->with('status', "Questão {$questao->numero} excluída.");
    }

    public function restore(Prova $prova, int $questao): RedirectResponse
    {
        $questaoModel = Questao::withTrashed()
            ->where('prova_codigo', $prova->codigo)
            ->findOrFail($questao);
        $questaoModel->restore();

        return redirect()
            ->route('provas.show', $prova)
            ->with('status', "Questão {$questaoModel->numero} restaurada.");
    }
}
