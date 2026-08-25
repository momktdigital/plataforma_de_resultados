<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RootRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitante_deslogado_vai_para_o_portal_publico(): void
    {
        Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);

        $this->get('/')->assertRedirect(route('portal.consulta'));
    }

    public function test_admin_logado_vai_para_provas(): void
    {
        $admin = Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);

        $this->actingAs($admin, 'admin')->get('/')->assertRedirect(route('avaliacoes.index'));
    }

    public function test_portal_publico_tem_link_discreto_para_login_administrativo(): void
    {
        Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);

        $response = $this->get('/portal');

        $response->assertOk();
        $response->assertSee(route('login'), false);
    }
}
