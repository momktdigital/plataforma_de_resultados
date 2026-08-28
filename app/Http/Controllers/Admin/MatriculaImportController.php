<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportArquivoRequest;
use App\Jobs\ImportarMatriculaJob;
use App\Support\ImportStatusTracker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class MatriculaImportController extends Controller
{
    public function create(): View
    {
        return view('admin.alunos.importar', [
            'importStatus' => ImportStatusTracker::status('matricula'),
        ]);
    }

    public function store(ImportArquivoRequest $request): RedirectResponse
    {
        $arquivo = $request->file('arquivo');
        $caminho = $arquivo->store('imports');

        $admin = Auth::guard('admin')->user();
        $dryRun = $request->boolean('dry_run');

        // Ver ResultadoImportController::store() — mesmo raciocínio do try/catch.
        try {
            ImportarMatriculaJob::dispatch($caminho, $arquivo->getClientOriginalName(), $admin?->id, $admin?->username, $dryRun);
        } catch (Throwable $e) {
            Storage::delete($caminho);
            Log::error('Falha ao solicitar import de matrícula.', ['exception' => $e]);

            return redirect()->route('alunos.importar')
                ->withErrors(['arquivo' => 'Não foi possível iniciar o import: '.$e->getMessage()]);
        }

        return redirect()->route('alunos.importar')
            ->with('status', $dryRun
                ? 'Simulação de import de matrícula solicitada — nada será gravado.'
                : 'Import de matrícula solicitado — está sendo processado em segundo plano.');
    }
}
