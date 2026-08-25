<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use App\Services\Backup\BackupStatusTracker;
use Illuminate\Console\Command;
use Throwable;

class GerarBackup extends Command
{
    protected $signature = 'sistema:backup';

    protected $description = 'Gera um backup completo (aplicação + banco de dados) em storage/app/backups';

    public function handle(BackupService $service): int
    {
        $this->info('Gerando backup...');

        BackupStatusTracker::iniciar();

        try {
            $caminho = $service->gerar();
        } catch (Throwable $e) {
            BackupStatusTracker::falhar($e);
            $this->error('Falha ao gerar backup: '.$e->getMessage());

            return self::FAILURE;
        }

        BackupStatusTracker::concluir();
        $this->info('Backup criado em: '.$caminho);

        return self::SUCCESS;
    }
}
