<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'admin', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_ADMIN]);
    }

    public function test_paginas_publicas_recebem_os_cabecalhos_basicos(): void
    {
        $this->admin();

        $resposta = $this->get('/portal');

        $resposta->assertOk();
        $resposta->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $resposta->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
        $resposta->assertHeader('X-Content-Type-Options', 'nosniff');
        $resposta->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $resposta->assertHeader('Permissions-Policy');
    }

    public function test_nao_define_csp_de_scripts_que_quebraria_as_telas(): void
    {
        $this->admin();

        $csp = (string) $this->get('/portal')->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString('script-src', $csp);
        $this->assertStringNotContainsString('default-src', $csp);
    }

    public function test_hsts_so_em_https(): void
    {
        $this->admin();

        $this->get('/portal')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/portal')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_area_logada_nao_fica_em_cache(): void
    {
        $resposta = $this->actingAs($this->admin(), 'admin')->get('/avaliacoes');

        $resposta->assertOk();
        $this->assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));
    }

    public function test_pagina_publica_nao_recebe_no_store(): void
    {
        $this->admin();

        $this->assertStringNotContainsString('no-store', (string) $this->get('/portal')->headers->get('Cache-Control'));
    }

    public function test_resultados_do_portal_nao_ficam_em_cache(): void
    {
        $this->admin();

        // Sem sessão de aluno redireciona, mas o redirecionamento também não deve ser guardado.
        $resposta = $this->get('/portal/resultados');

        $this->assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));
    }

    public function test_pagina_de_redefinicao_de_senha_nao_vaza_referer(): void
    {
        $this->admin();

        $this->get('/redefinir-senha/qualquer-token')->assertHeader('Referrer-Policy', 'no-referrer');
    }
}
