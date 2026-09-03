<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportArquivoRequest;
use App\Jobs\ImportarQuestoesJob;
use App\Models\Avaliacao;
use App\Support\ImportStatusTracker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class QuestaoImportController extends Controller
{
    public function create(Avaliacao $avaliacao): View
    {
        return view('admin.questoes.import', [
            'avaliacao' => $avaliacao,
            'importStatus' => ImportStatusTracker::status('questoes', (string) $avaliacao->codigo),
        ]);
    }

    public function store(ImportArquivoRequest $request, Avaliacao $avaliacao): RedirectResponse
    {
        if ($avaliacao->origemBloqueiaEdicao()) {
            return back()->withErrors(['origem' => 'Esta avaliação foi sincronizada do Avalia e não aceita import manual de questões.']);
        }

        $arquivo = $request->file('arquivo');
        $caminho = $arquivo->store('imports');

        $admin = Auth::guard('admin')->user();
        $dryRun = $request->boolean('dry_run');

        // Ver ResultadoImportController::store() — mesmo raciocínio do try/catch.
        try {
            ImportarQuestoesJob::dispatch($avaliacao->codigo, $caminho, $arquivo->getClientOriginalName(), $admin?->id, $admin?->username, $dryRun);
        } catch (Throwable $e) {
            Storage::delete($caminho);
            Log::error('Falha ao solicitar import de questões.', ['exception' => $e]);

            return redirect()->route('avaliacoes.questoes.import', $avaliacao)
                ->withErrors(['arquivo' => 'Não foi possível iniciar o import: '.$e->getMessage()]);
        }

        return redirect()->route('avaliacoes.questoes.import', $avaliacao)
            ->with('status', $dryRun
                ? 'Simulação de import de questões solicitada — nada será gravado.'
                : 'Import de questões solicitado — está sendo processado em segundo plano.');
    }
}
