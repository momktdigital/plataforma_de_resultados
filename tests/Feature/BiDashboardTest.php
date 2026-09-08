<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\QuestaoMatriz;
use App\Models\Resposta;
use App\Services\BiDashboardService;
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

    public function test_exclui_ausentes_por_padrao_e_inclui_com_o_filtro(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);

        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);
        // Aluno 2 não respondeu nada de verdade — ausente.
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'questao_numero' => 1, 'resposta' => '-']);

        $admin = $this->admin();

        $semAusentes = $this->actingAs($admin, 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi");
        $semAusentes->assertSee('1 respondente');

        $comAusentes = $this->actingAs($admin, 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi?incluir_ausentes=1");
        $comAusentes->assertSee('2 respondente');
    }

    public function test_total_do_respondente_e_calculado_por_aluno_no_painel_bi(): void
    {
        // Mesma regressão de ResumoResultadoServiceTest, mas pro painel BI
        // (BiDashboardService::gerar tinha o mesmo bug de total fixo pra
        // avaliação inteira, em vez de por aluno).
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'B']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 3, 'gabarito' => 'C']);

        // Aluno 1 viu as 3 e acertou todas (100%).
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 2, 'resposta' => 'B']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 3, 'resposta' => 'C']);

        // Aluno 2 só viu 1 das 3 e acertou (100% dele, não 33%).
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'questao_numero' => 1, 'resposta' => 'A']);

        $dados = app(BiDashboardService::class)->gerar($avaliacao);

        // Ambos os alunos ficam 100% dentro do próprio total — os dois caem
        // no bucket mais alto do histograma (90-100%), nenhum nos mais baixos.
        $this->assertSame(2, $dados['histograma'][9]);
        $this->assertSame(0, array_sum(array_slice($dados['histograma'], 0, 9)));
    }

    public function test_correta_pre_calculada_vence_a_comparacao_de_letra_no_painel_bi(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => '-']);

        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A', 'correta' => true]);

        $dados = app(BiDashboardService::class)->gerar($avaliacao);

        $this->assertSame(9, array_search(1, $dados['histograma']));
    }

    public function test_ranking_completo_mostra_badge_ausente_quando_incluido(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);

        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'questao_numero' => 1, 'resposta' => '-']);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $response = $this->actingAs($this->admin(), 'admin')
            ->get("/avaliacoes/{$avaliacao->codigo}/bi?incluir_ausentes=1");

        $response->assertOk();
        $response->assertSee('Ausente');
    }

    public function test_guest_nao_acessa_bi(): void
    {
        $this->admin();
        $avaliacao = Avaliacao::create([]);

        $this->get("/avaliacoes/{$avaliacao->codigo}/bi")->assertRedirect(route('login'));
    }
}
