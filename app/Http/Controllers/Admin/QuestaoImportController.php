<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportArquivoRequest;
use App\Jobs\ImportarQuestoesJob;
use App\Models\Avaliacao;
use App\Services\QuestaoImportService;
use App\Support\ImportStatusTracker;
use Illuminate\Http\JsonResponse;
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

    /**
     * Pré-visualização síncrona (sem fila, sem tocar o banco): lê só o
     * cabeçalho do arquivo enviado e devolve quais campos o import
     * reconheceria. Consumido via AJAX pela tela antes do usuário confirmar
     * o import de verdade.
     */
    public function preview(ImportArquivoRequest $request, Avaliacao $avaliacao, QuestaoImportService $service): JsonResponse
    {
        try {
            $campos = $service->identificarColunas($request->file('arquivo'));
        } catch (Throwable $e) {
            return response()->json(['erro' => 'Não foi possível ler o arquivo: '.$e->getMessage()], 422);
        }

        return response()->json(['campos' => $campos]);
    }

    public function store(ImportArquivoRequest $request, Avaliacao $avaliacao): RedirectResponse
    {
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
