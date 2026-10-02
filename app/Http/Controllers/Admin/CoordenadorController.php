<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Avaliacao;
use App\Services\CoordenadorDashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Painel do coordenador: desempenho do(s) curso(s) dele, por período letivo.
 */
class CoordenadorController extends Controller
{
    public function painel(Request $request, CoordenadorDashboardService $servico): View|RedirectResponse
    {
        $usuario = Auth::guard('admin')->user();

        // O painel é sobre "o meu curso" — administrador não tem curso.
        if (! $usuario->ehCoordenador()) {
            return redirect()->route('avaliacoes.index');
        }

        // Sem o parâmetro = período letivo mais recente; `periodo_letivo=` vazio = "Todos".
        $periodo = $request->has('periodo_letivo') ? (string) $request->query('periodo_letivo', '') : null;

        $painel = $servico->gerar($usuario, trim((string) $request->query('curso', '')), $periodo);

        // Só linka pro BI as avaliações que ele de fato pode abrir (a curadoria
        // manual dos cursos da avaliação pode ter removido o acesso).
        $codigosAcessiveis = Avaliacao::visivelPara($usuario)
            ->whereIn('codigo', collect($painel['categorias'] ?? [])->flatMap(fn ($c) => $c['avaliacoes'])->pluck('codigo'))
            ->pluck('codigo')
            ->all();

        return view('coordenador.painel', [
            'usuario' => $usuario,
            'painel' => $painel,
            'codigosAcessiveis' => $codigosAcessiveis,
        ]);
    }
}
