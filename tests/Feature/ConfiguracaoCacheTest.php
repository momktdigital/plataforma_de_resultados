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

    public function test_chaves_sensiveis_ficam_criptografadas_no_banco(): void
    {
        Configuracao::definir('smtp_pass', 'senha-secreta');
        Configuracao::definir('recaptcha_secret_key', 'chave-recaptcha');
        Configuracao::definir('hcaptcha_secret_key', 'chave-hcaptcha');

        $this->assertNotSame('senha-secreta', DB::table('configuracoes')->where('chave', 'smtp_pass')->value('valor'));
        $this->assertNotSame('chave-recaptcha', DB::table('configuracoes')->where('chave', 'recaptcha_secret_key')->value('valor'));
        $this->assertNotSame('chave-hcaptcha', DB::table('configuracoes')->where('chave', 'hcaptcha_secret_key')->value('valor'));

        $this->assertSame('senha-secreta', Configuracao::valor('smtp_pass'));
        $this->assertSame('chave-recaptcha', Configuracao::valor('recaptcha_secret_key'));
        $this->assertSame('chave-hcaptcha', Configuracao::valor('hcaptcha_secret_key'));
    }

    public function test_chave_nao_sensivel_continua_em_texto_puro_no_banco(): void
    {
        Configuracao::definir('site_title', 'Minha Faculdade');

        $this->assertSame('Minha Faculdade', DB::table('configuracoes')->where('chave', 'site_title')->value('valor'));
    }

    public function test_valor_sensivel_gravado_antes_da_criptografia_continua_legivel(): void
    {
        // Simula uma linha gravada por uma versão anterior do sistema, direto
        // no banco (sem passar por definir()) — ainda em texto puro. A
        // migração já semeia 'smtp_pass' com valor vazio, então é um update.
        DB::table('configuracoes')->updateOrInsert(['chave' => 'smtp_pass'], ['valor' => 'senha-antiga-em-texto-puro']);

        $this->assertSame('senha-antiga-em-texto-puro', Configuracao::valor('smtp_pass'));
    }
}
