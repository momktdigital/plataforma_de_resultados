<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Prova;
use App\Models\Questao;
use App\Models\Resposta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LixeiraTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    public function test_lista_provas_e_questoes_excluidas(): void
    {
        $provaAtiva = Prova::create(['nome' => 'Ativa']);
        $questao = Questao::create(['prova_codigo' => $provaAtiva->codigo, 'numero' => 1, 'gabarito' => 'A']);
        $questao->delete();

        $provaExcluida = Prova::create(['nome' => 'Excluida']);
        $provaExcluida->delete();

        $response = $this->actingAs($this->admin(), 'admin')->get('/lixeira');

        $response->assertOk();
        $response->assertSee('Excluida');
        $response->assertSee('Q1');
    }

    public function test_restaura_prova_e_cascata_de_filhos(): void
    {
        $prova = Prova::create([]);
        $questao = Questao::create(['prova_codigo' => $prova->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['prova_codigo' => $prova->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);

        $prova->questoes()->delete();
        $prova->resultados()->delete();
        $prova->delete();

        $response = $this->actingAs($this->admin(), 'admin')->post("/lixeira/provas/{$prova->codigo}/restaurar");

        $response->assertRedirect();
        $this->assertNotSoftDeleted('provas', ['codigo' => $prova->codigo]);
        $this->assertNotSoftDeleted('questoes', ['id' => $questao->id]);
        $this->assertNotSoftDeleted('respostas', ['prova_codigo' => $prova->codigo]);
    }

    public function test_exclui_prova_definitivamente_remove_filhos_via_fk_cascade(): void
    {
        $prova = Prova::create([]);
        Questao::create(['prova_codigo' => $prova->codigo, 'numero' => 1, 'gabarito' => 'A']);
        $prova->delete();

        $response = $this->actingAs($this->admin(), 'admin')->delete("/lixeira/provas/{$prova->codigo}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('provas', ['codigo' => $prova->codigo]);
        $this->assertDatabaseMissing('questoes', ['prova_codigo' => $prova->codigo]);
    }

    public function test_restaura_e_exclui_questao_definitivamente(): void
    {
        $prova = Prova::create([]);
        $questao = Questao::create(['prova_codigo' => $prova->codigo, 'numero' => 1, 'gabarito' => 'A']);
        $questao->delete();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post("/lixeira/questoes/{$questao->id}/restaurar")->assertRedirect();
        $this->assertNotSoftDeleted('questoes', ['id' => $questao->id]);

        $questao->delete();
        $this->actingAs($admin, 'admin')->delete("/lixeira/questoes/{$questao->id}")->assertRedirect();
        $this->assertDatabaseMissing('questoes', ['id' => $questao->id]);
    }

    public function test_guest_nao_acessa_lixeira(): void
    {
        $this->admin();

        $this->get('/lixeira')->assertRedirect(route('login'));
    }
}
