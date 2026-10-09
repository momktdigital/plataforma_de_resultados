<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\CoordenadorDashboardService;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tela de desempenho do coordenador (/painel/desempenho): categorias em dropdown, filtros de categoria e período do
 * curso, alunos abaixo do desempenho esperado (ou de 60% sem a meta), gráficos com abas Geral/Por período
 * (evolução, área, Bloom e tema) e a tabela de avaliações no fim da categoria.
 */
class CoordenadorDesempenhoTest extends TestCase
{
    use RefreshDatabase;

    private function coordenador(): Admin
    {
        $coordenador = Admin::create(['username' => 'coord', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_COORDENADOR]);
        $coordenador->sincronizarCursos(['MEDICINA']);

        return $coordenador;
    }

    /**
     * Avaliação de 10 questões (gabarito A; uma área; Bloom e tema por metade; `$meta` = período mínimo das duas metades).
     * `$alunos` = [período do curso, quantos acertos de 0 a 10] — quem acerta N responde A nas N primeiras.
     *
     * @param  array{0: ?int, 1: ?int}  $meta
     * @param  array<int, array{0: string, 1: int}>  $alunos
     */
    private function avaliacao(string $nome, string $data, ?int $categoriaId, array $meta, array $alunos): Avaliacao
    {
        static $ra = 7000;

        $avaliacao = Avaliacao::create(['nome' => $nome, 'data_avaliacao' => $data, 'categoria_id' => $categoriaId]);
        foreach (range(1, 10) as $n) {
            Questao::create([
                'avaliacao_codigo' => $avaliacao->codigo, 'numero' => $n, 'gabarito' => 'A', 'area' => 'Clínica',
                'bloom_nivel' => $n <= 5 ? 'Aplicar' : 'Lembrar', 'tema' => $n <= 5 ? 'Arritmias' : 'Asma',
                'periodo_minimo' => $n <= 5 ? $meta[0] : $meta[1],
            ]);
        }

        foreach ($alunos as [$periodo, $acertos]) {
            $ra++;
            $aluno = Aluno::create(['ra' => (string) $ra, 'nome' => 'Aluno '.$ra, 'curso' => 'MEDICINA', 'periodo' => $periodo]);
            foreach (range(1, 10) as $n) {
                Resposta::create([
                    'avaliacao_codigo' => $avaliacao->codigo, 'aluno_id' => $aluno->id, 'ra' => (string) $ra, 'periodo' => $periodo,
                    'questao_numero' => $n, 'resposta' => $n <= $acertos ? 'A' : 'B',
                ]);
            }
        }
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        return $avaliacao;
    }

    /** Quatro alunos do 2º período (esperado 50%) e quatro do 4º (esperado 100%). */
    private function turmas(array $acertos2, array $acertos4): array
    {
        return [
            ...array_map(fn ($a) => ['2º', $a], $acertos2),
            ...array_map(fn ($a) => ['4º', $a], $acertos4),
        ];
    }

    public function test_com_meta_o_cartao_vira_alunos_abaixo_do_desempenho_esperado(): void
    {
        $categoria = Categoria::create(['nome' => 'Diagnósticos']);
        // Metade das questões vale desde o 1º período, a outra metade só a partir do 3º.
        $this->avaliacao('D1', '2026-03-10', $categoria->id, [1, 3], $this->turmas([5, 5, 2, 0], [10, 10, 7, 4]));

        $resposta = $this->actingAs($this->coordenador(), 'admin')->get('/painel/desempenho?periodo_letivo=2026/1')->assertOk();

        // 2º período (mínimo 50%): 5 e 5 passam; 2 e 0 não. 4º período (mínimo 100%): só os dois 10 passam → 4 de 8 abaixo.
        $resposta->assertSee('Alunos abaixo do desempenho esperado');
        $resposta->assertDontSee('Abaixo de 60%');
        $resposta->assertSee('4 de 8 resultados', false);
        $resposta->assertViewHas('painel', fn ($p) => $p['categorias'][0]['detalhe']['comMeta'] === true
            && $p['categorias'][0]['detalhe']['abaixoEsperado']['abaixo'] === 4
            && $p['categorias'][0]['detalhe']['abaixoEsperado']['pct'] === 50.0);
    }

    public function test_sem_meta_o_cartao_continua_abaixo_de_60(): void
    {
        $categoria = Categoria::create(['nome' => 'Diagnósticos']);
        $this->avaliacao('D1', '2026-03-10', $categoria->id, [null, null], $this->turmas([10, 5, 2, 0], [10, 6, 7, 4]));
        $this->avaliacao('D2', '2026-04-10', $categoria->id, [null, null], $this->turmas([10, 5, 2, 0], [10, 6, 7, 4]));

        $resposta = $this->actingAs($this->coordenador(), 'admin')->get('/painel/desempenho?periodo_letivo=2026/1')->assertOk();

        $resposta->assertSee('Abaixo de 60%');
        $resposta->assertDontSee('Alunos abaixo do desempenho esperado');
        $resposta->assertSee('Evolução da média nesta categoria');
        $resposta->assertDontSee('Evolução dos alunos que atingiram o desempenho esperado');
    }

    public function test_evolucao_considera_alunos_que_atingiram_o_esperado_e_guarda_o_detalhe_por_periodo(): void
    {
        $categoria = Categoria::create(['nome' => 'Diagnósticos']);
        $this->avaliacao('D1', '2026-03-10', $categoria->id, [1, 3], $this->turmas([5, 5, 2, 0], [10, 10, 7, 4]));
        $this->avaliacao('D2', '2026-04-10', $categoria->id, [1, 3], $this->turmas([5, 5, 5, 5], [10, 10, 10, 10]));

        $resposta = $this->actingAs($this->coordenador(), 'admin')->get('/painel/desempenho?periodo_letivo=2026/1')->assertOk();

        $resposta->assertSee('Evolução dos alunos que atingiram o desempenho esperado');
        $resposta->assertDontSee('Evolução da média nesta categoria');

        $pontos = $resposta->viewData('painel')['categorias'][0]['detalhe']['evolucao'];
        $this->assertSame([50.0, 100.0], array_column(array_column($pontos, 'geral'), 'pct'));
        // Por período do curso: no 2º, D1 tem 2 de 4 dentro (50%); no 4º, 2 de 4 (50%). Em D2, todos (100%).
        $this->assertSame(50.0, $pontos[0]['porPeriodo'][2]['pct']);
        $this->assertSame(50.0, $pontos[0]['porPeriodo'][4]['pct']);
        $this->assertSame(100.0, $pontos[1]['porPeriodo'][2]['pct']);
    }

    public function test_categorias_em_dropdown_graficos_com_abas_e_tabela_no_fim(): void
    {
        $categoria = Categoria::create(['nome' => 'Diagnósticos']);
        $outra = Categoria::create(['nome' => 'Simulados']);
        $this->avaliacao('D1', '2026-03-10', $categoria->id, [1, 3], $this->turmas([5, 5, 2, 0], [10, 10, 7, 4]));
        $this->avaliacao('D2', '2026-04-10', $categoria->id, [1, 3], $this->turmas([5, 5, 5, 5], [10, 10, 10, 10]));
        $this->avaliacao('S1', '2026-04-11', $outra->id, [null, null], $this->turmas([5, 5, 5, 5], [5, 5, 5, 5]));

        $html = $this->actingAs($this->coordenador(), 'admin')->get('/painel/desempenho?periodo_letivo=2026/1')->assertOk()->getContent();

        // Duas categorias: dois dropdowns, ambos fechados por padrão.
        $this->assertSame(2, substr_count($html, '<details class="categoria-painel'));
        $this->assertStringNotContainsString('categoria-painel group bg-white border border-slate-200 rounded-xl shadow-sm" data-cat="0" open', $html);

        // Gráficos de evolução, área, Bloom e tema — cada um com as abas Geral e Por período.
        foreach (['evolucao', 'area', 'bloom', 'tema'] as $tipo) {
            $this->assertStringContainsString('data-tipo="'.$tipo.'"', $html, $tipo);
        }
        $this->assertStringContainsString('Desempenho por nível de Bloom', $html);
        $this->assertStringContainsString('Desempenho por tema', $html);
        $this->assertStringContainsString('data-aba="periodo"', $html);

        // A tabela de avaliações fica depois dos gráficos, no fim da categoria.
        $primeira = strpos($html, 'Avaliações da categoria Diagnósticos');
        $this->assertNotFalse($primeira);
        $this->assertGreaterThan(strpos($html, 'data-cat="0" data-tipo="tema"'), $primeira);
        $this->assertGreaterThan(strpos($html, 'data-cat="0" data-tipo="evolucao"'), $primeira);
    }

    public function test_filtro_de_categoria_abre_so_a_escolhida(): void
    {
        $categoria = Categoria::create(['nome' => 'Diagnósticos']);
        $outra = Categoria::create(['nome' => 'Simulados']);
        $this->avaliacao('D1', '2026-03-10', $categoria->id, [null, null], $this->turmas([5, 5], [5, 5]));
        $this->avaliacao('S1', '2026-04-11', $outra->id, [null, null], $this->turmas([5, 5], [5, 5]));

        $coordenador = $this->coordenador();
        $resposta = $this->actingAs($coordenador, 'admin')->get('/painel/desempenho?periodo_letivo=2026/1&categoria='.$outra->id)->assertOk();

        $resposta->assertSee('id="filtro-categoria"', false);
        $resposta->assertSee('<option value="'.$outra->id.'" selected', false);
        $resposta->assertViewHas('painel', fn ($p) => count($p['categorias']) === 1 && $p['categorias'][0]['nome'] === 'Simulados' && count($p['categoriasDisponiveis']) === 2);
        $this->assertSame(1, substr_count($resposta->getContent(), '<details class="categoria-painel'));
        $resposta->assertSee('data-cat="0" open', false);

        // Uma categoria que não existe no período é ignorada (a tela não esvazia).
        $this->actingAs($coordenador, 'admin')->get('/painel/desempenho?periodo_letivo=2026/1&categoria=9999')->assertOk()
            ->assertViewHas('painel', fn ($p) => count($p['categorias']) === 2);
    }

    public function test_filtro_de_periodo_do_curso_restringe_os_alunos_e_os_graficos(): void
    {
        $categoria = Categoria::create(['nome' => 'Diagnósticos']);
        $this->avaliacao('D1', '2026-03-10', $categoria->id, [1, 3], $this->turmas([5, 5, 2, 0], [10, 10, 7, 4]));

        $resposta = $this->actingAs($this->coordenador(), 'admin')->get('/painel/desempenho?periodo_letivo=2026/1&periodo_curso=2')->assertOk();

        $resposta->assertSee('id="filtro-periodo-curso"', false);
        $resposta->assertSee('<option value="2" selected', false);
        $resposta->assertViewHas('painel', function ($p) {
            $cat = $p['categorias'][0];

            return $p['periodosCursoDisponiveis'] === [2, 4]
                && $cat['totais']['inscritos'] === 4
                // 2º período, mínimo 50%: 5 e 5 passam, 2 e 0 não → 2 de 4 abaixo.
                && $cat['detalhe']['abaixoEsperado']['abaixo'] === 2
                && $cat['detalhe']['abaixoEsperado']['total'] === 4;
        });
    }

    public function test_desempenho_por_campos_traz_o_geral_e_a_aba_por_periodo(): void
    {
        $categoria = Categoria::create(['nome' => 'Diagnósticos']);
        $avaliacao = $this->avaliacao('D1', '2026-03-10', $categoria->id, [null, null], $this->turmas([10, 10, 10, 10, 10, 10], [0, 0, 0, 0, 0, 0]));

        $campos = app(CoordenadorDashboardService::class)->desempenhoPorCampos(['MEDICINA'], [$avaliacao->codigo]);

        // Bloom: 60 respostas em cada nível; metade dos alunos acerta tudo e metade erra tudo → 50% no geral.
        $bloom = collect($campos['bloom']['geral'])->keyBy('rotulo');
        $this->assertSame(50.0, $bloom['Aplicar']['percentual']);
        $this->assertSame(60, $bloom['Aplicar']['respostas']);
        $this->assertSame([2, 4], $campos['bloom']['periodos']);
        $this->assertSame(100.0, $campos['bloom']['porPeriodo'][2]['Aplicar']);
        $this->assertSame(0.0, $campos['bloom']['porPeriodo'][4]['Aplicar']);
        $this->assertSame(['Arritmias', 'Asma'], collect($campos['tema']['geral'])->pluck('rotulo')->sort()->values()->all());
        $this->assertSame(50.0, $campos['area']['geral'][0]['percentual']);

        // Só um período do curso: o geral passa a ser o dele.
        $so2 = app(CoordenadorDashboardService::class)->desempenhoPorCampos(['MEDICINA'], [$avaliacao->codigo], 2);
        $this->assertSame(100.0, collect($so2['bloom']['geral'])->firstWhere('rotulo', 'Aplicar')['percentual']);
        $this->assertSame([2], $so2['bloom']['periodos']);
    }

    public function test_celula_com_poucas_respostas_nao_aparece_na_aba_por_periodo(): void
    {
        $categoria = Categoria::create(['nome' => 'Diagnósticos']);
        // 6 alunos do 2º (30 respostas por nível) e 1 do 4º (5 respostas): o 4º fica abaixo das 10 respostas mínimas.
        $avaliacao = $this->avaliacao('D1', '2026-03-10', $categoria->id, [null, null], [['2º', 10], ['2º', 10], ['2º', 10], ['2º', 10], ['2º', 10], ['2º', 10], ['4º', 0]]);

        $campos = app(CoordenadorDashboardService::class)->desempenhoPorCampos(['MEDICINA'], [$avaliacao->codigo]);

        $this->assertSame([2], $campos['bloom']['periodos']);
    }
}
