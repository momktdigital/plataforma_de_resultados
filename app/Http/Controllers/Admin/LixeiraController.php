<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Services\ResumoResultadoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        // Duas listas independentes na mesma tela — cada paginate() usa um
        // nome de página próprio (senão as duas competeriam pelo mesmo
        // parâmetro `page` na query string).
        $avaliacoes = Avaliacao::onlyTrashed()
            ->orderByDesc('deleted_at')
            ->paginate(20, ['*'], 'avaliacoes_page')
            ->withQueryString();

        $questoes = Questao::onlyTrashed()
            ->whereHas('avaliacao')
            ->with('avaliacao')
            ->orderByDesc('deleted_at')
            ->paginate(20, ['*'], 'questoes_page')
            ->withQueryString();

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

    /** @return array<int, int> */
    private function idsSelecionados(Request $request): array
    {
        return array_values(array_unique(array_map('intval', $request->input('ids', []))));
    }

    public function restoreAvaliacoesBulk(Request $request, ResumoResultadoService $resumos): RedirectResponse
    {
        $ids = $this->idsSelecionados($request);
        if ($ids === []) {
            return back()->withErrors(['ids' => 'Selecione ao menos uma avaliação.']);
        }

        $avaliacoes = Avaliacao::onlyTrashed()->whereIn('codigo', $ids)->get();

        foreach ($avaliacoes as $avaliacaoModel) {
            $avaliacaoModel->restore();
            $avaliacaoModel->questoes()->onlyTrashed()->restore();
            $avaliacaoModel->resultados()->onlyTrashed()->restore();
            $avaliacaoModel->metricas()->onlyTrashed()->restore();
            $resumos->recalcular($avaliacaoModel->codigo);
        }

        return back()->with('status', $avaliacoes->count().' avaliação(ões) restaurada(s).');
    }

    public function forceDeleteAvaliacoesBulk(Request $request): RedirectResponse
    {
        $ids = $this->idsSelecionados($request);
        if ($ids === []) {
            return back()->withErrors(['ids' => 'Selecione ao menos uma avaliação.']);
        }

        $avaliacoes = Avaliacao::onlyTrashed()->whereIn('codigo', $ids)->get();

        foreach ($avaliacoes as $avaliacaoModel) {
            $avaliacaoModel->forceDelete();
        }

        return back()->with('status', $avaliacoes->count().' avaliação(ões) excluída(s) permanentemente.');
    }

    public function restoreQuestoesBulk(Request $request, ResumoResultadoService $resumos): RedirectResponse
    {
        $ids = $this->idsSelecionados($request);
        if ($ids === []) {
            return back()->withErrors(['ids' => 'Selecione ao menos uma questão.']);
        }

        $questoes = Questao::onlyTrashed()->whereIn('id', $ids)->get();

        foreach ($questoes as $questaoModel) {
            $questaoModel->restore();
        }

        foreach ($questoes->pluck('avaliacao_codigo')->unique() as $avaliacaoCodigo) {
            $resumos->recalcular($avaliacaoCodigo);
        }

        return back()->with('status', $questoes->count().' questão(ões) restaurada(s).');
    }

    public function forceDeleteQuestoesBulk(Request $request, ResumoResultadoService $resumos): RedirectResponse
    {
        $ids = $this->idsSelecionados($request);
        if ($ids === []) {
            return back()->withErrors(['ids' => 'Selecione ao menos uma questão.']);
        }

        $questoes = Questao::onlyTrashed()->whereIn('id', $ids)->get();
        $avaliacoesTocadas = $questoes->pluck('avaliacao_codigo')->unique();

        foreach ($questoes as $questaoModel) {
            $questaoModel->forceDelete();
        }

        foreach ($avaliacoesTocadas as $avaliacaoCodigo) {
            $resumos->recalcular($avaliacaoCodigo);
        }

        return back()->with('status', $questoes->count().' questão(ões) excluída(s) permanentemente.');
    }
}
