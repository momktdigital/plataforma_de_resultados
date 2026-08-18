<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

class GerarBackup extends Command
{
    protected $signature = 'sistema:backup';

    protected $description = 'Gera um backup completo (aplicação + banco de dados) em storage/app/backups';

    public function handle(BackupService $service): int
    {
        $this->info('Gerando backup...');

        $caminho = $service->gerar();

        $this->info('Backup criado em: '.$caminho);

        return self::SUCCESS;
    }
}
