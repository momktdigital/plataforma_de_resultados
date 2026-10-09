<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlanoAcao;
use App\Services\PlanoAcaoQuadroService;
use Illuminate\View\View;

/**
 * Planos de ação na visão da reitoria: SÓ NÚMEROS por curso (quantos em cada situação, execução das ações, tempo de análise).
 * Nunca o texto de um plano — causa-raiz, ações e responsáveis são do coordenador e do colaborador; a reitoria acompanha o
 * andamento. (O administrador também entra, como no resto do painel da reitoria.)
 */
class ReitorPlanosController extends Controller
{
    public function index(PlanoAcaoQuadroService $quadro): View
    {
        $planos = PlanoAcao::enviados()->with(['acoes', 'eventos'])->get();
        $linhas = $quadro->porCurso($planos);

        return view('reitor.planos', ['linhas' => $linhas, 'totais' => $quadro->totais($linhas, $planos)]);
    }
}
