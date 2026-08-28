<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AgendamentoTest extends TestCase
{
    public function test_backup_e_verificacao_de_atualizacao_estao_agendados(): void
    {
        // `Artisan::starting()` (que registra o closure de withSchedule() em
        // bootstrap/app.php) só dispara quando o console realmente inicializa
        // — por isso o comando é executado de verdade em vez de inspecionar
        // Schedule::class diretamente, que fica vazio num teste HTTP normal.
        Artisan::call('schedule:list');

        $saida = Artisan::output();

        $this->assertStringContainsString('sistema:backup', $saida);
        $this->assertStringContainsString('sistema:atualizar --check', $saida);
    }
}
