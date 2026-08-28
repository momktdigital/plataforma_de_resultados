<?php

namespace Tests\Unit;

use App\Support\Ordenacao;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class OrdenacaoTest extends TestCase
{
    public function test_usa_coluna_e_direcao_padrao_sem_query_string(): void
    {
        [$coluna, $direcao] = Ordenacao::resolver(Request::create('/x'), ['nome', 'ra'], 'nome');

        $this->assertSame('nome', $coluna);
        $this->assertSame('asc', $direcao);
    }

    public function test_respeita_direcao_padrao_customizada(): void
    {
        [$coluna, $direcao] = Ordenacao::resolver(Request::create('/x'), ['codigo'], 'codigo', 'desc');

        $this->assertSame('codigo', $coluna);
        $this->assertSame('desc', $direcao);
    }

    public function test_usa_coluna_pedida_na_query_quando_permitida(): void
    {
        [$coluna, $direcao] = Ordenacao::resolver(
            Request::create('/x?sort=cpf&direction=desc'),
            ['nome', 'cpf'],
            'nome'
        );

        $this->assertSame('cpf', $coluna);
        $this->assertSame('desc', $direcao);
    }

    public function test_ignora_coluna_nao_permitida_e_cai_pro_padrao(): void
    {
        [$coluna, $direcao] = Ordenacao::resolver(
            Request::create('/x?sort=id&direction=desc'),
            ['nome', 'cpf'],
            'nome',
            'asc'
        );

        $this->assertSame('nome', $coluna);
        $this->assertSame('asc', $direcao);
    }

    public function test_direction_sem_valor_desc_explicito_vira_asc(): void
    {
        [, $direcao] = Ordenacao::resolver(Request::create('/x?sort=nome&direction=qualquer-coisa'), ['nome'], 'nome');

        $this->assertSame('asc', $direcao);
    }
}
