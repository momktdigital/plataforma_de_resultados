<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AparenciaTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_publico_carrega_acessibilidade_e_icones(): void
    {
        Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);

        $response = $this->get('/portal');

        $response->assertOk();
        $response->assertSee('accessibility-container', false);
        $response->assertSee('assets/js/accessibility.js', false);
        $response->assertSee('sienna-accessibility', false);
        $response->assertSee('@phosphor-icons/web', false);
    }

    public function test_login_carrega_acessibilidade_e_icones(): void
    {
        Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('accessibility-container', false);
        $response->assertSee('assets/js/accessibility.js', false);
    }

    public function test_painel_admin_carrega_acessibilidade_e_sidebar(): void
    {
        $admin = Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);

        $response = $this->actingAs($admin, 'admin')->get('/avaliacoes');

        $response->assertOk();
        $response->assertSee('accessibility-container', false);
        $response->assertSee('id="sidebar"', false);
        $response->assertSee(route('categorias.index'), false);
    }

    public function test_barra_de_acessibilidade_oculta_fonte_e_expoe_gatilho_do_sienna(): void
    {
        $js = file_get_contents(public_path('assets/js/accessibility.js'));

        $this->assertStringNotContainsString('btn-acc-increase', $js);
        $this->assertStringNotContainsString('btn-acc-decrease', $js);
        $this->assertStringContainsString('btn-acc-sienna', $js);
        $this->assertStringContainsString(".querySelector('.asw-menu-btn')", $js);

        $css = file_get_contents(public_path('assets/css/accessibility.css'));
        $this->assertStringContainsString('.asw-widget', $css);
    }

    public function test_gatilho_do_vlibras_usa_a_api_atual_do_widget(): void
    {
        $js = file_get_contents(public_path('assets/js/accessibility.js'));

        // v7.6.0 do plugin não usa mais `[vw-access-button]` como seletor —
        // expõe `window.VLibrasWidget.open()` pra abrir o tradutor.
        $this->assertStringNotContainsString("querySelector('[vw-access-button]')", $js);
        $this->assertStringContainsString('VLibrasWidget.open()', $js);

        $css = file_get_contents(public_path('assets/css/accessibility.css'));
        $this->assertStringContainsString('#vlibras-access-wrapper', $css);
        // A regra de CSS em si (não o comentário explicativo acima dela) não
        // deve mais usar o seletor antigo, que não existe no DOM da v7.6.0.
        $this->assertStringNotContainsString("\n[vw-access-button] {", $css);
    }
}
