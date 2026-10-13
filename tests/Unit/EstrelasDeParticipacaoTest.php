<?php

namespace Tests\Unit;

use App\Support\EstrelasDeParticipacao;
use PHPUnit\Framework\TestCase;

class EstrelasDeParticipacaoTest extends TestCase
{
    public function test_todas_as_provas_feitas_sao_cinco_estrelas(): void
    {
        $r = EstrelasDeParticipacao::calcular(4, 4);

        $this->assertSame(5.0, $r['estrelas']);
        $this->assertSame(5, $r['cheias']);
        $this->assertFalse($r['meia']);
        $this->assertSame(0, $r['vazias']);
    }

    public function test_arredonda_para_baixo_em_meias_estrelas(): void
    {
        // 9 de 10 = 90% → 4,5 estrelas; 19 de 20 = 95% → ainda 4,5 (cinco só com 100%)
        $this->assertSame(4.5, EstrelasDeParticipacao::calcular(10, 9)['estrelas']);
        $this->assertSame(4.5, EstrelasDeParticipacao::calcular(20, 19)['estrelas']);
        // 3 de 4 = 75% → 3,5 ; 1 de 2 = 50% → 2,5 ; 1 de 3 = 33% → 1,5
        $this->assertSame(3.5, EstrelasDeParticipacao::calcular(4, 3)['estrelas']);
        $this->assertSame(2.5, EstrelasDeParticipacao::calcular(2, 1)['estrelas']);
        $this->assertSame(1.5, EstrelasDeParticipacao::calcular(3, 1)['estrelas']);
    }

    public function test_meia_estrela_vem_depois_das_cheias_e_as_vazias_completam_cinco(): void
    {
        $r = EstrelasDeParticipacao::calcular(10, 7); // 70% → 3,5

        $this->assertSame(3, $r['cheias']);
        $this->assertTrue($r['meia']);
        $this->assertSame(1, $r['vazias']);
    }

    public function test_sem_nenhuma_participacao_todas_vazias(): void
    {
        $r = EstrelasDeParticipacao::calcular(3, 0);

        $this->assertSame(0.0, $r['estrelas']);
        $this->assertSame(5, $r['vazias']);
    }

    public function test_sem_provas_previstas_nao_ha_estrelas(): void
    {
        $this->assertNull(EstrelasDeParticipacao::calcular(0, 0));
        $this->assertNull(EstrelasDeParticipacao::dosResultados([]));
    }

    public function test_a_partir_dos_resultados_do_boletim_a_falta_nao_conta(): void
    {
        $resultados = [['ausente' => false], ['ausente' => true], ['ausente' => false], ['ausente' => false]];

        $r = EstrelasDeParticipacao::dosResultados($resultados);

        $this->assertSame(4, $r['previstas']);
        $this->assertSame(3, $r['feitas']);
        $this->assertSame(3.5, $r['estrelas']);
    }
}
