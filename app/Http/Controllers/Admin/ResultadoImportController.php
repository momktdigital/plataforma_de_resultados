<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportArquivoRequest;
use App\Models\Avaliacao;
use App\Services\ResultadoImportService;
use App\Services\ResumoResultadoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use RuntimeException;

class ResultadoImportController extends Controller
{
    public function create(Avaliacao $avaliacao): View
    {
        return view('admin.resultados.import', ['avaliacao' => $avaliacao]);
    }

    public function store(
        ImportArquivoRequest $request,
        Avaliacao $avaliacao,
        ResultadoImportService $service,
        ResumoResultadoService $resumos,
    ): RedirectResponse {
        try {
            $resultado = $service->importar($avaliacao, $request->file('arquivo'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['arquivo' => $e->getMessage()]);
        }

        $resumos->recalcular($avaliacao->codigo);

        return redirect()
            ->route('avaliacoes.show', $avaliacao)
            ->with('status', "Import de resultados: {$resultado->resumo()}")
            ->with('importIgnoradas', $resultado->ignoradas());
    }
}
