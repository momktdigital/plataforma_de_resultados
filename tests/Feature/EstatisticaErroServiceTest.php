<?php

namespace Tests\Feature;

use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\EstatisticaErroService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EstatisticaErroServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_calcula_acertos_erros_e_em_branco_por_questao(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'B']);

        // Questão 1: 2 acertos, 1 erro, 1 em branco.
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '3', 'questao_numero' => 1, 'resposta' => 'C']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '4', 'questao_numero' => 1, 'resposta' => '']);

        // Questão 2: 100% de acerto — não deve aparecer no resultado (taxa_erro > 0 é filtro).
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 2, 'resposta' => 'B']);

        $stats = (new EstatisticaErroService)->calcular($avaliacao);

        $this->assertCount(1, $stats);
        $this->assertSame(1, $stats[0]['numero']);
        $this->assertSame(2, $stats[0]['acertos']);
        $this->assertSame(1, $stats[0]['erros']);
        $this->assertSame(1, $stats[0]['em_branco']);
        // taxa de erro é sobre RESPONDIDAS (acertos+erros=3), não sobre o total de linhas (4).
        $this->assertEqualsWithDelta(33.3, $stats[0]['taxa_erro'], 0.1);
    }

    public function test_ignora_respostas_de_outra_prova_e_questoes_sem_gabarito(): void
    {
        $avaliacao = Avaliacao::create([]);
        $outraAvaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Questao::create(['avaliacao_codigo' => $outraAvaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);

        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'B']);
        Resposta::create(['avaliacao_codigo' => $outraAvaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'C']);

        $stats = (new EstatisticaErroService)->calcular($avaliacao);

        $this->assertCount(1, $stats);
        $this->assertSame(1, $stats[0]['erros']);
    }

    public function test_desempenho_com_grande_volume_de_respostas(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);

        $lote = [];
        for ($i = 0; $i < 5000; $i++) {
            $lote[] = [
                'avaliacao_codigo' => $avaliacao->codigo,
                'ra' => (string) $i,
                'periodo' => '',
                'questao_numero' => 1,
                'resposta' => $i % 2 === 0 ? 'A' : 'B',
            ];
        }
        Resposta::insert($lote);

        $inicio = microtime(true);
        $stats = (new EstatisticaErroService)->calcular($avaliacao);
        $duracao = microtime(true) - $inicio;

        $this->assertSame(2500, $stats[0]['acertos']);
        $this->assertSame(2500, $stats[0]['erros']);
        $this->assertLessThan(5.0, $duracao, 'Cálculo deve ser feito em SQL agregado, não linha a linha em PHP.');
    }
}
