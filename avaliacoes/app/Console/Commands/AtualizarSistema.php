<?php

namespace App\Console\Commands;

use App\Services\Update\UpdateService;
use Illuminate\Console\Command;

class AtualizarSistema extends Command
{
    protected $signature = 'sistema:atualizar {--check : Só verifica se há uma versão nova, sem aplicar}';

    protected $description = 'Verifica e aplica a última Release publicada no GitHub';

    public function handle(UpdateService $service): int
    {
        $this->info('Versão instalada: '.$service->versaoAtual());

        $disponivel = $service->verificarAtualizacao();

        if ($disponivel === null) {
            $this->info('Nenhuma atualização disponível.');

            return self::SUCCESS;
        }

        $this->info("Nova versão disponível: {$disponivel['versao']}");

        if ($this->option('check')) {
            return self::SUCCESS;
        }

        $resultado = $service->atualizar();

        foreach ($resultado['mensagens'] as $mensagem) {
            $this->line($mensagem);
        }

        return $resultado['status'] === 'erro' ? self::FAILURE : self::SUCCESS;
    }
}
