<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Avaliacao;
use App\Models\Categoria;
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

    public function test_exclui_categoria_com_avaliacao_movendo_para_outra_categoria(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulados']);
        $destino = Categoria::create(['nome' => 'Provas oficiais']);
        $avaliacao = Avaliacao::create(['categoria_id' => $categoria->id]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->delete("/categorias/{$categoria->id}", ['mover_avaliacoes_para' => $destino->id]);

        $response->assertRedirect(route('categorias.index'));
        $this->assertDatabaseMissing('categorias', ['id' => $categoria->id]);
        $this->assertSame($destino->id, $avaliacao->fresh()->categoria_id);
    }

    public function test_exclui_categoria_com_avaliacao_deixando_sem_categoria(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulados']);
        $avaliacao = Avaliacao::create(['categoria_id' => $categoria->id]);

        $response = $this->actingAs($this->admin(), 'admin')->delete("/categorias/{$categoria->id}");

        $response->assertRedirect(route('categorias.index'));
        $this->assertDatabaseMissing('categorias', ['id' => $categoria->id]);
        $this->assertNull($avaliacao->fresh()->categoria_id);
    }

    public function test_edita_nome_e_categoria_mae(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulados errado']);
        $novoPai = Categoria::create(['nome' => 'Raiz']);

        $response = $this->actingAs($this->admin(), 'admin')->put("/categorias/{$categoria->id}", [
            'nome' => 'Simulados',
            'categoria_pai_id' => $novoPai->id,
        ]);

        $response->assertRedirect(route('categorias.index'));
        $this->assertDatabaseHas('categorias', ['id' => $categoria->id, 'nome' => 'Simulados', 'categoria_pai_id' => $novoPai->id]);
    }

    public function test_nao_permite_ser_mae_de_si_mesma(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulados']);

        $response = $this->actingAs($this->admin(), 'admin')->put("/categorias/{$categoria->id}", [
            'nome' => 'Simulados',
            'categoria_pai_id' => $categoria->id,
        ]);

        $response->assertSessionHasErrors('categoria_pai_id');
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
