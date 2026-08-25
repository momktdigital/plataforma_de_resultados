<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Avaliacao;
use App\Services\Visualizacoes\VisualizacaoConfigService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AvaliacaoVisualizacaoController extends Controller
{
    public function edit(Avaliacao $avaliacao, VisualizacaoConfigService $service): View
    {
        return view('admin.avaliacoes.visualizacoes', [
            'avaliacao' => $avaliacao,
            'estado' => $service->estadoCompleto($avaliacao),
        ]);
    }

    public function update(Request $request, Avaliacao $avaliacao, VisualizacaoConfigService $service): RedirectResponse
    {
        $dados = $request->validate([
            'visuais' => ['array'],
            'visuais.*.admin' => ['nullable', 'boolean'],
            'visuais.*.aluno' => ['nullable', 'boolean'],
        ]);

        $service->salvar($avaliacao, $dados['visuais'] ?? []);

        return redirect()
            ->route('avaliacoes.visualizacoes.edit', $avaliacao)
            ->with('status', 'Visualizações atualizadas com sucesso.');
    }
}
