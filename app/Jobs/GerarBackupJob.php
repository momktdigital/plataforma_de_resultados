<?php

namespace App\Jobs;

use App\Services\Backup\BackupService;
use App\Services\Backup\BackupStatusTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Gera o backup fora do ciclo de vida da requisição HTTP. Um dump do banco
 * inteiro + zip da aplicação facilmente passa do tempo de execução do PHP
 * numa base com volume real de dados — processado aqui pelo worker da fila
 * (`php artisan queue:work`), não existe esse limite: CLI não tem o
 * max_execution_time de uma requisição web.
 */
class GerarBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Tentativa única — reprocessar automaticamente um backup que falhou não faz sentido; o admin decide se tenta de novo. */
    public int $tries = 1;

    /** Segundos antes do worker considerar o job travado — generoso porque a base pode ser grande. */
    public int $timeout = 1800;

    public function handle(BackupService $service): void
    {
        BackupStatusTracker::iniciar();

        $service->gerar();

        BackupStatusTracker::concluir();
    }

    public function failed(Throwable $e): void
    {
        BackupStatusTracker::falhar($e);
    }
}
