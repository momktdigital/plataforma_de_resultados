<?php

namespace App\Jobs;

use App\Services\MatriculaImportService;
use App\Support\AtividadeLogger;
use App\Support\Concerns\PermiteImportacaoLonga;
use App\Support\ImportStatusTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Roda o import de matrícula fora do ciclo de vida da requisição HTTP — ver
 * ImportarResultadosJob para o motivo. Sem escopo por avaliação (matrícula é
 * global), por isso o único import deste tipo em andamento por vez.
 */
class ImportarMatriculaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, PermiteImportacaoLonga, Queueable, SerializesModels;

    /** Tentativa única — reprocessar automaticamente um import que falhou não faz sentido; o admin decide se tenta de novo. */
    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        private readonly string $caminhoArmazenado,
        private readonly string $nomeOriginal,
        // Capturado no controller — ver ImportarResultadosJob para o motivo.
        private readonly ?int $adminId = null,
        private readonly ?string $adminUsername = null,
        private readonly bool $dryRun = false,
    ) {}

    public function handle(MatriculaImportService $service): void
    {
        $this->permitirExecucaoLonga();

        ImportStatusTracker::iniciar('matricula', '', $this->dryRun);

        $this->protegerContraTransacaoOrfa();

        try {
            $resultado = $service->importar($this->arquivo(), $this->dryRun);

            if (! $this->dryRun) {
                AtividadeLogger::registrarComoAdmin($this->adminId, $this->adminUsername, 'import.matricula', null, null, [
                    'arquivo' => $this->nomeOriginal,
                    'linhas' => $resultado->totalLinhas(),
                    'criadas' => $resultado->criadas(),
                    'atualizadas' => $resultado->atualizadas(),
                    'ignoradas' => $resultado->totalIgnoradas(),
                ]);
            }

            ImportStatusTracker::concluir('matricula', '', $resultado);
        } finally {
            Storage::delete($this->caminhoArmazenado);
        }
    }

    public function failed(Throwable $e): void
    {
        ImportStatusTracker::falhar('matricula', '', $e);
        Storage::delete($this->caminhoArmazenado);

        AtividadeLogger::registrarComoAdmin($this->adminId, $this->adminUsername, 'import.matricula_falhou', null, null, [
            'arquivo' => $this->nomeOriginal,
            'erro' => $e->getMessage(),
        ]);
    }

    private function arquivo(): UploadedFile
    {
        return new UploadedFile(Storage::path($this->caminhoArmazenado), $this->nomeOriginal, test: true);
    }
}
