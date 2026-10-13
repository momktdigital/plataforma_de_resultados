<?php

namespace Tests\Unit;

use App\Support\PeriodoCurso;
use PHPUnit\Framework\TestCase;

/**
 * A meta do aluno é 60% do que cabe no período dele — não 100%.
 */
class PeriodoCursoMetaTest extends TestCase
{
    public function test_prova_de_100_questoes_com_10_ao_alcance_do_1o_periodo_tem_meta_de_6_questoes(): void
    {
        // 10 questões ao alcance de 100: a meta NÃO é as 10 (10% da prova), são 6 (6% da prova).
        $this->assertSame(6.0, PeriodoCurso::metaDoPeriodo(10, 100));
    }

    public function test_tudo_ao_alcance_volta_aos_60_por_cento_de_sempre(): void
    {
        $this->assertSame(60.0, PeriodoCurso::metaDoPeriodo(100, 100));
        $this->assertSame(60.0, PeriodoCurso::metaDoPeriodo(7, 7));
    }

    public function test_meta_cresce_com_o_que_cabe_no_periodo(): void
    {
        $this->assertSame(30.0, PeriodoCurso::metaDoPeriodo(5, 10));
        $this->assertSame(45.0, PeriodoCurso::metaDoPeriodo(3, 4));
        $this->assertSame(0.0, PeriodoCurso::metaDoPeriodo(0, 10));
    }

    public function test_prova_sem_questoes_nao_quebra(): void
    {
        $this->assertSame(0.0, PeriodoCurso::metaDoPeriodo(0, 0));
    }
}
