<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Categoria;
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

    public function test_criar_prova_com_categoria_e_data(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulados']);

        $this->actingAs($this->admin(), 'admin')->post('/provas', [
            'categoria_id' => $categoria->id,
            'data_prova' => '15/03/2026',
        ]);

        $prova = Prova::firstOrFail();
        $this->assertSame($categoria->id, $prova->categoria_id);
        $this->assertSame('2026-03-15', $prova->data_prova->format('Y-m-d'));
    }

    public function test_atualiza_categoria_e_data_de_uma_prova_existente(): void
    {
        $prova = Prova::create([]);
        $categoria = Categoria::create(['nome' => 'Institucional']);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->put("/provas/{$prova->codigo}", [
            'categoria_id' => $categoria->id,
            'data_prova' => '01/06/2026',
        ]);

        $prova->refresh();
        $this->assertSame($categoria->id, $prova->categoria_id);
        $this->assertSame('2026-06-01', $prova->data_prova->format('Y-m-d'));
    }

    public function test_categoria_inexistente_e_rejeitada(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')->post('/provas', [
            'categoria_id' => 999,
        ]);

        $response->assertSessionHasErrors('categoria_id');
    }

    public function test_guest_nao_acessa_provas(): void
    {
        $this->admin(); // sistema já instalado, mas o cliente não está autenticado

        $this->get('/provas')->assertRedirect(route('login'));
        $this->post('/provas')->assertRedirect(route('login'));
    }
}
