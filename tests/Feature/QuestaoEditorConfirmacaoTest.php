<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\Resposta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A confirmação em si (window.confirm()) roda no navegador — não dá pra
 * exercitar num teste de HTTP puro. O que este teste garante é a PARTE
 * server-side que a alimenta: a página expõe pra cada questão quantas
 * respostas já existem (respostas_count), pro JS decidir quando avisar que
 * mudar gabarito/anulação recalcula nota de gente que já respondeu — ver
 * admin/avaliacoes/show.blade.php.
 */
class QuestaoEditorConfirmacaoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    public function test_expoe_a_contagem_de_respostas_de_uma_questao_ja_respondida(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'questao_numero' => 1, 'resposta' => 'B']);

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}");

        $response->assertOk();
        $response->assertSee('"respostas_count":2', false);
    }

    public function test_questao_sem_resposta_expoe_contagem_zero(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}");

        $response->assertOk();
        $response->assertSee('"respostas_count":0', false);
    }

    public function test_resposta_excluida_nao_conta(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A'])->delete();

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}");

        $response->assertOk();
        $response->assertSee('"respostas_count":0', false);
    }
}
