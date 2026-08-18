<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportArquivoRequest;
use App\Models\Prova;
use App\Services\ResultadoImportService;
use App\Services\ResumoResultadoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use RuntimeException;

class ResultadoImportController extends Controller
{
    public function create(Prova $prova): View
    {
        return view('admin.resultados.import', ['prova' => $prova]);
    }

    public function store(
        ImportArquivoRequest $request,
        Prova $prova,
        ResultadoImportService $service,
        ResumoResultadoService $resumos,
    ): RedirectResponse {
        try {
            $resultado = $service->importar($prova, $request->file('arquivo'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['arquivo' => $e->getMessage()]);
        }

        $resumos->recalcular($prova->codigo);

        return redirect()
            ->route('provas.show', $prova)
            ->with('status', "Import de resultados: {$resultado->resumo()}")
            ->with('importIgnoradas', $resultado->ignoradas());
    }
}
