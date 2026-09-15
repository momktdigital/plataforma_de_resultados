<?php

namespace Tests\Feature;

use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\PsicometriaService;
use App\Support\Psicometria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PsicometriaServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cenário base, montado para que cada número seja verificável na mão:
     * 10 respondentes, 4 questões (gabarito "A" em todas).
     *
     * - 5 "fortes" acertam Q1, Q2 e Q3 (escore 3)
     * - 5 "fracos" acertam só Q3 (escore 1)
     *
     * Logo: Q1 e Q2 discriminam perfeitamente (D = 1,0), Q3 todo mundo acerta
     * (D = 0) e Q4 ninguém acerta (D = 0).
     */
    private function cenario(): Avaliacao
    {
        $avaliacao = Avaliacao::create([]);

        foreach ([1, 2, 3, 4] as $numero) {
            Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => $numero, 'gabarito' => 'A']);
        }

        for ($i = 1; $i <= 10; $i++) {
            $forte = $i <= 5;
            $respostas = [
                1 => $forte ? 'A' : 'B',
                2 => $forte ? 'A' : 'B',
                3 => 'A',
                4 => 'B',
            ];

            foreach ($respostas as $numero => $resposta) {
                Resposta::create([
                    'avaliacao_codigo' => $avaliacao->codigo,
                    'ra' => '90'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                    'periodo' => '',
                    'questao_numero' => $numero,
                    'resposta' => $resposta,
                ]);
            }
        }

        return $avaliacao;
    }

    public function test_calcula_dificuldade_e_discriminacao_por_questao(): void
    {
        $analise = app(PsicometriaService::class)->analisar($this->cenario());

        $this->assertNotNull($analise);
        $this->assertSame(10, $analise['respondentes']);
        $this->assertSame(4, $analise['questoes']);

        $itens = collect($analise['itens'])->keyBy('numero');

        $this->assertSame(50.0, $itens[1]['dificuldade']);
        $this->assertSame(1.0, $itens[1]['discriminacao']);
        $this->assertSame(Psicometria::FAIXA_OTIMO, $itens[1]['faixa']);

        $this->assertSame(100.0, $itens[3]['dificuldade'], 'todo mundo acertou a Q3');
        $this->assertSame(0.0, $itens[3]['discriminacao']);
        $this->assertSame(Psicometria::FAIXA_REVISAR, $itens[3]['faixa']);

        $this->assertSame(0.0, $itens[4]['dificuldade'], 'ninguém acertou a Q4');
        $this->assertSame(Psicometria::FAIXA_REVISAR, $itens[4]['faixa']);
    }

    public function test_calcula_media_mediana_desvio_e_kr20_da_prova(): void
    {
        $analise = app(PsicometriaService::class)->analisar($this->cenario());

        // Escores 3 e 1 sobre 4 questões → 75% e 25%.
        $this->assertSame(50.0, $analise['media']);
        $this->assertSame(50.0, $analise['mediana']);
        $this->assertSame(25.0, $analise['desvio']);

        // k=4, Σpq = 0,25+0,25+0+0 = 0,5, variância dos escores = 1,0
        // (4/3) * (1 - 0,5/1,0) = 0,6667
        $this->assertSame(0.6667, $analise['kr20']);
    }

    public function test_simulacao_mostra_o_ganho_de_remover_os_itens_fracos(): void
    {
        $analise = app(PsicometriaService::class)->analisar($this->cenario());

        $this->assertNotNull($analise['simulacao']);
        $this->assertSame([3, 4], $analise['simulacao']['removidos']);

        // Sem Q3 e Q4: k=2, Σpq = 0,5, variância = 1,0 → (2/1)*(1-0,5) = 1,0
        $this->assertSame(1.0, $analise['simulacao']['kr20']);
        $this->assertEqualsWithDelta(0.3333, $analise['simulacao']['ganho'], 0.0001);
    }

    public function test_curva_caracteristica_sobe_num_item_que_discrimina(): void
    {
        $avaliacao = $this->cenario();
        $curva = app(PsicometriaService::class)->curvaCaracteristica($avaliacao, 1);

        $this->assertNotEmpty($curva);

        $primeiro = $curva[0]['percentual'];
        $ultimo = $curva[count($curva) - 1]['percentual'];
        $this->assertGreaterThan($primeiro, $ultimo, 'quem foi melhor na prova deveria acertar mais a Q1');
    }

    public function test_questao_anulada_fica_fora_da_analise(): void
    {
        $avaliacao = $this->cenario();
        Questao::where('avaliacao_codigo', $avaliacao->codigo)
            ->where('numero', 1)
            ->update(['anulada_modo' => 'dar_ponto']);

        $analise = app(PsicometriaService::class)->analisar($avaliacao);

        $numeros = array_column($analise['itens'], 'numero');
        $this->assertNotContains(1, $numeros, 'uma questão anulada não tem dificuldade observada real');
        $this->assertSame(3, $analise['questoes']);
    }

    public function test_questao_com_distribuir_pontuacao_tambem_fica_fora(): void
    {
        $avaliacao = $this->cenario();
        Questao::where('avaliacao_codigo', $avaliacao->codigo)
            ->where('numero', 2)
            ->update(['anulada_modo' => 'distribuir_pontuacao']);

        $analise = app(PsicometriaService::class)->analisar($avaliacao);

        $this->assertNotContains(2, array_column($analise['itens'], 'numero'));
    }

    public function test_questao_excluida_nao_entra(): void
    {
        $avaliacao = $this->cenario();
        Questao::where('avaliacao_codigo', $avaliacao->codigo)->where('numero', 4)->delete();

        $analise = app(PsicometriaService::class)->analisar($avaliacao);

        $this->assertNotContains(4, array_column($analise['itens'], 'numero'));
    }

    public function test_ponto_bisserial_acompanha_a_discriminacao(): void
    {
        $analise = app(PsicometriaService::class)->analisar($this->cenario());
        $itens = collect($analise['itens'])->keyBy('numero');

        $this->assertGreaterThan(0.5, $itens[1]['pontoBisserial'], 'Q1 separa perfeitamente os dois grupos');
        // Q3/Q4: todo mundo acertou ou todo mundo errou — não há o que correlacionar.
        $this->assertNull($itens[3]['pontoBisserial']);
        $this->assertNull($itens[4]['pontoBisserial']);
    }

    public function test_avaliacao_com_poucos_respondentes_nao_produz_analise(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);

        for ($i = 1; $i <= 4; $i++) {
            Resposta::create([
                'avaliacao_codigo' => $avaliacao->codigo,
                'ra' => '80'.$i,
                'periodo' => '',
                'questao_numero' => 1,
                'resposta' => 'A',
            ]);
        }

        // Com 4 respondentes os cortes de 27% viram grupos de 1 pessoa — o D
        // não significaria nada, então a análise inteira é suprimida.
        $this->assertNull(app(PsicometriaService::class)->analisar($avaliacao));
    }

    public function test_analise_respeita_o_filtro_de_periodo(): void
    {
        $avaliacao = $this->cenario();

        // Mesmos respondentes num segundo período, todos acertando tudo.
        for ($i = 1; $i <= 10; $i++) {
            foreach ([1, 2, 3, 4] as $numero) {
                Resposta::create([
                    'avaliacao_codigo' => $avaliacao->codigo,
                    'ra' => '90'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                    'periodo' => '2026/2',
                    'questao_numero' => $numero,
                    'resposta' => 'A',
                ]);
            }
        }

        $segundo = app(PsicometriaService::class)->analisar($avaliacao, '2026/2');
        $itens = collect($segundo['itens'])->keyBy('numero');

        $this->assertSame(10, $segundo['respondentes']);
        $this->assertSame(100.0, $itens[4]['dificuldade'], 'no 2026/2 todo mundo acertou a Q4');
    }
}
