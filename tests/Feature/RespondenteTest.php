<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\Resposta;
use App\Models\ResultadoMetrica;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RespondenteTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    private function provaComRespostas(): Avaliacao
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'B']);

        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '111', 'periodo' => '2026/1', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '111', 'periodo' => '2026/1', 'questao_numero' => 2, 'resposta' => 'C']);
        ResultadoMetrica::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '111', 'periodo' => '2026/1', 'nome_metrica' => 'Total', 'valor' => '1']);

        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '222', 'periodo' => '2026/1', 'questao_numero' => 1, 'resposta' => 'A']);

        return $avaliacao;
    }

    public function test_lista_respondentes_agrupados(): void
    {
        $avaliacao = $this->provaComRespostas();

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/respondentes");

        $response->assertOk();
        $response->assertSee('111');
        $response->assertSee('222');
    }

    public function test_filtra_por_busca_de_ra(): void
    {
        $avaliacao = $this->provaComRespostas();

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/respondentes?search=111");

        $response->assertSee('111');
        $response->assertDontSee('222');
    }

    public function test_lista_mostra_nome_do_aluno_e_acertos(): void
    {
        Aluno::create(['ra' => '111', 'cpf' => '11122233344', 'data_nascimento' => '2000-01-01', 'nome' => 'Ana Respondente']);
        $avaliacao = $this->provaComRespostas();
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/respondentes");

        $response->assertOk();
        $response->assertSee('Ana Respondente');
        $response->assertSee('1/2');
    }

    public function test_detalhe_mostra_nome_e_acertos_do_aluno(): void
    {
        Aluno::create(['ra' => '111', 'cpf' => '11122233344', 'data_nascimento' => '2000-01-01', 'nome' => 'Ana Respondente']);
        $avaliacao = $this->provaComRespostas();
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $response = $this->actingAs($this->admin(), 'admin')
            ->get("/avaliacoes/{$avaliacao->codigo}/respondentes/show?chave=111&periodo=".urlencode('2026/1'));

        $response->assertOk();
        $response->assertSee('Ana Respondente');
        $response->assertSee('1/2');
    }

    public function test_mostra_respostas_do_respondente_com_comparacao_ao_gabarito(): void
    {
        $avaliacao = $this->provaComRespostas();

        $response = $this->actingAs($this->admin(), 'admin')
            ->get("/avaliacoes/{$avaliacao->codigo}/respondentes/show?chave=111&periodo=".urlencode('2026/1'));

        $response->assertOk();
        $response->assertSee('Total');
    }

    public function test_exclui_e_restaura_resultados_de_um_periodo(): void
    {
        $avaliacao = $this->provaComRespostas();
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);
        $this->assertDatabaseHas('resultado_resumos', ['ra' => '111', 'periodo' => '2026/1']);
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'admin')
            ->delete("/avaliacoes/{$avaliacao->codigo}/periodos", ['periodo' => '2026/1']);

        $response->assertRedirect(route('avaliacoes.respondentes.index', $avaliacao));
        $this->assertSoftDeleted('respostas', ['ra' => '111', 'periodo' => '2026/1']);
        $this->assertSoftDeleted('resultado_metricas', ['ra' => '111', 'periodo' => '2026/1']);
        // O resumo do boletim também é recalculado — período excluído some dele.
        $this->assertDatabaseMissing('resultado_resumos', ['ra' => '111', 'periodo' => '2026/1']);

        $this->actingAs($admin, 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/periodos/restaurar", ['periodo' => '2026/1']);

        $this->assertNotSoftDeleted('respostas', ['ra' => '111', 'periodo' => '2026/1']);
        $this->assertNotSoftDeleted('resultado_metricas', ['ra' => '111', 'periodo' => '2026/1']);
        $this->assertDatabaseHas('resultado_resumos', ['ra' => '111', 'periodo' => '2026/1']);
    }

    public function test_guest_nao_acessa_respondentes(): void
    {
        $this->admin();
        $avaliacao = $this->provaComRespostas();

        $this->get("/avaliacoes/{$avaliacao->codigo}/respondentes")->assertRedirect(route('login'));
    }
}
