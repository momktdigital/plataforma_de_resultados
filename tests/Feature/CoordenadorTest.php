<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\Curso;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\CoordenadorDashboardService;
use App\Services\PsicometriaService;
use App\Services\RelatorioAdminService;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class CoordenadorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'admin', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_ADMIN]);
    }

    /** @param array<int, string> $cursos */
    private function coordenador(string $username, array $cursos): Admin
    {
        $coordenador = Admin::create(['username' => $username, 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_COORDENADOR]);
        $coordenador->sincronizarCursos($cursos);

        return $coordenador;
    }

    /**
     * Avaliação de 1 questão (gabarito A). Cada item de $alunos é [curso, resposta]
     * — resposta '' = prova em branco (aluno ausente).
     *
     * @param  array<int, array{0: string, 1: string}>  $alunos
     */
    private function avaliacao(string $nome, array $alunos, string $data = '2026-03-10', ?int $categoriaId = null, string $periodoCurso = '3º'): Avaliacao
    {
        static $ra = 1000;

        $avaliacao = Avaliacao::create(['nome' => $nome, 'data_avaliacao' => $data, 'categoria_id' => $categoriaId]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A', 'area' => 'Clínica']);

        foreach ($alunos as [$curso, $resposta]) {
            $ra++;
            $aluno = Aluno::create(['ra' => (string) $ra, 'nome' => 'Aluno '.$ra, 'curso' => $curso, 'periodo' => $periodoCurso]);
            Resposta::create([
                'avaliacao_codigo' => $avaliacao->codigo,
                'aluno_id' => $aluno->id,
                'ra' => (string) $ra,
                'periodo' => $periodoCurso,
                'questao_numero' => 1,
                'resposta' => $resposta,
            ]);
        }

        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        return $avaliacao;
    }

    public function test_importar_resultados_marca_automaticamente_os_cursos_dos_alunos(): void
    {
        $avaliacao = $this->avaliacao('Mista', [['DIREITO', 'A'], ['MEDICINA', 'B'], ['DIREITO', 'A']]);

        $this->assertSame(['DIREITO', 'MEDICINA'], $avaliacao->cursos());
    }

    public function test_reimportar_nao_remove_curso_marcado_manualmente(): void
    {
        $avaliacao = $this->avaliacao('Direito', [['DIREITO', 'A']]);
        $avaliacao->sincronizarCursos(['DIREITO', 'ENFERMAGEM']);

        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $this->assertSame(['DIREITO', 'ENFERMAGEM'], $avaliacao->cursos());
    }

    public function test_coordenador_so_lista_avaliacoes_dos_seus_cursos(): void
    {
        $direito = $this->avaliacao('Prova Direito', [['DIREITO', 'A']]);
        $medicina = $this->avaliacao('Prova Medicina', [['MEDICINA', 'A']]);
        $mista = $this->avaliacao('Prova Mista', [['DIREITO', 'A'], ['MEDICINA', 'A']]);
        $coordenador = $this->coordenador('coord-direito', ['DIREITO']);

        $response = $this->actingAs($coordenador, 'admin')->get('/avaliacoes');

        $response->assertOk();
        $response->assertSee('Prova Direito');
        $response->assertSee('Prova Mista');
        $response->assertDontSee('Prova Medicina');
        $response->assertDontSee('Nova avaliação');
        // O link para o BI de cada avaliação se chama "Dashboard".
        $response->assertSee('Dashboard')->assertDontSee('Painel BI');
    }

    public function test_acesso_excepcional_libera_avaliacao_de_outro_curso(): void
    {
        $medicina = $this->avaliacao('Prova Medicina', [['MEDICINA', 'A']]);
        $coordenador = $this->coordenador('coord-direito', ['DIREITO']);

        $this->actingAs($coordenador, 'admin')->get("/avaliacoes/{$medicina->codigo}/bi")->assertNotFound();

        $medicina->usuariosComAcesso()->attach($coordenador->id);

        $this->actingAs($coordenador, 'admin')->get('/avaliacoes')->assertSee('Prova Medicina');
        $this->actingAs($coordenador, 'admin')->get("/avaliacoes/{$medicina->codigo}/bi")->assertOk();
    }

    public function test_coordenador_nao_abre_bi_de_avaliacao_sem_aluno_do_seu_curso(): void
    {
        $medicina = $this->avaliacao('Prova Medicina', [['MEDICINA', 'A']]);
        $direito = $this->avaliacao('Prova Direito', [['DIREITO', 'A']]);
        $coordenador = $this->coordenador('coord-direito', ['DIREITO']);

        $this->actingAs($coordenador, 'admin')->get("/avaliacoes/{$medicina->codigo}/bi")->assertNotFound();
        $this->actingAs($coordenador, 'admin')->get("/avaliacoes/{$direito->codigo}/bi")->assertOk();
    }

    public function test_coordenador_nao_acessa_areas_administrativas(): void
    {
        $avaliacao = $this->avaliacao('Direito', [['DIREITO', 'A']]);
        $coordenador = $this->coordenador('coord-direito', ['DIREITO']);

        foreach (['/alunos', '/usuarios', '/categorias', '/lixeira', '/buscar', '/sistema/configuracoes', "/avaliacoes/{$avaliacao->codigo}"] as $url) {
            $this->actingAs($coordenador, 'admin')->get($url)->assertForbidden();
        }
        $this->actingAs($coordenador, 'admin')->post('/avaliacoes', ['nome' => 'x'])->assertForbidden();
        $this->actingAs($coordenador, 'admin')->delete("/avaliacoes/{$avaliacao->codigo}")->assertForbidden();
        $this->assertNull(Avaliacao::find($avaliacao->codigo)?->deleted_at);
    }

    public function test_administrador_continua_vendo_tudo_e_nao_tem_painel_de_curso(): void
    {
        $this->avaliacao('Prova Medicina', [['MEDICINA', 'A']]);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get('/avaliacoes')->assertSee('Prova Medicina')->assertSee('Nova avaliação');
        $this->actingAs($admin, 'admin')->get('/painel')->assertRedirect(route('avaliacoes.index'));
        $this->actingAs($admin, 'admin')->get('/usuarios')->assertOk();
    }

    /**
     * Mistura 2 cursos com nome/RA/foto reconhecíveis, + 1 ausente de Direito.
     * Devolve a avaliação (código usado nas rotas).
     */
    private function avaliacaoComListaNominal(): Avaliacao
    {
        $avaliacao = Avaliacao::create(['nome' => 'Lista', 'data_avaliacao' => '2026-03-10']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A', 'area' => 'Clínica']);

        $alunos = [
            ['Ana Direito', '2001', 'DIREITO', '3º', 'A', 111],
            ['Bia Direito', '2002', 'DIREITO', '3º', 'B', 112],
            ['Cris Ausente', '2003', 'DIREITO', '4º', '', 113],
            ['Caio Medicina', '2004', 'MEDICINA', '8º', 'A', 114],
            ['=HYPERLINK("http://x")', '0005', 'MEDICINA', '8º', 'A', null],
        ];
        foreach ($alunos as [$nome, $ra, $curso, $periodo, $resposta, $codPerfil]) {
            $aluno = Aluno::create(['ra' => $ra, 'nome' => $nome, 'curso' => $curso, 'periodo' => $periodo, 'cod_perfil' => $codPerfil]);
            Resposta::create([
                'avaliacao_codigo' => $avaliacao->codigo,
                'aluno_id' => $aluno->id,
                'ra' => $ra,
                'periodo' => $periodo,
                'questao_numero' => 1,
                'resposta' => $resposta,
            ]);
        }
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        return $avaliacao;
    }

    public function test_bi_lista_alunos_com_foto_nome_ra_curso_periodo_e_total(): void
    {
        $avaliacao = $this->avaliacaoComListaNominal();

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi");

        $response->assertOk()
            ->assertSee('Alunos da avaliação')
            ->assertSee('Ana Direito')
            ->assertSee('2001')
            ->assertSee('DIREITO')
            ->assertSee('8º')
            ->assertSee('1/1') // acertos/total da Ana
            ->assertSee('https://faa.jacad.com.br/academico/images/perfil-v2/111/96', false)
            ->assertSee('Baixar XLSX')
            ->assertSee('Ausente');
    }

    public function test_bi_do_coordenador_lista_so_alunos_do_curso_dele(): void
    {
        $avaliacao = $this->avaliacaoComListaNominal();
        $coordenador = $this->coordenador('coord-direito', ['DIREITO']);

        $this->actingAs($coordenador, 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi")
            ->assertOk()
            ->assertSee('Ana Direito')
            ->assertSee('Cris Ausente')
            ->assertDontSee('Caio Medicina')
            ->assertDontSee('2004');
    }

    public function test_baixa_a_lista_de_alunos_em_xlsx(): void
    {
        $avaliacao = $this->avaliacaoComListaNominal();

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi/alunos.xlsx");

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertStringContainsString("alunos-avaliacao-{$avaliacao->codigo}.xlsx", $response->headers->get('Content-Disposition'));

        $linhas = $this->lerXlsx($response->streamedContent());

        $this->assertSame(['Posição', 'Nome', 'RA', 'Curso', 'Período', 'Turma', 'Acertos', 'Total de questões', 'Percentual (%)', 'Situação', 'Foto de perfil (URL)'], $linhas[0]);
        $this->assertCount(6, $linhas); // cabeçalho + 5 alunos
        $porNome = collect($linhas)->skip(1)->keyBy(1);

        $ana = $porNome['Ana Direito'];
        $this->assertSame('2001', (string) $ana[2]);
        $this->assertSame('DIREITO', $ana[3]);
        $this->assertSame('3º', $ana[4]);
        $this->assertEquals(1, $ana[6]);
        $this->assertEquals(1, $ana[7]);
        $this->assertEquals(100, $ana[8]);
        $this->assertSame('Presente', $ana[9]);
        $this->assertSame('https://faa.jacad.com.br/academico/images/perfil-v2/111/96', $ana[10]);

        $this->assertSame('Ausente', $porNome['Cris Ausente'][9]);
        // Zeros à esquerda do RA preservados; texto "=..." não vira fórmula.
        $this->assertSame('0005', (string) $porNome['=HYPERLINK("http://x")'][2]);
        // Ausentes por último.
        $this->assertSame('Ausente', end($linhas)[9]);
    }

    public function test_xlsx_do_coordenador_so_traz_o_curso_dele_e_respeita_o_acesso(): void
    {
        $avaliacao = $this->avaliacaoComListaNominal();
        $outra = $this->avaliacao('Só medicina', [['MEDICINA', 'A']]);
        $coordenador = $this->coordenador('coord-direito', ['DIREITO']);

        $response = $this->actingAs($coordenador, 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi/alunos.xlsx?periodo=3º");
        $response->assertOk();
        $linhas = $this->lerXlsx($response->streamedContent());
        $this->assertSame(['Ana Direito', 'Bia Direito'], array_column(array_slice($linhas, 1), 1), 'período 3º, só Direito');

        $response = $this->actingAs($coordenador, 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi/alunos.xlsx");
        $nomes = array_column(array_slice($this->lerXlsx($response->streamedContent()), 1), 1);
        $this->assertNotContains('Caio Medicina', $nomes);
        $this->assertContains('Cris Ausente', $nomes);

        // Avaliação sem aluno do curso dele: 404; visitante: login.
        $this->actingAs($coordenador, 'admin')->get("/avaliacoes/{$outra->codigo}/bi/alunos.xlsx")->assertNotFound();
        $this->app['auth']->guard('admin')->logout();
        $this->get("/avaliacoes/{$avaliacao->codigo}/bi/alunos.xlsx")->assertRedirect(route('login'));
    }

    public function test_exportar_a_lista_fica_registrado_na_atividade(): void
    {
        $avaliacao = $this->avaliacaoComListaNominal();

        $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi/alunos.xlsx")->streamedContent();

        $this->assertDatabaseHas('atividades', ['acao' => 'avaliacao.lista_alunos_exportada', 'alvo_id' => $avaliacao->codigo]);
    }

    /** @return array<int, array<int, mixed>> */
    private function lerXlsx(string $conteudo): array
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($arquivo, $conteudo);

        try {
            return IOFactory::load($arquivo)->getActiveSheet()->toArray(null, false, false, false);
        } finally {
            @unlink($arquivo);
        }
    }

    public function test_cria_coordenador_com_cursos_na_aba_de_coordenadores(): void
    {
        Curso::create(['nome' => 'DIREITO']);
        Aluno::create(['ra' => '1', 'nome' => 'A', 'curso' => 'MEDICINA']);

        $response = $this->actingAs($this->admin(), 'admin')->post('/usuarios', [
            'papel' => 'coordenador',
            'username' => 'coord-novo',
            'email' => 'coord-novo@example.com',
            'password' => 'senha123456',
            'cursos' => ['DIREITO', 'MEDICINA'],
        ]);

        $response->assertRedirect(route('usuarios.index', ['aba' => 'coordenadores']));
        $novo = Admin::where('username', 'coord-novo')->firstOrFail();
        $this->assertTrue($novo->ehCoordenador());
        $this->assertSame(['DIREITO', 'MEDICINA'], $novo->cursos());
        $this->assertSame('DIREITO', $novo->fresh()->curso, 'coluna legada guarda o primeiro curso');
    }

    public function test_coordenador_exige_ao_menos_um_curso(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')->post('/usuarios', [
            'papel' => 'coordenador',
            'username' => 'coord-sem-curso',
            'email' => 'sem-curso@example.com',
            'password' => 'senha123456',
        ]);

        $response->assertSessionHasErrors('cursos');
        $this->assertDatabaseMissing('admins', ['username' => 'coord-sem-curso']);
    }

    public function test_abas_separam_administradores_de_coordenadores(): void
    {
        $this->coordenador('coord-direito', ['DIREITO']);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get('/usuarios')->assertSee('admin')->assertDontSee('coord-direito');
        $this->actingAs($admin, 'admin')->get('/usuarios?aba=coordenadores')->assertSee('coord-direito')->assertSee('DIREITO');
    }

    public function test_edita_cursos_do_coordenador(): void
    {
        Curso::create(['nome' => 'DIREITO']);
        Curso::create(['nome' => 'ENFERMAGEM']);
        $coordenador = $this->coordenador('coord', ['DIREITO']);

        $this->actingAs($this->admin(), 'admin')->put("/usuarios/{$coordenador->id}", [
            'username' => 'coord',
            'email' => 'coord@example.com',
            'cursos' => ['ENFERMAGEM'],
        ])->assertRedirect(route('usuarios.index', ['aba' => 'coordenadores']));

        $this->assertSame(['ENFERMAGEM'], $coordenador->fresh()->cursos());
    }

    public function test_configuracao_da_avaliacao_define_cursos_e_usuarios_com_acesso(): void
    {
        $avaliacao = $this->avaliacao('Direito', [['DIREITO', 'A']]);
        $coordenador = $this->coordenador('coord-medicina', ['MEDICINA']);

        $this->actingAs($this->admin(), 'admin')->put("/avaliacoes/{$avaliacao->codigo}", [
            'nome' => 'Direito',
            'acesso_enviado' => '1',
            'cursos' => ['DIREITO', 'MEDICINA'],
            'usuarios_acesso' => [$coordenador->id],
        ])->assertRedirect();

        $this->assertSame(['DIREITO', 'MEDICINA'], $avaliacao->cursos());
        $this->assertSame([$coordenador->id], $avaliacao->usuariosComAcesso()->pluck('admins.id')->all());
    }

    public function test_tela_da_avaliacao_mostra_cursos_marcados_e_coordenadores(): void
    {
        $avaliacao = $this->avaliacao('Direito', [['DIREITO', 'A']]);
        $this->coordenador('coord-medicina', ['MEDICINA']);

        $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}")
            ->assertOk()
            ->assertSee('Acesso aos resultados')
            ->assertSee('coord-medicina')
            ->assertSee('value="DIREITO" class="rounded border-slate-300"', false)
            ->assertSee('checked', false);
    }

    public function test_atualizar_avaliacao_sem_o_bloco_de_acesso_nao_mexe_nos_cursos(): void
    {
        $avaliacao = $this->avaliacao('Direito', [['DIREITO', 'A']]);

        $this->actingAs($this->admin(), 'admin')->put("/avaliacoes/{$avaliacao->codigo}", ['nome' => 'Novo nome'])->assertRedirect();

        $this->assertSame(['DIREITO'], $avaliacao->cursos());
    }

    public function test_login_do_coordenador_leva_ao_painel(): void
    {
        $coordenador = $this->coordenador('coord', ['DIREITO']);
        $coordenador->update(['password_hash' => bcrypt('senha-correta-123')]);

        $this->post('/login', ['username' => 'coord', 'password' => 'senha-correta-123'])
            ->assertRedirect(route('coordenador.painel'));
    }

    public function test_painel_exclui_ausentes_das_medias_e_calcula_presenca(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulados']);
        // Direito: 2 acertam, 1 erra, 1 ausente (em branco). Medicina não pode entrar.
        $this->avaliacao('Prova', [['DIREITO', 'A'], ['DIREITO', 'A'], ['DIREITO', 'B'], ['DIREITO', ''], ['MEDICINA', 'A']], '2026-03-10', $categoria->id);
        $coordenador = $this->coordenador('coord-direito', ['DIREITO']);

        $painel = app(CoordenadorDashboardService::class)->gerar($coordenador, '', '2026/1');

        $totais = $painel['categorias'][0]['totais'];
        $this->assertSame(4, $totais['inscritos']);
        $this->assertSame(3, $totais['presentes']);
        $this->assertSame(1, $totais['ausentes']);
        $this->assertSame(75.0, $totais['presenca']);
        $this->assertEquals(66.7, $totais['media']); // (100 + 100 + 0) / 3 presentes
        $this->assertSame(1, $totais['abaixo']);
        $this->assertSame(['2026/1'], $painel['periodosDisponiveis']);
        $this->assertSame(75.0, $painel['geral']['presenca']);
    }

    public function test_painel_renderiza_para_coordenador_e_filtra_por_periodo_letivo(): void
    {
        $this->avaliacao('Prova do primeiro semestre', [['DIREITO', 'A'], ['DIREITO', 'B']], '2026-03-10');
        $this->avaliacao('Prova do segundo semestre', [['DIREITO', 'A']], '2026-09-10');
        $coordenador = $this->coordenador('coord-direito', ['DIREITO']);

        // Sem parâmetro: período letivo mais recente.
        $this->actingAs($coordenador, 'admin')->get('/painel')
            ->assertOk()
            ->assertSee('Prova do segundo semestre')
            ->assertDontSee('Prova do primeiro semestre');

        $this->actingAs($coordenador, 'admin')->get('/painel?periodo_letivo=2026/1')
            ->assertOk()
            ->assertSee('Prova do primeiro semestre')
            ->assertDontSee('Prova do segundo semestre');

        $this->actingAs($coordenador, 'admin')->get('/painel?periodo_letivo=')
            ->assertSee('Prova do primeiro semestre')
            ->assertSee('Prova do segundo semestre');
    }

    public function test_avaliacao_anterior_e_sempre_da_mesma_categoria_mesmo_de_outro_periodo(): void
    {
        $simulados = Categoria::create(['nome' => 'Simulados']);
        $disciplinas = Categoria::create(['nome' => 'Disciplinas']);

        // Simulado do 1º semestre (média 50) e uma prova de OUTRA categoria no meio, com média 100.
        $this->avaliacao('Simulado antigo', [['DIREITO', 'A'], ['DIREITO', 'B']], '2026-02-10', $simulados->id);
        $this->avaliacao('Prova de disciplina', [['DIREITO', 'A'], ['DIREITO', 'A']], '2026-08-01', $disciplinas->id);
        // Simulado do 2º semestre (média 0): a comparação tem que ser com o simulado antigo, não com a disciplina.
        $this->avaliacao('Simulado novo', [['DIREITO', 'B'], ['DIREITO', 'B']], '2026-09-10', $simulados->id);
        $coordenador = $this->coordenador('coord-direito', ['DIREITO']);

        $painel = app(CoordenadorDashboardService::class)->gerar($coordenador, '', '2026/2');

        $this->assertCount(2, $painel['categorias'], 'uma seção por categoria, sem misturar');
        $bloco = collect($painel['categorias'])->firstWhere('nome', 'Simulados');
        $novo = $bloco['avaliacoes'][0];
        $this->assertSame('Simulado novo', $novo['nome']);
        $this->assertSame('Simulado antigo', $novo['anterior']['nome']);
        $this->assertSame('2026/1', $novo['anterior']['periodoLetivo']);
        $this->assertEquals(-50.0, $novo['delta']);
        $this->assertStringContainsString('Simulado antigo', $bloco['insights'][0]['texto']);
        $this->assertStringNotContainsString('Prova de disciplina', $bloco['insights'][0]['texto']);

        // A prova de outra categoria não tem anterior (é a única da sua categoria).
        $disciplina = collect($painel['categorias'])->firstWhere('nome', 'Disciplinas');
        $this->assertNull($disciplina['avaliacoes'][0]['anterior']);
        $this->assertNull($disciplina['avaliacoes'][0]['delta']);
    }

    public function test_periodo_do_curso_ignora_valores_que_nao_sao_periodo(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulados']);
        $this->avaliacao('Prova', [['DIREITO', 'A'], ['DIREITO', 'A']], '2026-03-10', $categoria->id, '5º PERÍODO');
        $this->avaliacao('Outra', [['DIREITO', 'A']], '2026-03-11', $categoria->id, '2026/1');
        $this->avaliacao('Terceira', [['DIREITO', 'A']], '2026-03-12', $categoria->id, '');
        $coordenador = $this->coordenador('coord-direito', ['DIREITO']);

        $painel = app(CoordenadorDashboardService::class)->gerar($coordenador, '', '2026/1');
        $bloco = $painel['categorias'][0];

        $this->assertSame(['5º período'], array_column($bloco['porPeriodoDoCurso'], 'rotulo'));
        $this->assertSame(2, $bloco['periodosOmitidos']);
    }

    public function test_bi_do_coordenador_so_conta_alunos_do_curso_dele(): void
    {
        $alunos = [];
        for ($i = 0; $i < 12; $i++) {
            $alunos[] = ['DIREITO', 'A'];      // todos acertam
            $alunos[] = ['MEDICINA', 'B'];     // todos erram
        }
        $avaliacao = $this->avaliacao('Mista', $alunos);
        $coordenador = $this->coordenador('coord-direito', ['DIREITO']);

        // Administrador enxerga as duas turmas; o coordenador só a dele.
        $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi")
            ->assertViewHas('psicometria', fn ($p) => $p['respondentes'] === 24 && $p['media'] === 50.0)
            ->assertViewHas('dados', fn ($d) => $d['totalRespondentes'] === 24);

        $this->actingAs($coordenador, 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi")
            ->assertOk()
            ->assertViewHas('psicometria', fn ($p) => $p['respondentes'] === 12 && $p['media'] === 100.0)
            ->assertViewHas('dados', fn ($d) => $d['totalRespondentes'] === 12)
            ->assertViewHas('presenca', fn ($p) => $p['total'] === 12);
    }

    public function test_servicos_de_analise_escopados_ignoram_alunos_de_outros_cursos(): void
    {
        $avaliacao = $this->avaliacao('Mista', [['DIREITO', 'A'], ['DIREITO', 'A'], ['MEDICINA', 'B'], ['MEDICINA', 'B']]);

        $relatorio = app(RelatorioAdminService::class);
        $this->assertSame(['Clínica' => 50.0], $relatorio->mediaPorArea($avaliacao));
        $this->assertSame(['Clínica' => 100.0], $relatorio->paraCursos(['DIREITO'])->mediaPorArea($avaliacao));
        $this->assertSame(['Clínica' => 0.0], $relatorio->paraCursos(['MEDICINA'])->mediaPorArea($avaliacao));

        $this->assertSame(4, app(PsicometriaService::class)->presenca($avaliacao)['total']);
        $this->assertSame(2, app(PsicometriaService::class)->paraCursos(['DIREITO'])->presenca($avaliacao)['total']);
    }

    public function test_grafias_do_curso_que_so_diferem_em_acento_sao_o_mesmo_curso(): void
    {
        Curso::create(['nome' => 'ADMINISTRACAO']);
        $avaliacao = $this->avaliacao('Administração', [['ADMINISTRAÇÃO', 'A'], ['ADMINISTRACAO', 'A']]);

        // Uma grafia só na lista de seleção (a acentuada) e no curso da avaliação.
        $this->assertSame(['ADMINISTRAÇÃO'], Curso::nomesDisponiveis());
        $this->assertSame(['ADMINISTRAÇÃO'], $avaliacao->cursos());

        // Coordenador vinculado a QUALQUER grafia enxerga a avaliação e os dois grupos de alunos.
        foreach (['ADMINISTRACAO', 'ADMINISTRAÇÃO'] as $i => $grafia) {
            $coordenador = $this->coordenador("coord-{$i}", [$grafia]);
            $this->actingAs($coordenador, 'admin')->get('/avaliacoes')->assertSee('Administração');
            $this->actingAs($coordenador, 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi")->assertOk();
            $this->assertSame(2, app(PsicometriaService::class)->paraCursos([$grafia])->presenca($avaliacao)['total']);
        }
    }

    public function test_aceita_selecionar_curso_por_grafia_equivalente(): void
    {
        Aluno::create(['ra' => '9', 'nome' => 'A', 'curso' => 'ADMINISTRAÇÃO']);

        $this->actingAs($this->admin(), 'admin')->post('/usuarios', [
            'papel' => 'coordenador',
            'username' => 'coord-adm',
            'email' => 'adm@example.com',
            'password' => 'senha123456',
            'cursos' => ['ADMINISTRACAO'],
        ])->assertRedirect(route('usuarios.index', ['aba' => 'coordenadores']));

        $this->assertSame(['ADMINISTRACAO'], Admin::where('username', 'coord-adm')->firstOrFail()->cursos());
    }

    public function test_seletor_de_curso_aparece_quando_o_coordenador_tem_mais_de_um(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulados']);
        $this->avaliacao('Prova mista', [['DIREITO', 'A'], ['DIREITO', 'A'], ['MEDICINA', 'B']], '2026-03-10', $categoria->id);

        // Um curso só: título fixo, sem seletor.
        $um = $this->coordenador('coord-um', ['DIREITO']);
        $this->actingAs($um, 'admin')->get('/painel')->assertOk()->assertDontSee('id="seletor-curso"', false);

        // Dois cursos: seletor de curso na barra de filtros, com "todos" e cada curso, preservando o período letivo.
        $dois = $this->coordenador('coord-dois', ['DIREITO', 'MEDICINA']);
        $this->actingAs($dois, 'admin')->get('/painel?periodo_letivo=2026/1')
            ->assertOk()
            ->assertSee('id="seletor-curso"', false)
            ->assertSee('Todos os meus cursos')
            ->assertSee('<option value="MEDICINA"', false)
            ->assertSee('<option value="2026/1" selected', false)
            ->assertSee('periodo_letivo=2026%2F1', false)
            ->assertViewHas('painel', fn ($p) => $p['cursoSelecionado'] === '' && $p['geral']['inscritos'] === 3);

        // Escolhendo um curso, só os alunos dele entram (e ele aparece selecionado).
        $this->actingAs($dois, 'admin')->get('/painel?curso=MEDICINA&periodo_letivo=2026/1')
            ->assertOk()
            ->assertSee('<option value="MEDICINA" selected', false)
            ->assertViewHas('painel', fn ($p) => $p['cursoSelecionado'] === 'MEDICINA' && $p['geral']['inscritos'] === 1);
    }

    public function test_grafico_de_evolucao_do_painel_usa_a_regra_de_cor_do_desempenho(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulados']);
        $this->avaliacao('Primeira', [['DIREITO', 'A'], ['DIREITO', 'B']], '2026-03-10', $categoria->id);
        $this->avaliacao('Segunda', [['DIREITO', 'A'], ['DIREITO', 'A']], '2026-04-10', $categoria->id);
        $coordenador = $this->coordenador('coord-direito', ['DIREITO']);

        $this->actingAs($coordenador, 'admin')->get('/painel/desempenho?periodo_letivo=')
            ->assertOk()
            ->assertSee('id="grafico-evolucao-0"', false)
            ->assertSee('window.LinhaDesempenho', false)
            ->assertSee('LinhaDesempenho.serie(', false);
    }

    /**
     * Layout responsivo sem "buraco": um cartão de barras sozinho ocupa a linha inteira
     * (barras em colunas); dois dividem a linha (cada um em coluna única).
     */
    public function test_cartoes_de_barras_preenchem_a_linha_sem_deixar_espaco_vazio(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulados']);
        $coordenador = $this->coordenador('coord-direito', ['DIREITO']);

        // Poucos alunos: só o cartão de período do curso (área exige 30+ respostas) -> largura total.
        $this->avaliacao('Pequena', [['DIREITO', 'A'], ['DIREITO', 'B']], '2026-03-10', $categoria->id);
        $html = $this->actingAs($coordenador, 'admin')->get('/painel/desempenho')->assertOk()->getContent();
        $this->assertStringContainsString('Desempenho por período do curso', $html);
        $this->assertStringNotContainsString('Desempenho por área', $html);
        $this->assertStringContainsString('sm:grid-cols-2 xl:grid-cols-3', $html, 'cartão sozinho: barras em colunas');
        $this->assertMatchesRegularExpression('#<div class="grid gap-4 ">#', $html, 'um só cartão: a grade não divide a linha em duas colunas');

        // Com área também: dois cartões dividem a linha, cada um em coluna única.
        $muitos = [];
        for ($i = 0; $i < 32; $i++) {
            $muitos[] = ['DIREITO', $i % 2 ? 'A' : 'B'];
        }
        $this->avaliacao('Grande', $muitos, '2026-03-11', $categoria->id);
        $html = $this->actingAs($coordenador, 'admin')->get('/painel/desempenho')->assertOk()->getContent();
        $this->assertStringContainsString('Desempenho por área', $html);
        $this->assertStringContainsString('gap-4 lg:grid-cols-2 lg:[&>*:last-child:nth-child(odd)]:col-span-2', $html);
        $this->assertStringContainsString('space-y-3 max-h-96', $html, 'dois cartões: cada um em coluna única');
    }

    public function test_painel_sem_curso_vinculado_avisa(): void
    {
        $coordenador = Admin::create(['username' => 'sem-curso', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_COORDENADOR]);

        $this->actingAs($coordenador, 'admin')->get('/painel')->assertOk()->assertSee('não está vinculada a nenhum curso');
    }
}
