<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Avaliacao;
use App\Models\Categoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvaliacaoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    public function test_criar_prova_nao_exige_nenhum_campo(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')->post('/avaliacoes', []);

        $this->assertDatabaseCount('avaliacoes', 1);

        $avaliacao = Avaliacao::first();
        $response->assertRedirect(route('avaliacoes.show', $avaliacao));
        $this->assertNull($avaliacao->nome);
        $this->assertNotNull($avaliacao->codigo);
    }

    public function test_criar_prova_com_nome_e_tipo(): void
    {
        $this->actingAs($this->admin(), 'admin')->post('/avaliacoes', [
            'nome' => 'ENADE 2026',
            'tipo' => 'Institucional',
        ]);

        $this->assertDatabaseHas('avaliacoes', [
            'nome' => 'ENADE 2026',
            'tipo' => 'Institucional',
        ]);
    }

    public function test_criar_prova_com_categoria_e_data(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulados']);

        $this->actingAs($this->admin(), 'admin')->post('/avaliacoes', [
            'categoria_id' => $categoria->id,
            'data_avaliacao' => '15/03/2026',
        ]);

        $avaliacao = Avaliacao::firstOrFail();
        $this->assertSame($categoria->id, $avaliacao->categoria_id);
        $this->assertSame('2026-03-15', $avaliacao->data_avaliacao->format('Y-m-d'));
    }

    public function test_atualiza_categoria_e_data_de_uma_prova_existente(): void
    {
        $avaliacao = Avaliacao::create([]);
        $categoria = Categoria::create(['nome' => 'Institucional']);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->put("/avaliacoes/{$avaliacao->codigo}", [
            'categoria_id' => $categoria->id,
            'data_avaliacao' => '01/06/2026',
        ]);

        $avaliacao->refresh();
        $this->assertSame($categoria->id, $avaliacao->categoria_id);
        $this->assertSame('2026-06-01', $avaliacao->data_avaliacao->format('Y-m-d'));
    }

    public function test_categoria_inexistente_e_rejeitada(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')->post('/avaliacoes', [
            'categoria_id' => 999,
        ]);

        $response->assertSessionHasErrors('categoria_id');
    }

    public function test_busca_por_nome(): void
    {
        Avaliacao::create(['nome' => 'ENADE 2026']);
        Avaliacao::create(['nome' => 'Simulado Interno']);

        $response = $this->actingAs($this->admin(), 'admin')->get('/avaliacoes?search=ENADE');

        $response->assertOk();
        $response->assertSee('ENADE 2026');
        $response->assertDontSee('Simulado Interno');
    }

    public function test_busca_por_tipo(): void
    {
        Avaliacao::create(['nome' => 'Prova 1', 'tipo' => 'Institucional']);
        Avaliacao::create(['nome' => 'Prova 2', 'tipo' => 'Simulado']);

        $response = $this->actingAs($this->admin(), 'admin')->get('/avaliacoes?search=Institucional');

        $response->assertSee('Prova 1');
        $response->assertDontSee('Prova 2');
    }

    public function test_busca_por_codigo(): void
    {
        $avaliacao = Avaliacao::create(['nome' => 'Prova Alvo']);
        Avaliacao::create(['nome' => 'Outra prova']);

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes?search={$avaliacao->codigo}");

        $response->assertSee('Prova Alvo');
        $response->assertDontSee('Outra prova');
    }

    public function test_guest_nao_acessa_provas(): void
    {
        $this->admin(); // sistema já instalado, mas o cliente não está autenticado

        $this->get('/avaliacoes')->assertRedirect(route('login'));
        $this->post('/avaliacoes')->assertRedirect(route('login'));
    }
}
