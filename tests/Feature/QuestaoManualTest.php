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

    public function test_exclui_questoes_em_lote(): void
    {
        $avaliacao = Avaliacao::create([]);
        $questao1 = Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        $questao2 = Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'B']);
        $naoSelecionada = Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 3, 'gabarito' => 'C']);
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'admin')
            ->delete("/avaliacoes/{$avaliacao->codigo}/questoes/excluir-em-lote", ['ids' => [$questao1->id, $questao2->id]]);

        $response->assertRedirect(route('avaliacoes.show', $avaliacao));
        $this->assertSoftDeleted('questoes', ['id' => $questao1->id]);
        $this->assertSoftDeleted('questoes', ['id' => $questao2->id]);
        $this->assertNotSoftDeleted('questoes', ['id' => $naoSelecionada->id]);
        $this->assertDatabaseHas('atividades', ['acao' => 'questao.excluida', 'alvo_id' => (string) $questao1->id]);
    }

    public function test_exclusao_em_lote_de_questoes_e_escopada_a_avaliacao_da_url(): void
    {
        $avaliacao1 = Avaliacao::create([]);
        $avaliacao2 = Avaliacao::create([]);
        $questaoDeOutraAvaliacao = Questao::create(['avaliacao_codigo' => $avaliacao2->codigo, 'numero' => 1, 'gabarito' => 'A']);

        $this->actingAs($this->admin(), 'admin')
            ->delete("/avaliacoes/{$avaliacao1->codigo}/questoes/excluir-em-lote", ['ids' => [$questaoDeOutraAvaliacao->id]]);

        $this->assertNotSoftDeleted('questoes', ['id' => $questaoDeOutraAvaliacao->id]);
    }

    public function test_exclusao_em_lote_de_questoes_exige_pelo_menos_um_id(): void
    {
        $avaliacao = Avaliacao::create([]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->delete("/avaliacoes/{$avaliacao->codigo}/questoes/excluir-em-lote", ['ids' => []]);

        $response->assertSessionHasErrors('ids');
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

    public function test_cria_questao_manualmente_com_todos_os_metadados(): void
    {
        $avaliacao = Avaliacao::create([]);

        $this->actingAs($this->admin(), 'admin')->post("/avaliacoes/{$avaliacao->codigo}/questoes", [
            'numero' => 1,
            'gabarito' => 'B',
            'area' => 'Clínica Médica',
            'tema' => 'HIV/AIDS',
            'habilidade' => 'E3 — Avaliação',
            'bloom_nivel' => 'Aplicação',
            'bloom_verbo' => 'Avaliar',
            'miller_nivel' => 'Sabe como',
            'dificuldade_pedagogica' => 'facil',
            'dificuldade_tri' => '0.35',
            'matriz_prova' => ['Item 1', 'Item 2'],
            'dcn' => ['Art. 5º'],
            'portaria_inep' => ['P1', 'P2'],
            'ppc' => ['PPC-01'],
            'matriz_periodo' => ['1', '2'],
            'matriz_disciplina' => ['Anatomia', 'Fisiologia'],
            'matriz_codigo' => ['AN01', 'FI02'],
        ]);

        $questao = Questao::where('numero', 1)->firstOrFail();
        $this->assertSame('Clínica Médica', $questao->area);
        $this->assertSame('HIV/AIDS', $questao->tema);
        $this->assertSame('Avaliar', $questao->bloom_verbo);
        $this->assertSame('facil', $questao->dificuldade_pedagogica);
        $this->assertSame('0.3500', $questao->dificuldade_tri);
        $this->assertSame(['Item 1', 'Item 2'], $questao->referencias()->where('tipo', 'matriz_prova')->pluck('valor')->all());
        $this->assertSame(['Art. 5º'], $questao->referencias()->where('tipo', 'dcn')->pluck('valor')->all());
        $this->assertCount(2, $questao->matrizes);
        $this->assertSame('Anatomia', $questao->matrizes[0]->disciplina);
        $this->assertSame(1, $questao->matrizes[0]->periodo);
    }

    public function test_reenviar_com_chips_vazios_apaga_referencias_e_matrizes_anteriores(): void
    {
        $avaliacao = Avaliacao::create([]);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post("/avaliacoes/{$avaliacao->codigo}/questoes", [
            'numero' => 1,
            'gabarito' => 'A',
            'matriz_prova' => ['Item 1'],
            'matriz_periodo' => ['1'],
            'matriz_disciplina' => ['Anatomia'],
            'matriz_codigo' => ['AN01'],
        ]);
        $questao = Questao::where('numero', 1)->firstOrFail();
        $this->assertCount(1, $questao->referencias);
        $this->assertCount(1, $questao->matrizes);

        // Reenvia o mesmo formulário sem nenhum chip — o editor manual
        // sempre representa o estado completo da questão, então limpar os
        // campos e salvar de novo precisa apagar o que existia.
        $this->actingAs($admin, 'admin')->post("/avaliacoes/{$avaliacao->codigo}/questoes", [
            'numero' => 1,
            'gabarito' => 'A',
        ]);

        $questao->refresh();
        $this->assertCount(0, $questao->referencias);
        $this->assertCount(0, $questao->matrizes);
    }

    public function test_tela_da_avaliacao_renderiza_editor_com_dados_da_questao(): void
    {
        $avaliacao = Avaliacao::create([]);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post("/avaliacoes/{$avaliacao->codigo}/questoes", [
            'numero' => 1,
            'gabarito' => 'B',
            'area' => 'Clínica Médica',
            'matriz_prova' => ['Item 1', 'Item 2'],
            'matriz_periodo' => ['1'],
            'matriz_disciplina' => ['Anatomia'],
            'matriz_codigo' => ['AN01'],
        ]);

        $response = $this->actingAs($admin, 'admin')->get("/avaliacoes/{$avaliacao->codigo}");

        $response->assertOk();
        $response->assertSee('Editar', false);
        $response->assertSee('data-tag-input', false);
        $response->assertSee('Item 1', false);
        $response->assertSee('assets/js/tag-input.js', false);
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
