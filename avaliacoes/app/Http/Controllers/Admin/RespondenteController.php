<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Prova;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Gestão de resultados por respondente de uma Prova — equivalente a
 * admin/resultados.php, adaptado ao novo schema: cada "linha" ali (um aluno +
 * período + avaliação) agora é um grupo de linhas em `respostas`/
 * `resultado_metricas` (uma por questão/métrica).
 */
class RespondenteController extends Controller
{
    public function index(Request $request, Prova $prova): View
    {
        $search = trim((string) $request->query('search', ''));
        $periodo = trim((string) $request->query('periodo', ''));

        $respondentes = $prova->resultados()
            ->select('aluno_chave', 'periodo')
            ->selectRaw('MAX(ra) as ra, MAX(cpf) as cpf, COUNT(*) as total_respostas, MAX(updated_at) as updated_at')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('ra', 'like', "%{$search}%")
                        ->orWhere('cpf', 'like', "%{$search}%")
                        ->orWhere('aluno_chave', 'like', "%{$search}%");
                });
            })
            ->when($periodo !== '', fn ($query) => $query->where('periodo', $periodo))
            ->groupBy('aluno_chave', 'periodo')
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString();

        $periodosDisponiveis = $prova->resultados()->select('periodo')->distinct()->pluck('periodo');

        $trashedNoPeriodo = $periodo !== ''
            ? $prova->resultados()->onlyTrashed()->where('periodo', $periodo)->count()
            : 0;

        return view('admin.provas.respondentes.index', [
            'prova' => $prova,
            'respondentes' => $respondentes,
            'search' => $search,
            'periodo' => $periodo,
            'periodosDisponiveis' => $periodosDisponiveis,
            'trashedNoPeriodo' => $trashedNoPeriodo,
        ]);
    }

    public function show(Request $request, Prova $prova): View
    {
        $chave = (string) $request->query('chave', '');
        $periodo = (string) $request->query('periodo', '');

        abort_if($chave === '', 404);

        $respostas = $prova->resultados()
            ->where('aluno_chave', $chave)
            ->where('periodo', $periodo)
            ->orderBy('questao_numero')
            ->get();

        abort_if($respostas->isEmpty(), 404);

        $gabaritos = $prova->questoes()->whereNotNull('gabarito')->pluck('gabarito', 'numero');
        $metricas = $prova->metricas()->where('aluno_chave', $chave)->where('periodo', $periodo)->get();

        return view('admin.provas.respondentes.show', [
            'prova' => $prova,
            'respostas' => $respostas,
            'gabaritos' => $gabaritos,
            'metricas' => $metricas,
            'chave' => $chave,
            'periodo' => $periodo,
        ]);
    }

    public function destroyPeriodo(Request $request, Prova $prova): RedirectResponse
    {
        $periodo = (string) $request->input('periodo', '');

        $excluidas = $prova->resultados()->where('periodo', $periodo)->delete();
        $excluidas += $prova->metricas()->where('periodo', $periodo)->delete();

        return redirect()
            ->route('provas.respondentes.index', $prova)
            ->with('status', "Resultados do período '{$periodo}' excluídos ({$excluidas} registro(s)).");
    }

    public function restorePeriodo(Request $request, Prova $prova): RedirectResponse
    {
        $periodo = (string) $request->input('periodo', '');

        $restauradas = $prova->resultados()->onlyTrashed()->where('periodo', $periodo)->restore();
        $restauradas += $prova->metricas()->onlyTrashed()->where('periodo', $periodo)->restore();

        return redirect()
            ->route('provas.respondentes.index', ['prova' => $prova, 'periodo' => $periodo])
            ->with('status', "Resultados do período '{$periodo}' restaurados ({$restauradas} registro(s)).");
    }
}
