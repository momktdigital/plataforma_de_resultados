<?php

namespace App\Jobs;

use App\Models\Avaliacao;
use App\Services\ResultadoImportService;
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
 * Roda o import de resultados fora do ciclo de vida da requisição HTTP —
 * uma planilha de 100 mil+ linhas facilmente passa do tempo de execução do
 * PHP e do timeout do proxy/load balancer na frente dele (nginx/ALB cortam
 * em 30-60s) se processada de forma síncrona. Mesmo espírito do
 * GerarBackupJob: processado pelo worker da fila (`php artisan queue:work`),
 * sem o limite de tempo de uma requisição web.
 */
class ImportarResultadosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Tentativa única — reprocessar automaticamente um import que falhou não faz sentido; o admin decide se tenta de novo. */
    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        private readonly int $avaliacaoCodigo,
        private readonly string $caminhoArmazenado,
        private readonly string $nomeOriginal,
        // Capturado no controller (dentro da requisição, com sessão de admin
        // disponível) — o worker da fila que roda este job não tem sessão
        // nenhuma, então não daria pra descobrir isso de dentro do handle().
        private readonly ?int $adminId = null,
        private readonly ?string $adminUsername = null,
    ) {}

    public function handle(ResultadoImportService $service, ResumoResultadoService $resumos): void
    {
        $avaliacao = Avaliacao::findOrFail($this->avaliacaoCodigo);

        ImportStatusTracker::iniciar('resultados', (string) $avaliacao->codigo);

        try {
            $resultado = $service->importar($avaliacao, $this->arquivo());
            $resumos->recalcular($avaliacao->codigo);

            ImportStatusTracker::concluir('resultados', (string) $avaliacao->codigo, $resultado);

            AtividadeLogger::registrarComoAdmin($this->adminId, $this->adminUsername, 'import.resultados', 'Avaliacao', $avaliacao->codigo, [
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
        ImportStatusTracker::falhar('resultados', (string) $this->avaliacaoCodigo, $e);
        Storage::delete($this->caminhoArmazenado);

        AtividadeLogger::registrarComoAdmin($this->adminId, $this->adminUsername, 'import.resultados_falhou', 'Avaliacao', $this->avaliacaoCodigo, [
            'arquivo' => $this->nomeOriginal,
            'erro' => $e->getMessage(),
        ]);
    }

    private function arquivo(): UploadedFile
    {
        return new UploadedFile(Storage::path($this->caminhoArmazenado), $this->nomeOriginal, test: true);
    }
}
