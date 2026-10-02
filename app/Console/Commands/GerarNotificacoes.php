<?php

namespace App\Console\Commands;

use App\Models\Avaliacao;
use App\Services\NotificacaoCoordenadorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gera (ou atualiza) as notificações dos coordenadores para avaliações que já têm resultados — útil para quem já
 * tinha dados importados antes das notificações existirem. Novos imports geram os avisos sozinhos.
 */
class GerarNotificacoes extends Command
{
    protected $signature = 'notificacoes:gerar {avaliacao? : Código de uma avaliação (sem informar, todas as que têm resultados)}';

    protected $description = 'Gera as notificações dos coordenadores a partir dos resultados já importados';

    public function handle(NotificacaoCoordenadorService $servico): int
    {
        $codigos = $this->argument('avaliacao') !== null
            ? [(int) $this->argument('avaliacao')]
            : Avaliacao::whereIn('codigo', DB::table('resultado_resumos')->select('avaliacao_codigo')->distinct())->orderBy('data_avaliacao')->pluck('codigo')->all();

        $total = 0;
        foreach ($codigos as $codigo) {
            $total += $servico->gerarParaAvaliacao((int) $codigo);
        }

        $this->info("{$total} notificação(ões) criada(s) ou atualizada(s) para ".count($codigos).' avaliação(ões).');

        return self::SUCCESS;
    }
}
