<?php

namespace App\Http\Controllers\Admin;

use App\Services\ComparacaoSemestresService;
use App\Services\CoordenadorDashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Compara dois períodos letivos do curso do coordenador, categoria a categoria.
 */
class CoordenadorComparativoController extends PainelController
{
    public function index(Request $request, CoordenadorDashboardService $dashboard, ComparacaoSemestresService $servico): View|RedirectResponse
    {
        if (($usuario = $this->coordenador()) === null) {
            return redirect()->route('avaliacoes.index');
        }

        $curso = $this->cursoEscolhido($request);
        $base = $dashboard->escopo($usuario, $curso, '');

        if ($this->semDados($base)) {
            return view('coordenador.comparativo', ['usuario' => $usuario, 'painel' => $base]);
        }

        // Do mais recente ao mais antigo.
        $periodos = $base['periodosDisponiveis'];
        $atual = (string) $request->query('periodo_letivo', '');
        if (! in_array($atual, $periodos, true)) {
            $atual = $periodos[0] ?? '';
        }
        $painel = [...$base, 'periodoSelecionado' => $atual];

        // Padrão: o período imediatamente anterior ao escolhido (ou, se for o mais antigo, o seguinte).
        $referencia = (string) $request->query('comparar', '');
        if (! in_array($referencia, $periodos, true) || $referencia === $atual) {
            $posicao = array_search($atual, $periodos, true);
            $referencia = $periodos[$posicao + 1] ?? ($periodos[$posicao - 1] ?? '');
        }

        if ($atual === '' || $referencia === '') {
            return view('coordenador.comparativo', ['usuario' => $usuario, 'painel' => $painel, 'poucosPeriodos' => true]);
        }

        return view('coordenador.comparativo', [
            'usuario' => $usuario,
            'painel' => $painel,
            'filtrosEscolhidos' => [
                'categoria' => trim((string) $request->query('categoria', '')),
                'periodo_curso' => trim((string) $request->query('periodo_curso', '')),
            ],
            'periodos' => $periodos,
            'atual' => $atual,
            'referencia' => $referencia,
            'comparacao' => $servico->comparar($usuario, $curso, $atual, $referencia, [
                'categoria' => trim((string) $request->query('categoria', '')),
                'periodo_curso' => trim((string) $request->query('periodo_curso', '')),
            ]),
        ]);
    }
}
