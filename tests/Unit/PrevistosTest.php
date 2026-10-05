<?php

namespace Tests\Unit;

use App\Support\Previstos;
use PHPUnit\Framework\TestCase;

class PrevistosTest extends TestCase
{
    public function test_conta_so_os_periodos_em_que_a_avaliacao_foi_aplicada(): void
    {
        $r = Previstos::calcular([3 => 6, 5 => 2], [3 => 5], 0);

        $this->assertSame(6, $r['previstos']);
        $this->assertSame('matricula', $r['fonte']);
        // ativos no 5º período, onde a prova não foi aplicada: não são "ausentes", ficam à parte
        $this->assertSame([5 => 2], $r['semAplicacao']);
    }

    public function test_nunca_fica_abaixo_de_quem_tem_resultado(): void
    {
        // aluno que fez a prova sem estar na matrícula importada não pode gerar participação acima de 100%
        $this->assertSame(10, Previstos::calcular([3 => 6], [3 => 10], 0)['previstos']);
    }

    public function test_sem_matricula_os_previstos_sao_os_resultados(): void
    {
        $r = Previstos::calcular([], [1 => 4, 2 => 3], 2);

        $this->assertSame(9, $r['previstos']);
        $this->assertSame('resultados', $r['fonte']);
        $this->assertSame([], $r['semAplicacao']);
    }

    public function test_matricula_sem_periodo_conhecido_conta_como_prevista(): void
    {
        $this->assertSame(8, Previstos::calcular([0 => 3, 2 => 5], [2 => 4], 0)['previstos']);
    }

    public function test_previstos_por_periodo_respeitam_o_piso_dos_resultados(): void
    {
        $this->assertSame([1 => 5, 2 => 4], Previstos::calcular([1 => 5, 2 => 2], [1 => 3, 2 => 4], 0)['porOrdinal']);
    }

    public function test_rotulo_dos_periodos_agrupa_sequencias(): void
    {
        $this->assertSame('1º–8º', Previstos::rotuloDosPeriodos(range(1, 8)));
        $this->assertSame('1º, 3º–5º', Previstos::rotuloDosPeriodos([5, 1, 3, 4]));
        $this->assertSame('3º, 4º', Previstos::rotuloDosPeriodos([3, 4]));
        $this->assertSame('9º', Previstos::rotuloDosPeriodos([9]));
        $this->assertSame('—', Previstos::rotuloDosPeriodos([]));
    }
}
