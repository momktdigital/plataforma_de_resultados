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

        $response = $this->actingAs($admin, 'admin')->get('/provas');

        $response->assertOk();
        $response->assertSee('accessibility-container', false);
        $response->assertSee('id="sidebar"', false);
        $response->assertSee(route('categorias.index'), false);
    }
}
