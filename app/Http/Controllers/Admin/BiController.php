<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Avaliacao;
use App\Services\BiDashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BiController extends Controller
{
    public function index(Request $request, Avaliacao $avaliacao, BiDashboardService $service): View
    {
        $periodo = trim((string) $request->query('periodo', ''));
        $periodosDisponiveis = $avaliacao->resultados()->select('periodo')->distinct()->pluck('periodo');

        return view('admin.avaliacoes.bi', [
            'avaliacao' => $avaliacao,
            'periodo' => $periodo,
            'periodosDisponiveis' => $periodosDisponiveis,
            'dados' => $service->gerar($avaliacao, $periodo),
        ]);
    }
}
