<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Categoria;
use App\Models\Prova;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoriaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    public function test_cria_categoria_raiz(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')->post('/categorias', ['nome' => 'Simulados']);

        $response->assertRedirect(route('categorias.index'));
        $this->assertDatabaseHas('categorias', ['nome' => 'Simulados', 'categoria_pai_id' => null]);
    }

    public function test_cria_subcategoria(): void
    {
        $pai = Categoria::create(['nome' => 'Simulados']);

        $this->actingAs($this->admin(), 'admin')->post('/categorias', [
            'nome' => '1º ao 4º período',
            'categoria_pai_id' => $pai->id,
        ]);

        $this->assertDatabaseHas('categorias', ['nome' => '1º ao 4º período', 'categoria_pai_id' => $pai->id]);
    }

    public function test_categoria_pai_inexistente_e_rejeitada(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')->post('/categorias', [
            'nome' => 'X',
            'categoria_pai_id' => 999,
        ]);

        $response->assertSessionHasErrors('categoria_pai_id');
    }

    public function test_lista_arvore_de_categorias(): void
    {
        $pai = Categoria::create(['nome' => 'Simulados']);
        Categoria::create(['nome' => '1º ao 4º período', 'categoria_pai_id' => $pai->id]);

        $response = $this->actingAs($this->admin(), 'admin')->get('/categorias');

        $response->assertOk();
        $response->assertSee('Simulados');
        $response->assertSee('1º ao 4º período');
    }

    public function test_nao_exclui_categoria_com_subcategoria(): void
    {
        $pai = Categoria::create(['nome' => 'Simulados']);
        Categoria::create(['nome' => 'Filha', 'categoria_pai_id' => $pai->id]);

        $response = $this->actingAs($this->admin(), 'admin')->delete("/categorias/{$pai->id}");

        $response->assertSessionHasErrors('categoria');
        $this->assertDatabaseHas('categorias', ['id' => $pai->id]);
    }

    public function test_nao_exclui_categoria_com_prova_vinculada(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulados']);
        Prova::create(['categoria_id' => $categoria->id]);

        $response = $this->actingAs($this->admin(), 'admin')->delete("/categorias/{$categoria->id}");

        $response->assertSessionHasErrors('categoria');
        $this->assertDatabaseHas('categorias', ['id' => $categoria->id]);
    }

    public function test_exclui_categoria_sem_filhos_e_sem_provas(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulados']);

        $response = $this->actingAs($this->admin(), 'admin')->delete("/categorias/{$categoria->id}");

        $response->assertRedirect(route('categorias.index'));
        $this->assertDatabaseMissing('categorias', ['id' => $categoria->id]);
    }

    public function test_guest_nao_acessa_categorias(): void
    {
        $this->admin();

        $this->get('/categorias')->assertRedirect(route('login'));
    }
}
