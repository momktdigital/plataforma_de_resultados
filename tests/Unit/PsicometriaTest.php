<?php

namespace Tests\Unit;

use App\Support\Psicometria;
use PHPUnit\Framework\TestCase;

class PsicometriaTest extends TestCase
{
    public function test_discriminacao_e_a_diferenca_entre_os_grupos_extremos(): void
    {
        // 90% dos melhores acertaram, 30% dos piores — D = 0,60.
        $this->assertSame(0.6, Psicometria::discriminacao(9, 10, 3, 10));
    }

    public function test_discriminacao_negativa_quando_os_piores_acertam_mais(): void
    {
        // O sinal mais grave: quem foi bem na prova acertou MENOS essa questão.
        $this->assertSame(-0.4, Psicometria::discriminacao(3, 10, 7, 10));
    }

    public function test_discriminacao_e_nula_sem_grupo_para_comparar(): void
    {
        $this->assertNull(Psicometria::discriminacao(0, 0, 3, 10));
        $this->assertNull(Psicometria::discriminacao(9, 10, 0, 0));
    }

    public function test_faixas_de_ebel(): void
    {
        $this->assertSame(Psicometria::FAIXA_OTIMO, Psicometria::classificar(0.55));
        $this->assertSame(Psicometria::FAIXA_OTIMO, Psicometria::classificar(0.40));
        $this->assertSame(Psicometria::FAIXA_BOM, Psicometria::classificar(0.39));
        $this->assertSame(Psicometria::FAIXA_MARGINAL, Psicometria::classificar(0.20));
        $this->assertSame(Psicometria::FAIXA_REVISAR, Psicometria::classificar(0.19));
        $this->assertSame(Psicometria::FAIXA_REVISAR, Psicometria::classificar(-0.3));
        $this->assertSame(Psicometria::FAIXA_REVISAR, Psicometria::classificar(null));
    }

    public function test_kr20_de_uma_prova_consistente(): void
    {
        // 10 itens, Σpq = 2,0, variância das notas = 8,0
        // (10/9) * (1 - 2/8) = 1,1111 * 0,75 = 0,8333
        $this->assertSame(0.8333, Psicometria::kr20(10, 2.0, 8.0));
    }

    public function test_kr20_e_nulo_sem_variancia_entre_respondentes(): void
    {
        // Todo mundo com a mesma nota: o coeficiente é indefinido, não zero.
        $this->assertNull(Psicometria::kr20(10, 2.0, 0.0));
    }

    public function test_kr20_e_nulo_com_menos_de_duas_questoes(): void
    {
        $this->assertNull(Psicometria::kr20(1, 0.2, 4.0));
    }

    public function test_kr20_nunca_passa_dos_limites(): void
    {
        // Σpq maior que a variância produz negativo na fórmula crua.
        $this->assertSame(0.0, Psicometria::kr20(10, 9.0, 2.0));
    }

    public function test_ponto_bisserial_positivo_quando_quem_acerta_vai_melhor(): void
    {
        // (70 - 50) / 10 * sqrt(0,5 * 0,5) = 2 * 0,5 = 1,0 (saturado no teto)
        $this->assertSame(1.0, Psicometria::pontoBisserial(70.0, 50.0, 10.0, 0.5));

        // Caso comum, longe do teto: (62 - 50) / 12 * sqrt(0,6*0,4) = 1 * 0,4899
        $this->assertSame(0.4899, Psicometria::pontoBisserial(62.0, 50.0, 12.0, 0.6));
    }

    public function test_ponto_bisserial_e_nulo_quando_nao_ha_o_que_correlacionar(): void
    {
        $this->assertNull(Psicometria::pontoBisserial(null, 50.0, 10.0, 0.5));
        $this->assertNull(Psicometria::pontoBisserial(70.0, null, 10.0, 0.5));
        $this->assertNull(Psicometria::pontoBisserial(70.0, 50.0, 0.0, 0.5), 'desvio zero');
        $this->assertNull(Psicometria::pontoBisserial(70.0, 50.0, 10.0, 1.0), 'todo mundo acertou');
        $this->assertNull(Psicometria::pontoBisserial(70.0, 50.0, 10.0, 0.0), 'ninguém acertou');
    }

    public function test_variancia_e_desvio_populacionais(): void
    {
        // [2,4,4,4,5,5,7,9]: média 5, variância populacional 4, desvio 2.
        $valores = [2, 4, 4, 4, 5, 5, 7, 9];
        $this->assertSame(4.0, Psicometria::variancia($valores));
        $this->assertSame(2.0, Psicometria::desvioPadrao($valores));
    }

    public function test_variancia_de_lista_pequena_demais_e_zero(): void
    {
        $this->assertSame(0.0, Psicometria::variancia([]));
        $this->assertSame(0.0, Psicometria::variancia([7]));
    }

    public function test_mediana_com_quantidade_par_e_impar(): void
    {
        $this->assertSame(5.0, Psicometria::mediana([9, 1, 5]));
        $this->assertSame(3.5, Psicometria::mediana([1, 2, 5, 9]));
        $this->assertSame(0.0, Psicometria::mediana([]));
    }

    public function test_percentil_interpola_entre_os_vizinhos(): void
    {
        $ordenados = [0, 10, 20, 30, 40];

        $this->assertSame(0.0, Psicometria::percentil($ordenados, 0));
        $this->assertSame(40.0, Psicometria::percentil($ordenados, 1));
        $this->assertSame(20.0, Psicometria::percentil($ordenados, 0.5));
        // posição 0,27*4 = 1,08 → entre 10 e 20, mais perto de 10
        $this->assertSame(10.8, round(Psicometria::percentil($ordenados, 0.27), 4));
    }

    public function test_percentil_de_lista_com_um_elemento_so(): void
    {
        $this->assertSame(7.0, Psicometria::percentil([7], 0.27));
        $this->assertSame(0.0, Psicometria::percentil([], 0.5));
    }
}
