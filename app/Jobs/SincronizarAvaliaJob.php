<?php

namespace App\Jobs;

use App\Models\AvaliaSyncExecucao;
use App\Services\Avalia\AvaliaSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sincronização disparada pelo botão "Forçar sincronização agora" da tela de
 * Integração Avalia — roda fora do ciclo de vida da requisição HTTP pelo
 * worker da fila (`php artisan queue:work`), igual a GerarBackupJob. A
 * sincronização agendada (a cada 12h, ver bootstrap/app.php) NÃO passa por
 * aqui: `avalia:sincronizar` chama AvaliaSyncService diretamente, já que um
 * comando de CLI não tem o limite de tempo de uma requisição web.
 */
class SincronizarAvaliaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Tentativa única — reprocessar automaticamente uma sincronização que falhou não faz sentido; o admin decide se tenta de novo. */
    public int $tries = 1;

    /** Segundos antes do worker considerar o job travado — generoso porque o volume de dados pode ser grande. */
    public int $timeout = 1800;

    public function __construct(
        public readonly string $produto,
        public readonly ?int $adminId = null,
    ) {}

    public function handle(AvaliaSyncService $service): void
    {
        // AvaliaSyncService::sincronizar() já registra sucesso/erro em
        // avalia_sync_execucoes antes de relançar a exceção — nada extra a
        // fazer aqui em caso de falha (sem failed(), a tela de logs já
        // reflete o que aconteceu).
        $service->sincronizar($this->produto, AvaliaSyncExecucao::DISPARADO_MANUAL, $this->adminId);
    }
}
