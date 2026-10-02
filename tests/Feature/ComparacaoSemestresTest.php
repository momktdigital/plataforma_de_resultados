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
 * Comparação entre dois períodos letivos, categoria a categoria.
 */
class ComparacaoSemestresTest extends TestCase
{
    use RefreshDatabase;

    private function coordenador(string $username = 'coord-direito', array $cursos = ['DIREITO']): Admin
    {
        $coordenador = Admin::create(['username' => $username, 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_COORDENADOR]);
        $coordenador->sincronizarCursos($cursos);

        return $coordenador;
    }

    private function aluno(string $nome, string $curso = 'DIREITO'): Aluno
    {
        static $ra = 8000;
        $ra++;

        return Aluno::create(['ra' => (string) $ra, 'nome' => $nome, 'curso' => $curso, 'periodo' => '3º']);
    }

    /** @param array<int, array{0: Aluno, 1: string}> $resultados 'A' acerta as 3 questões, 'B' erra, '' = ausente */
    private function avaliacao(string $nome, string $data, array $resultados, ?int $categoriaId = null): Avaliacao
    {
        $avaliacao = Avaliacao::create(['nome' => $nome, 'data_avaliacao' => $data, 'categoria_id' => $categoriaId]);
        foreach ([1, 2, 3] as $numero) {
            Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => $numero, 'gabarito' => 'A', 'area' => 'Clínica']);
        }
        foreach ($resultados as [$aluno, $resposta]) {
            foreach ([1, 2, 3] as $numero) {
                Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'aluno_id' => $aluno->id, 'ra' => $aluno->ra, 'periodo' => '3º', 'questao_numero' => $numero, 'resposta' => $resposta]);
            }
        }
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        return $avaliacao;
    }

    private function cenario(): array
    {
        $simulados = Categoria::create(['nome' => 'Simulados']);
        $disciplinas = Categoria::create(['nome' => 'Disciplinas']);
        $ana = $this->aluno('Ana');
        $bia = $this->aluno('Bia');
        $cris = $this->aluno('Cris');

        // 2026/1: média 50 (Ana 100, Bia 0). 2026/2: média 66,7 (Ana 0, Bia 100, Cris 100).
        $this->avaliacao('Simulado 1', '2026-03-10', [[$ana, 'A'], [$bia, 'B']], $simulados->id);
        $this->avaliacao('Simulado 2', '2026-09-10', [[$ana, 'B'], [$bia, 'A'], [$cris, 'A']], $simulados->id);
        // Categoria que só existe no 2º semestre.
        $this->avaliacao('Prova de disciplina', '2026-09-20', [[$ana, 'A'], [$bia, 'A']], $disciplinas->id);

        return compact('ana', 'bia', 'cris');
    }

    public function test_compara_a_media_por_categoria_e_o_geral(): void
    {
        $this->cenario();

        $c = app(ComparacaoSemestresService::class)->comparar($this->coordenador(), '', '2026/2', '2026/1');

        $simulados = collect($c['categorias'])->firstWhere('nome', 'Simulados');
        $this->assertTrue($simulados['emAmbos']);
        $this->assertEquals(66.7, $simulados['media']['atual']);
        $this->assertEquals(50.0, $simulados['media']['referencia']);
        $this->assertEquals(16.7, $simulados['media']['delta']);
        $this->assertEquals(33.3, $simulados['abaixoPct']['atual']);
        $this->assertEquals(50.0, $simulados['abaixoPct']['referencia']);

        $this->assertSame(['3º período'], array_column($simulados['periodosDoCurso'], 'rotulo'));
        $this->assertEquals(16.7, $simulados['periodosDoCurso'][0]['delta']);

        // A categoria que só existiu em 2026/2 não é comparada com nada.
        $disciplinas = collect($c['categorias'])->firstWhere('nome', 'Disciplinas');
        $this->assertFalse($disciplinas['emAmbos']);
        $this->assertSame('2026/2', $disciplinas['so']);
        $this->assertNull($disciplinas['alunos']);
        $this->assertNull($disciplinas['media']['delta']);

        $this->assertSame(3, $c['geral']['alunos']['atual']);
        $this->assertSame(2, $c['geral']['alunos']['referencia']);
        $this->assertSame(1, $c['geral']['alunos']['delta'] === null ? null : (int) $c['geral']['alunos']['delta']);
    }

    public function test_pareia_os_mesmos_alunos_nos_dois_periodos(): void
    {
        $this->cenario();

        $alunos = collect(app(ComparacaoSemestresService::class)->comparar($this->coordenador(), '', '2026/2', '2026/1')['categorias'])
            ->firstWhere('nome', 'Simulados')['alunos'];

        $this->assertSame(2, $alunos['comparaveis'], 'Cris só fez o 2º semestre');
        $this->assertSame(1, $alunos['subiram']);
        $this->assertSame(1, $alunos['cairam']);
        $this->assertSame(0, $alunos['estaveis']);
        $this->assertSame('Bia', $alunos['maisSubiram'][0]['nome']);
        $this->assertEquals(100.0, $alunos['maisSubiram'][0]['delta']);
        $this->assertSame('Ana', $alunos['maisCairam'][0]['nome']);
        $this->assertEquals(-100.0, $alunos['maisCairam'][0]['delta']);
    }

    public function test_pagina_de_comparacao_padrao_compara_com_o_periodo_anterior(): void
    {
        $this->cenario();
        $coordenador = $this->coordenador();

        $this->actingAs($coordenador, 'admin')->get('/painel/comparativo')
            ->assertOk()
            ->assertSee('O que mudou')
            ->assertSee('Os mesmos alunos, nos dois períodos')
            ->assertSee('Esta categoria só teve avaliações em')
            ->assertSee('Comparar com')
            ->assertViewHas('atual', '2026/2')
            ->assertViewHas('referencia', '2026/1');

        // Escolher um período de referência inválido (ou igual ao atual) cai no padrão.
        $this->actingAs($coordenador, 'admin')->get('/painel/comparativo?periodo_letivo=2026/2&comparar=2026/2')->assertViewHas('referencia', '2026/1');
        $this->actingAs($coordenador, 'admin')->get('/painel/comparativo?periodo_letivo=2026/2&comparar=1999/9')->assertViewHas('referencia', '2026/1');
        // Do mais antigo, compara com o seguinte.
        $this->actingAs($coordenador, 'admin')->get('/painel/comparativo?periodo_letivo=2026/1')
            ->assertViewHas('atual', '2026/1')->assertViewHas('referencia', '2026/2');
    }

    public function test_so_um_periodo_avisa_que_nao_ha_o_que_comparar(): void
    {
        $ana = $this->aluno('Ana');
        $this->avaliacao('Prova', '2026-03-10', [[$ana, 'A']]);

        $this->actingAs($this->coordenador(), 'admin')->get('/painel/comparativo')
            ->assertOk()->assertSee('Só existe um período letivo com resultados');
    }

    public function test_comparacao_so_usa_alunos_do_curso_do_coordenador(): void
    {
        $ana = $this->aluno('Ana');
        $caio = $this->aluno('Caio Medicina', 'MEDICINA');
        $this->avaliacao('Prova 1', '2026-03-10', [[$ana, 'A'], [$caio, 'B']]);
        $this->avaliacao('Prova 2', '2026-09-10', [[$ana, 'A'], [$caio, 'B']]);

        $resposta = $this->actingAs($this->coordenador(), 'admin')->get('/painel/comparativo');

        $resposta->assertOk()->assertDontSee('Caio Medicina');
        $this->assertSame(1, $resposta->viewData('comparacao')['geral']['alunos']['atual']);
    }

    public function test_perfis_sem_acesso_e_coordenador_sem_curso(): void
    {
        $this->cenario();
        $admin = Admin::create(['username' => 'admin', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_ADMIN]);
        $this->actingAs($admin, 'admin')->get('/painel/comparativo')->assertRedirect(route('avaliacoes.index'));

        $semCurso = Admin::create(['username' => 'sem-curso', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_COORDENADOR]);
        $this->actingAs($semCurso, 'admin')->get('/painel/comparativo')->assertOk()->assertSee('não está vinculada a nenhum curso');
    }

    public function test_textos_de_destaque_usam_virgula_decimal(): void
    {
        $this->cenario();

        $textos = collect(app(ComparacaoSemestresService::class)->comparar($this->coordenador(), '', '2026/2', '2026/1')['destaques'])->pluck('texto')->implode(' | ');

        $this->assertStringContainsString('subiu 16,7 pontos de 2026/1 para 2026/2 (50% → 66,7%)', $textos);
        $this->assertDoesNotMatchRegularExpression('/\d\.\d%/', $textos);
    }

    public function test_destaques_da_visao_geral_vem_separados_por_categoria(): void
    {
        // Duas categorias, cada uma com a SUA "área com menor desempenho" — nunca soltas, sem dizer de qual categoria.
        $a = Categoria::create(['nome' => 'Categoria Alfa']);
        $b = Categoria::create(['nome' => 'Categoria Beta']);
        $alunos = [];
        for ($i = 0; $i < 12; $i++) {
            $alunos[] = $this->aluno('Aluno '.$i);
        }
        $resultados = array_map(fn ($al, $i) => [$al, $i % 2 ? 'A' : 'B'], $alunos, array_keys($alunos));
        $this->avaliacao('Prova Alfa', '2026-03-10', $resultados, $a->id);
        $this->avaliacao('Prova Beta', '2026-03-11', $resultados, $b->id);

        $resposta = $this->actingAs($this->coordenador(), 'admin')->get('/painel');

        $resposta->assertOk()->assertSee('O que chamou atenção')
            ->assertSee('Cada grupo é uma categoria de avaliação')
            ->assertViewHas('destaques', fn ($grupos) => collect($grupos)->pluck('titulo')->contains('Categoria Alfa') && collect($grupos)->pluck('titulo')->contains('Categoria Beta'));
    }
}
