<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\ComparacaoSemestresService;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comparar semestres: filtros de categoria e período do curso, "alunos abaixo do esperado" (ou de 60% sem a meta) e o
 * quadro por período do curso (quantos alunos atingiram o esperado em cada semestre), que olha para o período e não
 * para o aluno. Também a ordem do menu do coordenador.
 */
class ComparacaoPorPeriodoTest extends TestCase
{
    use RefreshDatabase;

    private function coordenador(): Admin
    {
        $coordenador = Admin::create(['username' => 'coord', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_COORDENADOR]);
        $coordenador->sincronizarCursos(['MEDICINA']);

        return $coordenador;
    }

    /**
     * Avaliação de 10 questões (gabarito A); `$meta` = período mínimo das 5 primeiras e das 5 últimas.
     * `$alunos` = [período do curso, acertos de 0 a 10].
     *
     * @param  array{0: ?int, 1: ?int}  $meta
     * @param  array<int, array{0: string, 1: int}>  $alunos
     */
    private function avaliacao(string $nome, string $data, ?int $categoriaId, array $meta, array $alunos): Avaliacao
    {
        static $ra = 6000;

        $avaliacao = Avaliacao::create(['nome' => $nome, 'data_avaliacao' => $data, 'categoria_id' => $categoriaId]);
        foreach (range(1, 10) as $n) {
            Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => $n, 'gabarito' => 'A', 'area' => 'Clínica', 'periodo_minimo' => $n <= 5 ? $meta[0] : $meta[1]]);
        }

        foreach ($alunos as [$periodo, $acertos]) {
            $ra++;
            $aluno = Aluno::create(['ra' => (string) $ra, 'nome' => 'Aluno '.$ra, 'curso' => 'MEDICINA', 'periodo' => $periodo]);
            foreach (range(1, 10) as $n) {
                Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'aluno_id' => $aluno->id, 'ra' => (string) $ra, 'periodo' => $periodo, 'questao_numero' => $n, 'resposta' => $n <= $acertos ? 'A' : 'B']);
            }
        }
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        return $avaliacao;
    }

    /** Dois semestres da categoria: no 2º período o mínimo é 50% (5 de 10) e no 4º é 100%. */
    private function cenario(): Categoria
    {
        $categoria = Categoria::create(['nome' => 'Diagnósticos']);
        // 2026/1: 2º → 5, 5, 2, 0 (2 de 4 dentro: 50%); 4º → 10, 10, 7, 4 (2 de 4: 50%).
        $this->avaliacao('D1', '2026-03-10', $categoria->id, [1, 3], [['2º', 5], ['2º', 5], ['2º', 2], ['2º', 0], ['4º', 10], ['4º', 10], ['4º', 7], ['4º', 4]]);
        // 2026/2: 2º → 5, 5, 5, 2 (3 de 4: 75%); 4º → 10, 8, 7, 4 (1 de 4: 25%).
        $this->avaliacao('D2', '2026-09-10', $categoria->id, [1, 3], [['2º', 5], ['2º', 5], ['2º', 5], ['2º', 2], ['4º', 10], ['4º', 8], ['4º', 7], ['4º', 4]]);

        return $categoria;
    }

    public function test_olha_para_o_periodo_do_curso_e_conta_alunos_dentro_do_esperado_em_cada_semestre(): void
    {
        $this->cenario();

        $c = app(ComparacaoSemestresService::class)->comparar($this->coordenador(), '', '2026/2', '2026/1');
        $ap = collect($c['categorias'])->firstWhere('nome', 'Diagnósticos')['alunosPorPeriodo'];

        $this->assertSame([2, 4], array_column($ap['periodos'], 'ordem'));

        [$segundo, $quarto] = $ap['periodos'];
        $this->assertSame(['presentes' => 4, 'dentro' => 2, 'pct' => 50.0], $segundo['referencia']);
        $this->assertSame(['presentes' => 4, 'dentro' => 3, 'pct' => 75.0], $segundo['atual']);
        $this->assertSame(25.0, $segundo['deltaPct']);
        $this->assertSame(1, $segundo['deltaAlunos']);
        $this->assertSame('subiu', $segundo['sentido']);

        $this->assertSame(['presentes' => 4, 'dentro' => 1, 'pct' => 25.0], $quarto['atual']);
        $this->assertSame(-25.0, $quarto['deltaPct']);
        $this->assertSame(-1, $quarto['deltaAlunos']);
        $this->assertSame('caiu', $quarto['sentido']);

        $this->assertSame(['subiram' => 1, 'estaveis' => 0, 'cairam' => 1], ['subiram' => $ap['subiram'], 'estaveis' => $ap['estaveis'], 'cairam' => $ap['cairam']]);
    }

    public function test_variacao_pequena_conta_como_estavel(): void
    {
        $categoria = Categoria::create(['nome' => 'Diagnósticos']);
        $this->avaliacao('D1', '2026-03-10', $categoria->id, [1, 3], [['2º', 5], ['2º', 5], ['2º', 5], ['2º', 5], ['2º', 5], ['2º', 5], ['2º', 5], ['2º', 5], ['2º', 5], ['2º', 0]]);
        $this->avaliacao('D2', '2026-09-10', $categoria->id, [1, 3], [['2º', 5], ['2º', 5], ['2º', 5], ['2º', 5], ['2º', 5], ['2º', 5], ['2º', 5], ['2º', 5], ['2º', 5], ['2º', 5], ['2º', 0]]);

        $ap = collect(app(ComparacaoSemestresService::class)->comparar($this->coordenador(), '', '2026/2', '2026/1')['categorias'])->first()['alunosPorPeriodo'];

        // 9 de 10 (90%) → 10 de 11 (90,9%): menos de 5 pontos.
        $this->assertSame('estavel', $ap['periodos'][0]['sentido']);
        $this->assertSame(1, $ap['estaveis']);
    }

    public function test_card_abaixo_do_esperado_com_meta_e_abaixo_de_60_sem_meta(): void
    {
        $this->cenario();
        $simulados = Categoria::create(['nome' => 'Simulados']);
        $this->avaliacao('S1', '2026-03-11', $simulados->id, [null, null], [['2º', 10], ['2º', 5], ['2º', 2]]);
        $this->avaliacao('S2', '2026-09-11', $simulados->id, [null, null], [['2º', 10], ['2º', 6], ['2º', 2]]);

        $coordenador = $this->coordenador();
        $comparacao = app(ComparacaoSemestresService::class)->comparar($coordenador, '', '2026/2', '2026/1');
        $porNome = collect($comparacao['categorias'])->keyBy('nome');

        $this->assertTrue($porNome['Diagnósticos']['comMeta']);
        // 2026/1: 4 de 8 abaixo (50%); 2026/2: 4 de 8 abaixo do esperado (50%) — 2º: 1 abaixo; 4º: 3 abaixo.
        $this->assertSame(50.0, $porNome['Diagnósticos']['abaixoEsperado']['referencia']);
        $this->assertSame(50.0, $porNome['Diagnósticos']['abaixoEsperado']['atual']);
        $this->assertFalse($porNome['Simulados']['comMeta']);

        $resposta = $this->actingAs($coordenador, 'admin')->get('/painel/comparativo?periodo_letivo=2026/2&comparar=2026/1')->assertOk();
        $resposta->assertSee('Alunos abaixo do esperado');
        $resposta->assertSee('Alunos abaixo de 60%');
    }

    public function test_filtro_de_categoria_mostra_so_a_escolhida_e_lista_as_disponiveis(): void
    {
        $diagnosticos = $this->cenario();
        $simulados = Categoria::create(['nome' => 'Simulados']);
        $this->avaliacao('S1', '2026-03-11', $simulados->id, [null, null], [['2º', 5]]);
        $this->avaliacao('S2', '2026-09-11', $simulados->id, [null, null], [['2º', 5]]);

        $coordenador = $this->coordenador();
        $servico = app(ComparacaoSemestresService::class);
        $c = $servico->comparar($coordenador, '', '2026/2', '2026/1', ['categoria' => (string) $simulados->id]);

        $this->assertSame(['Simulados'], array_column($c['categorias'], 'nome'));
        $this->assertEqualsCanonicalizing(['Diagnósticos', 'Simulados'], array_column($c['categoriasDisponiveis'], 'nome'));

        $resposta = $this->actingAs($coordenador, 'admin')->get('/painel/comparativo?periodo_letivo=2026/2&comparar=2026/1&categoria='.$diagnosticos->id)->assertOk();
        $resposta->assertSee('id="filtro-categoria"', false);
        $resposta->assertSee('<option value="'.$diagnosticos->id.'" selected', false);
    }

    public function test_filtro_de_periodo_do_curso_vale_para_os_dois_semestres(): void
    {
        $this->cenario();

        $coordenador = $this->coordenador();
        $c = app(ComparacaoSemestresService::class)->comparar($coordenador, '', '2026/2', '2026/1', ['periodo_curso' => '4']);
        $cat = collect($c['categorias'])->firstWhere('nome', 'Diagnósticos');

        $this->assertSame([2, 4], $c['periodosCursoDisponiveis']);
        $this->assertSame([4], array_column($cat['alunosPorPeriodo']['periodos'], 'ordem'));
        $this->assertSame(4, $c['geral']['alunos']['atual']); // só os alunos do 4º período
        // 4º período: 2 de 4 abaixo em 2026/1 (50%); 3 de 4 em 2026/2 (75%).
        $this->assertSame(50.0, $cat['abaixoEsperado']['referencia']);
        $this->assertSame(75.0, $cat['abaixoEsperado']['atual']);

        $this->actingAs($coordenador, 'admin')->get('/painel/comparativo?periodo_letivo=2026/2&comparar=2026/1&periodo_curso=4')
            ->assertOk()
            ->assertSee('id="filtro-periodo-curso"', false)
            ->assertSee('<option value="4" selected', false);
    }

    public function test_menu_do_coordenador_na_ordem_visao_geral_desempenho_avaliacoes_comparar_alunos(): void
    {
        $this->cenario();

        $html = $this->actingAs($this->coordenador(), 'admin')->get('/painel/comparativo')->assertOk()->getContent();

        // Menu lateral (as abas do cabeçalho seguem a mesma ordem relativa).
        $posicoes = array_map(fn ($rotulo) => strpos($html, '</i> '.$rotulo), ['Visão geral', 'Desempenho', 'Avaliações', 'Comparar semestres', 'Alunos do curso']);
        $this->assertNotContains(false, $posicoes, 'todos os itens do menu aparecem');
        $this->assertSame($posicoes, collect($posicoes)->sort()->values()->all(), 'ordem do menu lateral');
    }
}
