<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\QuestaoReferencia;
use App\Models\Resposta;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Painéis novos do BI: cabeçalho de números, mapa de qualidade dos itens,
 * alinhamento curricular e equidade.
 */
class BiVisuaisAnaliseTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    /**
     * 24 respondentes e 3 questões: Q1 discrimina bem, Q2 todo mundo acerta
     * (item fraco), Q3 tem referências curriculares.
     *
     * São 12 por sexo de propósito: a equidade exige 10 por grupo, então um
     * cenário 6/6 faria o painel inteiro sumir (e o teste passaria a medir a
     * supressão, não o painel).
     */
    private function cenario(): Avaliacao
    {
        $avaliacao = Avaliacao::create([]);

        $q1 = Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A', 'area' => 'Clínica Médica']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'A']);
        $q3 = Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 3, 'gabarito' => 'A']);

        QuestaoReferencia::create(['questao_id' => $q1->id, 'tipo' => 'dcn', 'valor' => 'Atenção à saúde']);
        QuestaoReferencia::create(['questao_id' => $q3->id, 'tipo' => 'ppc', 'valor' => 'PPC-01']);

        for ($i = 1; $i <= 24; $i++) {
            $forte = $i <= 12;
            Aluno::create([
                'ra' => (string) $i,
                'cpf' => null,
                'data_nascimento' => '2000-01-01',
                'nome' => 'Aluno '.$i,
                'sexo' => $forte ? 'Feminino' : 'Masculino',
            ]);

            foreach ([1 => ($forte ? 'A' : 'B'), 2 => 'A', 3 => ($forte ? 'A' : 'B')] as $numero => $resposta) {
                Resposta::create([
                    'avaliacao_codigo' => $avaliacao->codigo,
                    'ra' => (string) $i,
                    'periodo' => '',
                    'questao_numero' => $numero,
                    'resposta' => $resposta,
                ]);
            }
        }

        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        return $avaliacao;
    }

    public function test_cabecalho_de_numeros_mostra_media_mediana_desvio_e_confiabilidade(): void
    {
        $avaliacao = $this->cenario();

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi");

        $response->assertOk();
        $response->assertSee('Média da turma');
        $response->assertSee('Mediana');
        $response->assertSee('Desvio-padrão');
        $response->assertSee('Confiabilidade (KR-20)');
    }

    public function test_mapa_de_itens_aparece_com_a_tabela_equivalente(): void
    {
        $avaliacao = $this->cenario();

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi");

        $response->assertOk();
        $response->assertSee('Mapa de qualidade dos itens');
        $response->assertSee('grafico-mapa-itens', false);
        // A Q2 (todo mundo acertou, D = 0) precisa aparecer na tabela de itens fracos.
        $response->assertSee('Ver como tabela');
        $response->assertSee('Não separa quem sabe de quem não sabe', false);
    }

    public function test_alinhamento_curricular_lista_dcn_e_ppc(): void
    {
        $avaliacao = $this->cenario();

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi");

        $response->assertOk();
        $response->assertSee('Alinhamento curricular e regulatório');
        $response->assertSee('Atenção à saúde');
        $response->assertSee('PPC-01');
    }

    public function test_equidade_mostra_os_dois_grupos_com_respondentes_suficientes(): void
    {
        $avaliacao = $this->cenario();

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi");

        $response->assertOk();
        $response->assertSee('Equidade: desempenho por recorte');
        $response->assertSee('Feminino');
        $response->assertSee('Masculino');
    }

    public function test_visuais_psicometricos_somem_com_poucos_respondentes(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);

        for ($i = 1; $i <= 3; $i++) {
            Resposta::create([
                'avaliacao_codigo' => $avaliacao->codigo,
                'ra' => (string) $i,
                'periodo' => '',
                'questao_numero' => 1,
                'resposta' => 'A',
            ]);
        }
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi");

        $response->assertOk();
        // Com 3 respondentes os grupos de 27% não significam nada — o painel
        // não pode aparecer prometendo uma medida que não se sustenta.
        $response->assertDontSee('Mapa de qualidade dos itens');
        $response->assertDontSee('Confiabilidade (KR-20)');
    }

    public function test_cada_visual_tem_o_botao_de_explicacao_com_leitura_do_dado_atual(): void
    {
        $avaliacao = $this->cenario();

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi");

        $response->assertOk();
        $response->assertSee('explicacao-toggle', false);
        $response->assertSee('O que este visual significa e como analisá-lo', false);
        // O popover traz a leitura do resultado que está na tela, não só o texto fixo.
        $response->assertSee('Nesta avaliação:', false);
    }

    public function test_perfil_demografico_e_equidade_ficam_na_mesma_secao(): void
    {
        $avaliacao = $this->cenario();

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi");

        $response->assertOk();
        $response->assertSeeInOrder([
            'Análise demográfica',
            'Sexo',
            'Cor/raça',
            'UF',
            'Equidade: desempenho por recorte',
        ]);
    }

    public function test_alinhamento_some_quando_nenhuma_questao_tem_referencia(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'periodo' => '', 'questao_numero' => 1, 'resposta' => 'A']);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi");

        $response->assertOk();
        $response->assertDontSee('Alinhamento curricular e regulatório');
    }
}
