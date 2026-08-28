<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Avaliacao;
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
        $avaliacaoAtiva = Avaliacao::create(['nome' => 'Ativa']);
        $questao = Questao::create(['avaliacao_codigo' => $avaliacaoAtiva->codigo, 'numero' => 1, 'gabarito' => 'A']);
        $questao->delete();

        $provaExcluida = Avaliacao::create(['nome' => 'Excluida']);
        $provaExcluida->delete();

        $response = $this->actingAs($this->admin(), 'admin')->get('/lixeira');

        $response->assertOk();
        $response->assertSee('Excluida');
        $response->assertSee('Q1');
    }

    public function test_restaura_prova_e_cascata_de_filhos(): void
    {
        $avaliacao = Avaliacao::create([]);
        $questao = Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);

        $avaliacao->questoes()->delete();
        $avaliacao->resultados()->delete();
        $avaliacao->delete();

        $response = $this->actingAs($this->admin(), 'admin')->post("/lixeira/avaliacoes/{$avaliacao->codigo}/restaurar");

        $response->assertRedirect();
        $this->assertNotSoftDeleted('avaliacoes', ['codigo' => $avaliacao->codigo]);
        $this->assertNotSoftDeleted('questoes', ['id' => $questao->id]);
        $this->assertNotSoftDeleted('respostas', ['avaliacao_codigo' => $avaliacao->codigo]);
        // Restaurar a avaliacao recalcula o resumo do boletim com os dados de volta.
        $this->assertDatabaseHas('resultado_resumos', ['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'acertos' => 1, 'total' => 1]);
    }

    public function test_exclui_prova_definitivamente_remove_filhos_via_fk_cascade(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        $avaliacao->delete();

        $response = $this->actingAs($this->admin(), 'admin')->delete("/lixeira/avaliacoes/{$avaliacao->codigo}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('avaliacoes', ['codigo' => $avaliacao->codigo]);
        $this->assertDatabaseMissing('questoes', ['avaliacao_codigo' => $avaliacao->codigo]);
    }

    public function test_restaura_e_exclui_questao_definitivamente(): void
    {
        $avaliacao = Avaliacao::create([]);
        $questao = Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        $questao->delete();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post("/lixeira/questoes/{$questao->id}/restaurar")->assertRedirect();
        $this->assertNotSoftDeleted('questoes', ['id' => $questao->id]);

        $questao->delete();
        $this->actingAs($admin, 'admin')->delete("/lixeira/questoes/{$questao->id}")->assertRedirect();
        $this->assertDatabaseMissing('questoes', ['id' => $questao->id]);
    }

    public function test_restaura_avaliacoes_em_lote(): void
    {
        $avaliacao1 = Avaliacao::create(['nome' => 'Uma']);
        $avaliacao1->delete();
        $avaliacao2 = Avaliacao::create(['nome' => 'Outra']);
        $avaliacao2->delete();
        $naoSelecionada = Avaliacao::create(['nome' => 'Não selecionada']);
        $naoSelecionada->delete();

        $response = $this->actingAs($this->admin(), 'admin')
            ->post('/lixeira/avaliacoes/restaurar-em-lote', ['ids' => [$avaliacao1->codigo, $avaliacao2->codigo]]);

        $response->assertRedirect();
        $this->assertNotSoftDeleted('avaliacoes', ['codigo' => $avaliacao1->codigo]);
        $this->assertNotSoftDeleted('avaliacoes', ['codigo' => $avaliacao2->codigo]);
        $this->assertSoftDeleted('avaliacoes', ['codigo' => $naoSelecionada->codigo]);
    }

    public function test_exclui_avaliacoes_em_lote_definitivamente(): void
    {
        $avaliacao1 = Avaliacao::create([]);
        $avaliacao1->delete();
        $avaliacao2 = Avaliacao::create([]);
        $avaliacao2->delete();

        $response = $this->actingAs($this->admin(), 'admin')
            ->delete('/lixeira/avaliacoes/excluir-em-lote', ['ids' => [$avaliacao1->codigo, $avaliacao2->codigo]]);

        $response->assertRedirect();
        $this->assertDatabaseMissing('avaliacoes', ['codigo' => $avaliacao1->codigo]);
        $this->assertDatabaseMissing('avaliacoes', ['codigo' => $avaliacao2->codigo]);
    }

    public function test_bulk_de_avaliacoes_exige_pelo_menos_um_id(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')
            ->post('/lixeira/avaliacoes/restaurar-em-lote', ['ids' => []]);

        $response->assertSessionHasErrors('ids');
    }

    public function test_restaura_e_exclui_questoes_em_lote(): void
    {
        $avaliacao = Avaliacao::create([]);
        $questao1 = Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        $questao2 = Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'B']);
        $questao1->delete();
        $questao2->delete();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post('/lixeira/questoes/restaurar-em-lote', ['ids' => [$questao1->id, $questao2->id]])
            ->assertRedirect();

        $this->assertNotSoftDeleted('questoes', ['id' => $questao1->id]);
        $this->assertNotSoftDeleted('questoes', ['id' => $questao2->id]);

        $questao1->delete();
        $questao2->delete();

        $this->actingAs($admin, 'admin')
            ->delete('/lixeira/questoes/excluir-em-lote', ['ids' => [$questao1->id, $questao2->id]])
            ->assertRedirect();

        $this->assertDatabaseMissing('questoes', ['id' => $questao1->id]);
        $this->assertDatabaseMissing('questoes', ['id' => $questao2->id]);
    }

    public function test_guest_nao_acessa_lixeira(): void
    {
        $this->admin();

        $this->get('/lixeira')->assertRedirect(route('login'));
    }
}
