<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Prova;
use App\Models\Questao;
use App\Models\Resposta;
use App\Models\ResultadoMetrica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RespondenteTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    private function provaComRespostas(): Prova
    {
        $prova = Prova::create([]);
        Questao::create(['prova_codigo' => $prova->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Questao::create(['prova_codigo' => $prova->codigo, 'numero' => 2, 'gabarito' => 'B']);

        Resposta::create(['prova_codigo' => $prova->codigo, 'ra' => '111', 'periodo' => '2026/1', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['prova_codigo' => $prova->codigo, 'ra' => '111', 'periodo' => '2026/1', 'questao_numero' => 2, 'resposta' => 'C']);
        ResultadoMetrica::create(['prova_codigo' => $prova->codigo, 'ra' => '111', 'periodo' => '2026/1', 'nome_metrica' => 'Total', 'valor' => '1']);

        Resposta::create(['prova_codigo' => $prova->codigo, 'ra' => '222', 'periodo' => '2026/1', 'questao_numero' => 1, 'resposta' => 'A']);

        return $prova;
    }

    public function test_lista_respondentes_agrupados(): void
    {
        $prova = $this->provaComRespostas();

        $response = $this->actingAs($this->admin(), 'admin')->get("/provas/{$prova->codigo}/respondentes");

        $response->assertOk();
        $response->assertSee('111');
        $response->assertSee('222');
    }

    public function test_filtra_por_busca_de_ra(): void
    {
        $prova = $this->provaComRespostas();

        $response = $this->actingAs($this->admin(), 'admin')->get("/provas/{$prova->codigo}/respondentes?search=111");

        $response->assertSee('111');
        $response->assertDontSee('222');
    }

    public function test_mostra_respostas_do_respondente_com_comparacao_ao_gabarito(): void
    {
        $prova = $this->provaComRespostas();

        $response = $this->actingAs($this->admin(), 'admin')
            ->get("/provas/{$prova->codigo}/respondentes/show?chave=111&periodo=".urlencode('2026/1'));

        $response->assertOk();
        $response->assertSee('Total');
    }

    public function test_exclui_e_restaura_resultados_de_um_periodo(): void
    {
        $prova = $this->provaComRespostas();
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'admin')
            ->delete("/provas/{$prova->codigo}/periodos", ['periodo' => '2026/1']);

        $response->assertRedirect(route('provas.respondentes.index', $prova));
        $this->assertSoftDeleted('respostas', ['ra' => '111', 'periodo' => '2026/1']);
        $this->assertSoftDeleted('resultado_metricas', ['ra' => '111', 'periodo' => '2026/1']);

        $this->actingAs($admin, 'admin')
            ->post("/provas/{$prova->codigo}/periodos/restaurar", ['periodo' => '2026/1']);

        $this->assertNotSoftDeleted('respostas', ['ra' => '111', 'periodo' => '2026/1']);
        $this->assertNotSoftDeleted('resultado_metricas', ['ra' => '111', 'periodo' => '2026/1']);
    }

    public function test_guest_nao_acessa_respondentes(): void
    {
        $this->admin();
        $prova = $this->provaComRespostas();

        $this->get("/provas/{$prova->codigo}/respondentes")->assertRedirect(route('login'));
    }
}
