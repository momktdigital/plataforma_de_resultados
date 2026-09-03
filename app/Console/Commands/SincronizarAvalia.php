<?php

namespace App\Console\Commands;

use App\Models\AvaliaSyncExecucao;
use App\Services\Avalia\AvaliaSyncService;
use Illuminate\Console\Command;
use Throwable;

class SincronizarAvalia extends Command
{
    protected $signature = 'avalia:sincronizar {--produto= : avalia_pro ou avalia_online — por padrão sincroniza os dois}';

    protected $description = 'Sincroniza avaliações/questões/respostas do Avalia (+A Data / Redshift) — ver admin/sistema/integracao-avalia';

    public function handle(AvaliaSyncService $service): int
    {
        $produtoUnico = $this->option('produto');
        $produtos = $produtoUnico !== null ? [$produtoUnico] : ['avalia_pro', 'avalia_online'];

        $falhou = false;

        foreach ($produtos as $produto) {
            $this->info("Sincronizando {$produto}...");

            try {
                $execucao = $service->sincronizar($produto, AvaliaSyncExecucao::DISPARADO_AGENDADO);
                $this->info("OK: {$execucao->linhas_lidas} linha(s) lida(s), {$execucao->linhas_gravadas} gravada(s).");
            } catch (Throwable $e) {
                $falhou = true;
                $this->error("Falha ao sincronizar {$produto}: {$e->getMessage()}");
            }
        }

        return $falhou ? self::FAILURE : self::SUCCESS;
    }
}
