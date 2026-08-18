<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Prova;
use App\Services\BiDashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BiController extends Controller
{
    public function index(Request $request, Prova $prova, BiDashboardService $service): View
    {
        $periodo = trim((string) $request->query('periodo', ''));
        $periodosDisponiveis = $prova->resultados()->select('periodo')->distinct()->pluck('periodo');

        return view('admin.provas.bi', [
            'prova' => $prova,
            'periodo' => $periodo,
            'periodosDisponiveis' => $periodosDisponiveis,
            'dados' => $service->gerar($prova, $periodo),
        ]);
    }
}
