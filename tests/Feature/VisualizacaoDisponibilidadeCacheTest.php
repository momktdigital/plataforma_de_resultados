<?php

namespace Tests\Feature;

use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\ResumoResultadoService;
use App\Services\Visualizacoes\VisualizacaoConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VisualizacaoDisponibilidadeCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_segunda_chamada_a_estado_completo_nao_bate_no_banco_de_novo(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);

        $service = app(VisualizacaoConfigService::class);
        $service->estadoCompleto($avaliacao);

        DB::enableQueryLog();
        $service->estadoCompleto($avaliacao);
        $consultasDaSegundaChamada = count(DB::getQueryLog());
        DB::disableQueryLog();

        // A segunda chamada ainda lê avaliacao_visualizacoes (preferências
        // salvas) — só a parte cara (calcular(), ~17 consultas) deve vir do
        // cache.
        $this->assertLessThan(3, $consultasDaSegundaChamada);
    }

    public function test_recalcular_invalida_o_cache_de_disponibilidade(): void
    {
        $avaliacao = Avaliacao::create([]);

        $service = app(VisualizacaoConfigService::class);
        $semGabarito = $service->estadoCompleto($avaliacao);
        $this->assertFalse($semGabarito['histograma']['disponivel']);

        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $comGabarito = $service->estadoCompleto($avaliacao);
        $this->assertTrue($comGabarito['histograma']['disponivel']);
    }
}
