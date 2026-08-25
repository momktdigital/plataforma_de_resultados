<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Services\ResumoResultadoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Lixeira global — equivalente a admin/lixeira.php. Lista Avaliacoes excluídas
 * (com tudo que elas continham) e Questões excluídas individualmente de
 * avaliacoes que continuam ativas; resultados/métricas excluídos em lote (ver
 * RespondenteController::destroyPeriodo) são restaurados/expurgados por
 * período direto na tela de respondentes, não listados um a um aqui.
 */
class LixeiraController extends Controller
{
    public function index(): View
    {
        $avaliacoes = Avaliacao::onlyTrashed()->orderByDesc('deleted_at')->get();

        $questoes = Questao::onlyTrashed()
            ->whereHas('avaliacao')
            ->with('avaliacao')
            ->orderByDesc('deleted_at')
            ->get();

        return view('admin.lixeira.index', ['avaliacoes' => $avaliacoes, 'questoes' => $questoes]);
    }

    public function restoreAvaliacao(int $avaliacao, ResumoResultadoService $resumos): RedirectResponse
    {
        $avaliacaoModel = Avaliacao::withTrashed()->findOrFail($avaliacao);
        $avaliacaoModel->restore();
        $avaliacaoModel->questoes()->onlyTrashed()->restore();
        $avaliacaoModel->resultados()->onlyTrashed()->restore();
        $avaliacaoModel->metricas()->onlyTrashed()->restore();

        $resumos->recalcular($avaliacaoModel->codigo);

        return back()->with('status', "Avaliação #{$avaliacaoModel->codigo} restaurada.");
    }

    public function forceDeleteAvaliacao(int $avaliacao): RedirectResponse
    {
        $avaliacaoModel = Avaliacao::withTrashed()->findOrFail($avaliacao);
        $codigo = $avaliacaoModel->codigo;
        $avaliacaoModel->forceDelete();

        return back()->with('status', "Avaliacao #{$codigo} excluída permanentemente.");
    }

    public function restoreQuestao(int $questao, ResumoResultadoService $resumos): RedirectResponse
    {
        $questaoModel = Questao::withTrashed()->findOrFail($questao);
        $questaoModel->restore();

        $resumos->recalcular($questaoModel->avaliacao_codigo);

        return back()->with('status', "Questão {$questaoModel->numero} restaurada.");
    }

    public function forceDeleteQuestao(int $questao, ResumoResultadoService $resumos): RedirectResponse
    {
        $questaoModel = Questao::withTrashed()->findOrFail($questao);
        $numero = $questaoModel->numero;
        $avaliacaoCodigo = $questaoModel->avaliacao_codigo;
        $questaoModel->forceDelete();

        $resumos->recalcular($avaliacaoCodigo);

        return back()->with('status', "Questão {$numero} excluída permanentemente.");
    }
}
