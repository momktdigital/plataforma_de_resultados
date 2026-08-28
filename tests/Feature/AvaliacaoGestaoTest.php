<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\Resposta;
use App\Models\ResultadoMetrica;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvaliacaoGestaoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    public function test_atualiza_configuracoes_da_prova(): void
    {
        $avaliacao = Avaliacao::create(['nome' => 'Original']);

        $response = $this->actingAs($this->admin(), 'admin')->put("/avaliacoes/{$avaliacao->codigo}", [
            'nome' => 'Renomeada',
            'link_comentado' => 'https://exemplo.com/gabarito',
        ]);

        $response->assertRedirect(route('avaliacoes.show', $avaliacao));
        $this->assertSame('Renomeada', $avaliacao->fresh()->nome);
        $this->assertSame('https://exemplo.com/gabarito', $avaliacao->fresh()->link_comentado);
    }

    public function test_excluir_prova_faz_soft_delete_em_cascata(): void
    {
        $avaliacao = Avaliacao::create([]);
        $questao = Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '123', 'questao_numero' => 1, 'resposta' => 'A']);
        ResultadoMetrica::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '123', 'nome_metrica' => 'Total', 'valor' => '10']);

        $response = $this->actingAs($this->admin(), 'admin')->delete("/avaliacoes/{$avaliacao->codigo}");

        $response->assertRedirect(route('avaliacoes.index'));
        $this->assertSoftDeleted('avaliacoes', ['codigo' => $avaliacao->codigo]);
        $this->assertSoftDeleted('questoes', ['id' => $questao->id]);
        $this->assertDatabaseCount('respostas', 1);
        $this->assertNotNull(Resposta::withTrashed()->first()->deleted_at);
    }

    public function test_excluir_avaliacao_apaga_os_resumos_pre_calculados(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '123', 'questao_numero' => 1, 'resposta' => 'A']);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);
        $this->assertDatabaseCount('resultado_resumos', 1);

        $this->actingAs($this->admin(), 'admin')->delete("/avaliacoes/{$avaliacao->codigo}");

        // resultado_resumos não tem soft-delete — sem essa limpeza, a linha
        // ficaria órfã pra sempre (o cascadeOnDelete só dispara em exclusão
        // definitiva, não no soft-delete da avaliação).
        $this->assertDatabaseCount('resultado_resumos', 0);
    }

    public function test_guest_nao_atualiza_nem_exclui_prova(): void
    {
        $this->admin();
        $avaliacao = Avaliacao::create([]);

        $this->put("/avaliacoes/{$avaliacao->codigo}", ['nome' => 'X'])->assertRedirect(route('login'));
        $this->delete("/avaliacoes/{$avaliacao->codigo}")->assertRedirect(route('login'));
    }
}
