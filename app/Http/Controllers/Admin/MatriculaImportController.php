<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportArquivoRequest;
use App\Services\MatriculaImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use RuntimeException;

class MatriculaImportController extends Controller
{
    public function create(): View
    {
        return view('admin.alunos.importar');
    }

    public function store(ImportArquivoRequest $request, MatriculaImportService $service): RedirectResponse
    {
        try {
            $resultado = $service->importar($request->file('arquivo'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['arquivo' => $e->getMessage()]);
        }

        return redirect()
            ->route('alunos.index')
            ->with('status', "Import de matrícula: {$resultado->resumo()}")
            ->with('importIgnoradas', $resultado->ignoradas());
    }
}
