<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\QuestaoMatriz;
use App\Models\Resposta;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BiDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    public function test_avisa_quando_prova_nao_tem_gabarito(): void
    {
        $avaliacao = Avaliacao::create([]);

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi");

        $response->assertOk();
        $response->assertSee('ainda não tem gabarito');
    }

    public function test_calcula_histograma_e_radar_por_disciplina(): void
    {
        $avaliacao = Avaliacao::create([]);
        $q1 = Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'B']);

        // Aluno 1: acerta as duas (100%)
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 2, 'resposta' => 'B']);

        // Aluno 2: acerta uma (50%)
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'questao_numero' => 2, 'resposta' => 'C']);

        $admin = $this->admin();

        $response = $this->actingAs($admin, 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi");

        $response->assertOk();
        $response->assertSee('2 respondente');

        QuestaoMatriz::create(['questao_id' => $q1->id, 'disciplina' => 'Anatomia']);

        // Igual o admin editando a questão pela tela (QuestaoController) ou
        // reimportando o gabarito faria — invalida o cache de disponibilidade
        // dos visuais (ver ResumoResultadoService::recalcular).
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $comMatriz = $this->actingAs($admin, 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi");
        $comMatriz->assertSee('Anatomia');
    }

    public function test_filtra_por_periodo(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);

        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'periodo' => '2026/1', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'periodo' => '2026/2', 'questao_numero' => 1, 'resposta' => 'A']);

        $response = $this->actingAs($this->admin(), 'admin')
            ->get("/avaliacoes/{$avaliacao->codigo}/bi?periodo=".urlencode('2026/1'));

        $response->assertSee('1 respondente');
    }

    public function test_desempenho_com_grande_volume_de_respostas(): void
    {
        $avaliacao = Avaliacao::create([]);
        $q1 = Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        QuestaoMatriz::create(['questao_id' => $q1->id, 'disciplina' => 'Anatomia']);

        $lote = [];
        for ($i = 0; $i < 5000; $i++) {
            $lote[] = [
                'avaliacao_codigo' => $avaliacao->codigo,
                'ra' => (string) $i,
                'periodo' => '',
                'questao_numero' => 1,
                'resposta' => $i % 2 === 0 ? 'A' : 'B',
            ];
        }
        Resposta::insert($lote);

        $inicio = microtime(true);
        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi");
        $duracao = microtime(true) - $inicio;

        $response->assertOk();
        $response->assertSee('5000 respondente');
        $this->assertLessThan(5.0, $duracao, 'Painel deve agregar em SQL, não varrer as respostas em PHP.');
    }

    public function test_guest_nao_acessa_bi(): void
    {
        $this->admin();
        $avaliacao = Avaliacao::create([]);

        $this->get("/avaliacoes/{$avaliacao->codigo}/bi")->assertRedirect(route('login'));
    }
}
