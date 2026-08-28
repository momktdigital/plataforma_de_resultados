<?php

namespace Tests\Feature;

use App\Models\Configuracao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConfiguracaoCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_valor_nao_bate_no_banco_de_novo_apos_a_primeira_leitura(): void
    {
        Configuracao::definir('site_title', 'Minha Faculdade');

        DB::enableQueryLog();
        Configuracao::valor('site_title');
        Configuracao::valor('site_title');
        Configuracao::valor('outra_chave');
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $consultas, 'Esperava só 1 consulta pra 3 leituras — o resto deveria vir do cache.');
    }

    public function test_definir_invalida_o_cache_imediatamente(): void
    {
        Configuracao::definir('site_title', 'Valor Antigo');
        $this->assertSame('Valor Antigo', Configuracao::valor('site_title'));

        Configuracao::definir('site_title', 'Valor Novo');

        $this->assertSame('Valor Novo', Configuracao::valor('site_title'));
    }
}
