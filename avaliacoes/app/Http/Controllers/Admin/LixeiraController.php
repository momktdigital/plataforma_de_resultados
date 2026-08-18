<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Prova;
use App\Models\Questao;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Lixeira global — equivalente a admin/lixeira.php. Lista Provas excluídas
 * (com tudo que elas continham) e Questões excluídas individualmente de
 * provas que continuam ativas; resultados/métricas excluídos em lote (ver
 * RespondenteController::destroyPeriodo) são restaurados/expurgados por
 * período direto na tela de respondentes, não listados um a um aqui.
 */
class LixeiraController extends Controller
{
    public function index(): View
    {
        $provas = Prova::onlyTrashed()->orderByDesc('deleted_at')->get();

        $questoes = Questao::onlyTrashed()
            ->whereHas('prova')
            ->with('prova')
            ->orderByDesc('deleted_at')
            ->get();

        return view('admin.lixeira.index', ['provas' => $provas, 'questoes' => $questoes]);
    }

    public function restoreProva(int $prova): RedirectResponse
    {
        $provaModel = Prova::withTrashed()->findOrFail($prova);
        $provaModel->restore();
        $provaModel->questoes()->onlyTrashed()->restore();
        $provaModel->resultados()->onlyTrashed()->restore();
        $provaModel->metricas()->onlyTrashed()->restore();

        return back()->with('status', "Prova #{$provaModel->codigo} restaurada.");
    }

    public function forceDeleteProva(int $prova): RedirectResponse
    {
        $provaModel = Prova::withTrashed()->findOrFail($prova);
        $codigo = $provaModel->codigo;
        $provaModel->forceDelete();

        return back()->with('status', "Prova #{$codigo} excluída permanentemente.");
    }

    public function restoreQuestao(int $questao): RedirectResponse
    {
        $questaoModel = Questao::withTrashed()->findOrFail($questao);
        $questaoModel->restore();

        return back()->with('status', "Questão {$questaoModel->numero} restaurada.");
    }

    public function forceDeleteQuestao(int $questao): RedirectResponse
    {
        $questaoModel = Questao::withTrashed()->findOrFail($questao);
        $numero = $questaoModel->numero;
        $questaoModel->forceDelete();

        return back()->with('status', "Questão {$numero} excluída permanentemente.");
    }
}
