<?php

namespace App\Jobs;

use App\Models\Avaliacao;
use App\Services\QuestaoImportService;
use App\Services\ResumoResultadoService;
use App\Support\AtividadeLogger;
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
 * Roda o import de questões/gabarito fora do ciclo de vida da requisição
 * HTTP — ver ImportarResultadosJob para o motivo.
 */
class ImportarQuestoesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Tentativa única — reprocessar automaticamente um import que falhou não faz sentido; o admin decide se tenta de novo. */
    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        private readonly int $avaliacaoCodigo,
        private readonly string $caminhoArmazenado,
        private readonly string $nomeOriginal,
        // Capturado no controller — ver ImportarResultadosJob para o motivo.
        private readonly ?int $adminId = null,
        private readonly ?string $adminUsername = null,
    ) {}

    public function handle(QuestaoImportService $service, ResumoResultadoService $resumos): void
    {
        $avaliacao = Avaliacao::findOrFail($this->avaliacaoCodigo);

        ImportStatusTracker::iniciar('questoes', (string) $avaliacao->codigo);

        try {
            $resultado = $service->importar($avaliacao, $this->arquivo());

            // Gabarito mudou: o "total" e os acertos de todo mundo que já
            // respondeu esta avaliação (em qualquer período) podem ter mudado junto.
            $resumos->recalcular($avaliacao->codigo);

            ImportStatusTracker::concluir('questoes', (string) $avaliacao->codigo, $resultado);

            AtividadeLogger::registrarComoAdmin($this->adminId, $this->adminUsername, 'import.questoes', 'Avaliacao', $avaliacao->codigo, [
                'arquivo' => $this->nomeOriginal,
                'linhas' => $resultado->totalLinhas(),
                'criadas' => $resultado->criadas(),
                'atualizadas' => $resultado->atualizadas(),
                'ignoradas' => $resultado->totalIgnoradas(),
            ]);
        } finally {
            Storage::delete($this->caminhoArmazenado);
        }
    }

    public function failed(Throwable $e): void
    {
        ImportStatusTracker::falhar('questoes', (string) $this->avaliacaoCodigo, $e);
        Storage::delete($this->caminhoArmazenado);

        AtividadeLogger::registrarComoAdmin($this->adminId, $this->adminUsername, 'import.questoes_falhou', 'Avaliacao', $this->avaliacaoCodigo, [
            'arquivo' => $this->nomeOriginal,
            'erro' => $e->getMessage(),
        ]);
    }

    private function arquivo(): UploadedFile
    {
        return new UploadedFile(Storage::path($this->caminhoArmazenado), $this->nomeOriginal, test: true);
    }
}
