<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Prova;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProvaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    public function test_criar_prova_nao_exige_nenhum_campo(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')->post('/provas', []);

        $this->assertDatabaseCount('provas', 1);

        $prova = Prova::first();
        $response->assertRedirect(route('provas.show', $prova));
        $this->assertNull($prova->nome);
        $this->assertNotNull($prova->codigo);
    }

    public function test_criar_prova_com_nome_e_tipo(): void
    {
        $this->actingAs($this->admin(), 'admin')->post('/provas', [
            'nome' => 'ENADE 2026',
            'tipo' => 'Institucional',
        ]);

        $this->assertDatabaseHas('provas', [
            'nome' => 'ENADE 2026',
            'tipo' => 'Institucional',
        ]);
    }

    public function test_guest_nao_acessa_provas(): void
    {
        $this->get('/provas')->assertRedirect(route('login'));
        $this->post('/provas')->assertRedirect(route('login'));
    }
}
