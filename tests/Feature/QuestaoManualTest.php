<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\ResumoResultadoService;
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
        $avaliacao = Avaliacao::create([]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/questoes", ['numero' => 5, 'gabarito' => 'c']);

        $response->assertRedirect(route('avaliacoes.show', $avaliacao));
        $this->assertDatabaseHas('questoes', ['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 5, 'gabarito' => 'C']);
    }

    public function test_gabarito_em_branco_e_rejeitado(): void
    {
        $avaliacao = Avaliacao::create([]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/questoes", ['numero' => 1, 'gabarito' => '']);

        $response->assertSessionHasErrors('gabarito');
        $this->assertDatabaseCount('questoes', 0);
    }

    public function test_reenviar_mesmo_numero_atualiza_em_vez_de_duplicar(): void
    {
        $avaliacao = Avaliacao::create([]);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post("/avaliacoes/{$avaliacao->codigo}/questoes", ['numero' => 1, 'gabarito' => 'A']);
        $this->actingAs($admin, 'admin')->post("/avaliacoes/{$avaliacao->codigo}/questoes", ['numero' => 1, 'gabarito' => 'B']);

        $this->assertDatabaseCount('questoes', 1);
        $this->assertDatabaseHas('questoes', ['numero' => 1, 'gabarito' => 'B']);
    }

    public function test_exclui_e_restaura_questao(): void
    {
        $avaliacao = Avaliacao::create([]);
        $questao = Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->delete("/avaliacoes/{$avaliacao->codigo}/questoes/{$questao->id}")
            ->assertRedirect(route('avaliacoes.show', $avaliacao));
        $this->assertSoftDeleted('questoes', ['id' => $questao->id]);

        $this->actingAs($admin, 'admin')->post("/avaliacoes/{$avaliacao->codigo}/questoes/{$questao->id}/restaurar")
            ->assertRedirect(route('avaliacoes.show', $avaliacao));
        $this->assertNotSoftDeleted('questoes', ['id' => $questao->id]);
    }

    public function test_reenviar_numero_de_questao_excluida_restaura(): void
    {
        $avaliacao = Avaliacao::create([]);
        $questao = Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        $questao->delete();

        $this->actingAs($this->admin(), 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/questoes", ['numero' => 1, 'gabarito' => 'B']);

        $this->assertDatabaseCount('questoes', 1);
        $this->assertNotSoftDeleted('questoes', ['id' => $questao->id]);
        $this->assertDatabaseHas('questoes', ['id' => $questao->id, 'gabarito' => 'B']);
    }

    public function test_excluir_e_restaurar_questao_recalcula_o_resumo_do_boletim(): void
    {
        $avaliacao = Avaliacao::create([]);
        $q1 = Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'B']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '123', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '123', 'questao_numero' => 2, 'resposta' => 'B']);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);
        $this->assertDatabaseHas('resultado_resumos', ['ra' => '123', 'acertos' => 2, 'total' => 2]);

        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->delete("/avaliacoes/{$avaliacao->codigo}/questoes/{$q1->id}");
        $this->assertDatabaseHas('resultado_resumos', ['ra' => '123', 'acertos' => 1, 'total' => 1]);

        $this->actingAs($admin, 'admin')->post("/avaliacoes/{$avaliacao->codigo}/questoes/{$q1->id}/restaurar");
        $this->assertDatabaseHas('resultado_resumos', ['ra' => '123', 'acertos' => 2, 'total' => 2]);
    }
}
