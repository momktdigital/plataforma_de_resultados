<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportArquivoRequest;
use App\Jobs\ImportarResultadosJob;
use App\Models\Avaliacao;
use App\Services\ResultadoImportService;
use App\Support\ImportStatusTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class ResultadoImportController extends Controller
{
    public function create(Avaliacao $avaliacao): View
    {
        return view('admin.resultados.import', [
            'avaliacao' => $avaliacao,
            'importStatus' => ImportStatusTracker::status('resultados', (string) $avaliacao->codigo),
        ]);
    }

    /**
     * Pré-visualização síncrona (sem fila, sem tocar o banco): lê só o
     * cabeçalho do arquivo enviado, detecta o formato (longo ou largo) e
     * devolve quais campos o import reconheceria — ver
     * QuestaoImportController::preview(), mesmo padrão.
     */
    public function preview(ImportArquivoRequest $request, Avaliacao $avaliacao, ResultadoImportService $service): JsonResponse
    {
        try {
            $info = $service->identificarColunas($request->file('arquivo'));
        } catch (Throwable $e) {
            return response()->json(['erro' => 'Não foi possível ler o arquivo: '.$e->getMessage()], 422);
        }

        return response()->json($info);
    }

    public function store(ImportArquivoRequest $request, Avaliacao $avaliacao): RedirectResponse
    {
        $arquivo = $request->file('arquivo');
        $caminho = $arquivo->store('imports');

        // Em produção (fila assíncrona) o job roda fora desta requisição — se
        // ele falhar, ImportarResultadosJob::failed() já registra o erro.
        // Este try/catch cobre o caso de QUEUE_CONNECTION=sync (ex.: testes)
        // ou de uma falha ao simplesmente enfileirar o job.
        $admin = Auth::guard('admin')->user();
        $dryRun = $request->boolean('dry_run');

        try {
            ImportarResultadosJob::dispatch($avaliacao->codigo, $caminho, $arquivo->getClientOriginalName(), $admin?->id, $admin?->username, $dryRun);
        } catch (Throwable $e) {
            Storage::delete($caminho);
            Log::error('Falha ao solicitar import de resultados.', ['exception' => $e]);

            return redirect()->route('avaliacoes.resultados.import', $avaliacao)
                ->withErrors(['arquivo' => 'Não foi possível iniciar o import: '.$e->getMessage()]);
        }

        return redirect()->route('avaliacoes.resultados.import', $avaliacao)
            ->with('status', $dryRun
                ? 'Simulação de import de resultados solicitada — nada será gravado.'
                : 'Import de resultados solicitado — está sendo processado em segundo plano.');
    }
}
