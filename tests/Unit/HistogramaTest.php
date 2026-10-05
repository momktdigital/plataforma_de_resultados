<?php

namespace Tests\Unit;

use App\Support\Histograma;
use PHPUnit\Framework\TestCase;

class HistogramaTest extends TestCase
{
    /** @param array<int, float> $valores */
    private function de(array $valores): array
    {
        $h = [];
        foreach ($valores as $v) {
            Histograma::adicionar($h, $v);
        }

        return $h;
    }

    public function test_media_mediana_e_quartis_batem_com_o_calculo_direto(): void
    {
        $h = $this->de([10.0, 20.0, 30.0, 40.0, 100.0]);

        $this->assertSame(5, Histograma::n($h));
        $this->assertEqualsWithDelta(40.0, Histograma::media($h), 0.001);
        $this->assertEqualsWithDelta(30.0, Histograma::quantil($h, 0.5), 0.001);
        $this->assertEqualsWithDelta(20.0, Histograma::quantil($h, 0.25), 0.001);
        $this->assertEqualsWithDelta(40.0, Histograma::quantil($h, 0.75), 0.001);
        $this->assertEqualsWithDelta(10.0, Histograma::minimo($h), 0.001);
        $this->assertEqualsWithDelta(100.0, Histograma::maximo($h), 0.001);
    }

    public function test_mediana_de_quantidade_par_interpola_os_dois_do_meio(): void
    {
        $this->assertEqualsWithDelta(65.0, Histograma::quantil($this->de([30.0, 60.0, 70.0, 100.0]), 0.5), 0.001);
    }

    public function test_valores_repetidos_contam_cada_ocorrencia(): void
    {
        $h = $this->de([50.0, 50.0, 50.0, 80.0]);

        $this->assertEqualsWithDelta(50.0, Histograma::quantil($h, 0.5), 0.001);
        $this->assertEqualsWithDelta(57.5, Histograma::media($h), 0.001);
    }

    public function test_corte_inclui_o_proprio_valor_e_nao_arredonda_para_cima(): void
    {
        $h = $this->de([59.9, 60.0, 60.1, 45.5]);

        // 59,9% NÃO é proficiente: um histograma de faixas de 1 ponto classificaria errado.
        $this->assertSame(2, Histograma::contarAcima($h, 60.0));
        $this->assertSame(3, Histograma::contarAcima($h, 59.9));
    }

    public function test_faixas_sao_fechadas_embaixo_e_abertas_em_cima(): void
    {
        $h = $this->de([39.9, 40.0, 49.9, 50.0, 70.0, 100.0]);

        $this->assertSame(1, Histograma::contarEntre($h, 0.0, 40.0));
        $this->assertSame(2, Histograma::contarEntre($h, 40.0, 50.0));
        $this->assertSame(2, Histograma::contarEntre($h, 70.0, null));
    }

    public function test_baldes_de_5_pontos_com_o_cem_no_ultimo(): void
    {
        $baldes = Histograma::baldes($this->de([0.0, 4.9, 5.0, 59.9, 60.0, 100.0]), 5);

        $this->assertCount(20, $baldes);
        $this->assertSame(2, $baldes[0]);
        $this->assertSame(1, $baldes[1]);
        $this->assertSame(1, $baldes[11]);
        $this->assertSame(1, $baldes[12]);
        $this->assertSame(1, $baldes[19]);
        $this->assertSame(6, array_sum($baldes));
    }

    public function test_histograma_vazio_nao_quebra(): void
    {
        $this->assertNull(Histograma::media([]));
        $this->assertNull(Histograma::quantil([], 0.5));
        $this->assertNull(Histograma::minimo([]));
        $this->assertSame(0, Histograma::contarAcima([], 60.0));
    }

    public function test_mesclar_soma_as_quantidades(): void
    {
        $this->assertSame([300 => 3, 600 => 1], Histograma::mesclar([300 => 2], [300 => 1, 600 => 1]));
    }
}
