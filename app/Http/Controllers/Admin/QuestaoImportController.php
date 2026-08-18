<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportArquivoRequest;
use App\Models\Prova;
use App\Services\QuestaoImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use RuntimeException;

class QuestaoImportController extends Controller
{
    public function create(Prova $prova): View
    {
        return view('admin.questoes.import', ['prova' => $prova]);
    }

    public function store(ImportArquivoRequest $request, Prova $prova, QuestaoImportService $service): RedirectResponse
    {
        try {
            $resultado = $service->importar($prova, $request->file('arquivo'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['arquivo' => $e->getMessage()]);
        }

        return redirect()
            ->route('provas.show', $prova)
            ->with('status', "Import de questões: {$resultado->resumo()}")
            ->with('importIgnoradas', $resultado->ignoradas());
    }
}
