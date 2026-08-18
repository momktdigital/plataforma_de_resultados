<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Prova;
use App\Models\Questao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestaoManualTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    public function test_cria_questao_manualmente(): void
    {
        $prova = Prova::create([]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post("/provas/{$prova->codigo}/questoes", ['numero' => 5, 'gabarito' => 'c']);

        $response->assertRedirect(route('provas.show', $prova));
        $this->assertDatabaseHas('questoes', ['prova_codigo' => $prova->codigo, 'numero' => 5, 'gabarito' => 'C']);
    }

    public function test_gabarito_em_branco_e_rejeitado(): void
    {
        $prova = Prova::create([]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post("/provas/{$prova->codigo}/questoes", ['numero' => 1, 'gabarito' => '']);

        $response->assertSessionHasErrors('gabarito');
        $this->assertDatabaseCount('questoes', 0);
    }

    public function test_reenviar_mesmo_numero_atualiza_em_vez_de_duplicar(): void
    {
        $prova = Prova::create([]);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post("/provas/{$prova->codigo}/questoes", ['numero' => 1, 'gabarito' => 'A']);
        $this->actingAs($admin, 'admin')->post("/provas/{$prova->codigo}/questoes", ['numero' => 1, 'gabarito' => 'B']);

        $this->assertDatabaseCount('questoes', 1);
        $this->assertDatabaseHas('questoes', ['numero' => 1, 'gabarito' => 'B']);
    }

    public function test_exclui_e_restaura_questao(): void
    {
        $prova = Prova::create([]);
        $questao = Questao::create(['prova_codigo' => $prova->codigo, 'numero' => 1, 'gabarito' => 'A']);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->delete("/provas/{$prova->codigo}/questoes/{$questao->id}")
            ->assertRedirect(route('provas.show', $prova));
        $this->assertSoftDeleted('questoes', ['id' => $questao->id]);

        $this->actingAs($admin, 'admin')->post("/provas/{$prova->codigo}/questoes/{$questao->id}/restaurar")
            ->assertRedirect(route('provas.show', $prova));
        $this->assertNotSoftDeleted('questoes', ['id' => $questao->id]);
    }

    public function test_reenviar_numero_de_questao_excluida_restaura(): void
    {
        $prova = Prova::create([]);
        $questao = Questao::create(['prova_codigo' => $prova->codigo, 'numero' => 1, 'gabarito' => 'A']);
        $questao->delete();

        $this->actingAs($this->admin(), 'admin')
            ->post("/provas/{$prova->codigo}/questoes", ['numero' => 1, 'gabarito' => 'B']);

        $this->assertDatabaseCount('questoes', 1);
        $this->assertNotSoftDeleted('questoes', ['id' => $questao->id]);
        $this->assertDatabaseHas('questoes', ['id' => $questao->id, 'gabarito' => 'B']);
    }
}
