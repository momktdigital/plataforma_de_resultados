<?php

namespace App\Console\Commands;

use App\Services\PlanoAcaoLembreteService;
use Illuminate\Console\Command;

/**
 * Cria os lembretes dos planos de ação em execução (prazo próximo ou vencido, plano parado). Roda todo dia pelo agendador
 * (ver bootstrap/app.php) e pode ser chamado à mão.
 */
class PlanosLembretes extends Command
{
    protected $signature = 'planos:lembretes';

    protected $description = 'Avisa os coordenadores sobre ações de planos de ação com prazo próximo ou vencido';

    public function handle(PlanoAcaoLembreteService $servico): int
    {
        $this->info($servico->gerar().' lembrete(s) criado(s).');

        return self::SUCCESS;
    }
}
