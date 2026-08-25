<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportArquivoRequest;
use App\Models\Avaliacao;
use App\Services\QuestaoImportService;
use App\Services\ResumoResultadoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use RuntimeException;

class QuestaoImportController extends Controller
{
    public function create(Avaliacao $avaliacao): View
    {
        return view('admin.questoes.import', ['avaliacao' => $avaliacao]);
    }

    public function store(
        ImportArquivoRequest $request,
        Avaliacao $avaliacao,
        QuestaoImportService $service,
        ResumoResultadoService $resumos,
    ): RedirectResponse {
        try {
            $resultado = $service->importar($avaliacao, $request->file('arquivo'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['arquivo' => $e->getMessage()]);
        }

        // Gabarito mudou: o "total" e os acertos de todo mundo que já
        // respondeu esta avaliação (em qualquer período) podem ter mudado junto.
        $resumos->recalcular($avaliacao->codigo);

        return redirect()
            ->route('avaliacoes.show', $avaliacao)
            ->with('status', "Import de questões: {$resultado->resumo()}")
            ->with('importIgnoradas', $resultado->ignoradas());
    }
}
