<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\AlunoMatricula;
use App\Models\Atividade;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\ConfiguracaoSistema;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\Auth\LoginPorCodigoService;
use App\Services\ReitorCompetenciasService;
use App\Services\ReitorDashboardService;
use App\Services\ReitorEvolucaoService;
use App\Services\ReitorItensService;
use App\Services\ReitorRiscoService;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Perfil de reitor e o painel da reitoria: acesso (só leitura, sem dado nominal), números por curso (participação
 * com previstos da matrícula, proficiência, média, mediana), evolução entre semestres e competências.
 */
class ReitorTest extends TestCase
{
    use RefreshDatabase;

    private int $ra = 5000;

    private function usuario(string $nome, string $role): Admin
    {
        return Admin::create(['username' => $nome, 'email' => "{$nome}@example.test", 'password_hash' => Hash::make('senha-secreta-123'), 'role' => $role]);
    }

    private function reitor(): Admin
    {
        return $this->usuario('reitor', Admin::ROLE_REITOR);
    }

    private function admin(): Admin
    {
        return $this->usuario('admin', Admin::ROLE_ADMIN);
    }

    private function coordenador(): Admin
    {
        $coordenador = $this->usuario('coord', Admin::ROLE_COORDENADOR);
        $coordenador->sincronizarCursos(['DIREITO']);

        return $coordenador;
    }

    /**
     * Avaliação de 10 questões (gabarito A; 1–5 = Lembrar/Clínica, 6–10 = Aplicar/Ética). Cada aluno é
     * [curso, período do curso, acertos (0–10) ou null para ausente]; quem acerta N responde A nas N primeiras.
     *
     * @param  array<int, array{0: string, 1: string, 2: ?int}>  $alunos
     */
    private function avaliacao(string $nome, string $data, array $alunos, ?int $categoriaId = null, bool $comMatricula = true): Avaliacao
    {
        $avaliacao = Avaliacao::create(['nome' => $nome, 'data_avaliacao' => $data, 'categoria_id' => $categoriaId]);
        foreach (range(1, 10) as $n) {
            Questao::create([
                'avaliacao_codigo' => $avaliacao->codigo, 'numero' => $n, 'gabarito' => 'A',
                'area' => $n <= 5 ? 'Clínica' : 'Ética', 'bloom_nivel' => $n <= 5 ? 'Lembrar' : 'Aplicar',
            ]);
        }

        foreach ($alunos as [$curso, $periodo, $acertos]) {
            $aluno = $this->aluno($curso, $periodo, $this->periodoLetivo($data), $comMatricula);
            foreach (range(1, 10) as $n) {
                Resposta::create([
                    'avaliacao_codigo' => $avaliacao->codigo, 'aluno_id' => $aluno->id, 'ra' => $aluno->ra, 'periodo' => $periodo,
                    'questao_numero' => $n, 'resposta' => $acertos === null ? '' : ($n <= $acertos ? 'A' : 'B'),
                ]);
            }
        }

        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        return $avaliacao;
    }

    private function periodoLetivo(string $data): string
    {
        return substr($data, 0, 4).'/'.((int) substr($data, 5, 2) <= 6 ? 1 : 2);
    }

    private function aluno(string $curso, string $periodo, string $periodoLetivo, bool $comMatricula = true, string $status = 'ATIVA'): Aluno
    {
        $this->ra++;
        $aluno = Aluno::create(['ra' => (string) $this->ra, 'nome' => 'Fulano Sigiloso '.$this->ra, 'curso' => $curso, 'periodo' => $periodo]);
        if ($comMatricula) {
            AlunoMatricula::create(['aluno_id' => $aluno->id, 'curso' => $curso, 'periodo' => $periodo, 'periodo_letivo' => $periodoLetivo, 'status' => $status]);
        }

        return $aluno;
    }

    /**
     * DIREITO (3º): 4 fizeram (100%, 70%, 60%, 30%) + 1 ausente + 1 matriculado sem resultado + 1 cancelado (não conta)
     * + 2 ativos no 5º período, onde a prova não foi aplicada. MEDICINA (3º): 80% e 50%.
     */
    private function cenario(): Avaliacao
    {
        $avaliacao = $this->avaliacao('2026/1 - Diagnóstico', '2026-03-10', [
            ['DIREITO', '3º', 10], ['DIREITO', '3º', 7], ['DIREITO', '3º', 6], ['DIREITO', '3º', 3], ['DIREITO', '3º', null],
            ['MEDICINA', '3º', 8], ['MEDICINA', '3º', 5],
        ]);
        $this->aluno('DIREITO', '3º', '2026/1');
        $this->aluno('DIREITO', '3º', '2026/1', true, 'CANCELADA');
        $this->aluno('DIREITO', '5º', '2026/1');
        $this->aluno('DIREITO', '5º', '2026/1');

        return $avaliacao;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Perfil e acesso
    // ------------------------------------------------------------------------------------------------------------

    public function test_papel_de_reitor_e_reconhecido_e_nao_vira_outro_perfil(): void
    {
        $reitor = new Admin(['role' => ' Rector ']);

        $this->assertSame(Admin::ROLE_REITOR, $reitor->papel());
        $this->assertTrue($reitor->ehReitor());
        $this->assertFalse($reitor->ehAdministrador());
        $this->assertFalse($reitor->ehCoordenador());
        $this->assertTrue($reitor->temPapelValido());
        $this->assertSame('reitor.visao', $reitor->rotaInicial());
    }

    public function test_reitor_acessa_as_cinco_telas_do_painel_sem_dado_nominal(): void
    {
        $this->cenario();
        $reitor = $this->reitor();

        foreach (['reitor.visao', 'reitor.desempenho', 'reitor.trajetoria', 'reitor.competencias', 'reitor.evolucao'] as $rota) {
            $this->actingAs($reitor, 'admin')->get(route($rota))
                ->assertOk()
                ->assertSee('Painel da reitoria')
                ->assertDontSee('Fulano Sigiloso');
        }
    }

    public function test_reitor_nao_alcanca_gestao_avaliacoes_alunos_nem_painel_de_coordenador(): void
    {
        $avaliacao = $this->cenario();
        $reitor = $this->reitor();

        // GET em área de curso/avaliação volta para o painel dele; o resto é proibido.
        $this->actingAs($reitor, 'admin')->get('/avaliacoes')->assertRedirect(route('reitor.visao'));
        $this->actingAs($reitor, 'admin')->get('/painel')->assertRedirect(route('reitor.visao'));
        $this->actingAs($reitor, 'admin')->get('/painel/alunos')->assertRedirect(route('reitor.visao'));
        $this->actingAs($reitor, 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi")->assertRedirect(route('reitor.visao'));
        $this->actingAs($reitor, 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi/alunos.xlsx")->assertRedirect(route('reitor.visao'));
        $this->actingAs($reitor, 'admin')->get('/usuarios')->assertForbidden();
        $this->actingAs($reitor, 'admin')->get('/alunos')->assertForbidden();
        $this->actingAs($reitor, 'admin')->get('/buscar?q=Fulano')->assertForbidden();
        $this->actingAs($reitor, 'admin')->get("/avaliacoes/{$avaliacao->codigo}/respondentes")->assertForbidden();
        $this->actingAs($reitor, 'admin')->get('/sistema/configuracoes')->assertForbidden();
        $this->actingAs($reitor, 'admin')->delete("/avaliacoes/{$avaliacao->codigo}")->assertForbidden();
        $this->actingAs($reitor, 'admin')->get('/notificacoes')->assertRedirect(route('reitor.visao'));
    }

    public function test_coordenador_nao_abre_o_painel_da_reitoria(): void
    {
        $this->cenario();

        $coordenador = $this->coordenador();

        $this->actingAs($coordenador, 'admin')->get('/reitoria')->assertForbidden();
        $this->actingAs($coordenador, 'admin')->get('/reitoria/exportar.xlsx')->assertForbidden();
    }

    public function test_visitante_vai_para_o_login_e_administrador_tambem_ve_o_painel(): void
    {
        $this->cenario();
        $admin = $this->admin(); // sem nenhum administrador o sistema se considera "não instalado"

        $this->get('/reitoria')->assertRedirect(route('login'));
        $this->actingAs($admin, 'admin')->get('/reitoria')->assertOk()->assertSee('Painel da reitoria');
    }

    public function test_perfil_do_reitor_continua_acessivel(): void
    {
        $this->actingAs($this->reitor(), 'admin')->get('/perfil')->assertOk();
    }

    public function test_login_com_senha_leva_o_reitor_ao_painel_e_a_raiz_tambem(): void
    {
        $this->reitor();

        $this->post('/login', ['username' => 'reitor', 'password' => 'senha-secreta-123'])->assertRedirect(route('reitor.visao'));
        $this->get('/')->assertRedirect(route('reitor.visao'));
    }

    public function test_reitor_entra_por_codigo_do_email_como_o_coordenador(): void
    {
        $reitor = $this->reitor();
        $admin = $this->admin();
        $servico = app(LoginPorCodigoService::class);

        $this->assertSame($reitor->id, $servico->localizar('reitor@example.test')?->id);
        $this->assertSame($reitor->id, $servico->localizar('reitor')?->id);
        // administrador segue só com senha
        $this->assertNull($servico->localizar($admin->username));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Cadastro de usuários
    // ------------------------------------------------------------------------------------------------------------

    public function test_administrador_cria_reitor_com_email_e_sem_curso_nem_senha(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post('/usuarios', ['papel' => 'reitor', 'username' => 'novo.reitor', 'email' => 'novo@example.test'])
            ->assertRedirect(route('usuarios.index', ['aba' => 'reitores']));

        $reitor = Admin::where('username', 'novo.reitor')->first();
        $this->assertTrue($reitor->ehReitor());
        $this->assertSame([], DB::table('admin_cursos')->where('admin_id', $reitor->id)->pluck('curso')->all());
        $this->assertTrue(Atividade::where('acao', 'reitor.criado')->exists());

        $this->actingAs($admin, 'admin')->get('/usuarios?aba=reitores')->assertOk()->assertSee('novo.reitor');
    }

    public function test_reitor_exige_email_e_nao_aceita_curso(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post('/usuarios', ['papel' => 'reitor', 'username' => 'sem.email'])->assertSessionHasErrors('email');
        $this->actingAs($admin, 'admin')->post('/usuarios', ['papel' => 'reitor', 'username' => 'com.curso', 'email' => 'c@example.test', 'cursos' => ['DIREITO']])->assertSessionHasErrors('cursos');
        $this->assertSame(0, Admin::reitores()->count());
    }

    public function test_edicao_de_reitor_atualiza_o_email_e_proibe_curso(): void
    {
        $admin = $this->admin();
        $reitor = $this->reitor();

        $this->actingAs($admin, 'admin')->put("/usuarios/{$reitor->id}", ['username' => 'reitor', 'email' => 'outro@example.test'])
            ->assertRedirect(route('usuarios.index', ['aba' => 'reitores']));
        $this->assertSame('outro@example.test', $reitor->fresh()->email);

        $this->actingAs($admin, 'admin')->put("/usuarios/{$reitor->id}", ['username' => 'reitor', 'email' => 'outro@example.test', 'cursos' => ['DIREITO']])
            ->assertSessionHasErrors('cursos');
    }

    // ------------------------------------------------------------------------------------------------------------
    // Números do painel
    // ------------------------------------------------------------------------------------------------------------

    private function estatisticas(Avaliacao $avaliacao, array $cursos = []): array
    {
        $servico = app(ReitorDashboardService::class);
        $ctx = $servico->contexto($avaliacao->codigo, $cursos);

        return [$ctx, $servico->estatisticas($ctx)];
    }

    public function test_participacao_usa_previstos_da_matricula_e_deixa_periodo_sem_aplicacao_a_parte(): void
    {
        [, $est] = $this->estatisticas($this->cenario());
        $direito = $est['cursos']['DIREITO'];

        // 5 com resultado (4 fizeram + 1 ausente) + 1 matriculado sem resultado; o cancelado e os do 5º não entram.
        $this->assertSame(6, $direito['previstos']);
        $this->assertSame(4, $direito['fizeram']);
        $this->assertSame(2, $direito['ausentes']);
        $this->assertSame(66.7, $direito['participacao']);
        $this->assertSame('3º', $direito['periodosAvaliadosRotulo']);
        $this->assertSame([5 => 2], $direito['ativosSemAplicacao']);
        $this->assertSame('matricula', $direito['fontePrevistos']);
        // meta 98% de 6 previstos = 6 pessoas (5,88 arredondado para cima): faltam 2
        $this->assertSame(2, $direito['alunosAMais']);

        $this->assertSame(100.0, $est['cursos']['MEDICINA']['participacao']);
        $this->assertSame(8, $est['total']['previstos']);
        $this->assertSame(6, $est['total']['fizeram']);
        $this->assertSame(75.0, $est['total']['participacao']);
    }

    public function test_proficiencia_media_e_mediana_excluem_os_ausentes(): void
    {
        [, $est] = $this->estatisticas($this->cenario());
        $direito = $est['cursos']['DIREITO'];

        $this->assertSame(4, $direito['n']);
        $this->assertSame(3, $direito['proficientes']); // 100, 70 e 60 (o corte inclui 60)
        $this->assertSame(75.0, $direito['proficienciaPct']);
        $this->assertSame(65.0, $direito['media']);
        $this->assertSame(65.0, $direito['mediana']);

        $this->assertSame(50.0, $est['cursos']['MEDICINA']['proficienciaPct']);
        $this->assertSame(6, $est['total']['n']);
        $this->assertSame(66.7, $est['total']['proficienciaPct']);
        $this->assertSame(65.0, $est['total']['media']);
    }

    public function test_faixas_e_patamares_seguem_o_corte(): void
    {
        [, $est] = $this->estatisticas($this->cenario());
        $total = $est['total'];

        // 100, 70, 60, 30, 80, 50 → <40: 1 | 40–49: 0 | 50–59: 1 | 60–69: 1 | ≥70: 3
        $this->assertSame([1, 0, 1, 1, 3], array_column($total['faixas'], 'n'));
        $this->assertSame([60 => 66.7, 65 => 50.0, 70 => 50.0, 75 => 33.3, 80 => 33.3], $total['patamares']);
    }

    public function test_corte_configurado_pelo_administrador_muda_a_proficiencia(): void
    {
        $avaliacao = $this->cenario();
        ConfiguracaoSistema::definir('reitor_corte_proficiencia', '70');

        [$ctx, $est] = $this->estatisticas($avaliacao);

        $this->assertSame(70.0, $ctx['corte']);
        $this->assertSame(50.0, $est['total']['proficienciaPct']); // 100, 70 e 80 de 6
        $this->assertSame(['< 50%', '50–59%', '60–69%', '70–79%', '≥ 80%'], array_column(ReitorDashboardService::faixas(70.0), 'rotulo'));
    }

    public function test_corte_e_meta_invalidos_na_configuracao_voltam_para_limites_seguros(): void
    {
        ConfiguracaoSistema::definir('reitor_corte_proficiencia', '5');
        ConfiguracaoSistema::definir('reitor_meta_participacao', '250');

        $this->assertSame(30.0, ReitorDashboardService::corte());
        $this->assertSame(100.0, ReitorDashboardService::meta());
    }

    public function test_filtro_de_cursos_restringe_a_visao_e_o_total(): void
    {
        [$ctx, $est] = $this->estatisticas($this->cenario(), ['MEDICINA']);

        $this->assertTrue($ctx['filtrando']);
        $this->assertSame(['MEDICINA'], array_keys($est['cursos']));
        $this->assertSame(2, $est['total']['n']);
        $this->assertSame(['DIREITO' => 'DIREITO', 'MEDICINA' => 'MEDICINA'], $ctx['cursosDisponiveis']);

        // chave inexistente é ignorada e a visão volta a ser de todos
        [$todos] = $this->estatisticas($this->cenario(), ['NAO-EXISTE']);
        $this->assertFalse($todos['filtrando']);
    }

    public function test_grafias_do_mesmo_curso_viram_um_so(): void
    {
        $avaliacao = $this->avaliacao('2026/1 - Diagnóstico', '2026-03-10', [
            ['EDUCAÇÃO FÍSICA', '2º', 8], ['EDUCACAO FISICA', '2º', 6],
        ]);

        [, $est] = $this->estatisticas($avaliacao);

        $this->assertCount(1, $est['cursos']);
        $this->assertSame(2, array_values($est['cursos'])[0]['n']);
        $this->assertSame('EDUCAÇÃO FÍSICA', array_values($est['cursos'])[0]['nome']);
    }

    public function test_sem_matricula_importada_os_previstos_sao_os_resultados(): void
    {
        $avaliacao = $this->avaliacao('2026/1 - Diagnóstico', '2026-03-10', [['DIREITO', '1º', 5], ['DIREITO', '1º', null]], null, false);
        // sem matrícula não há como saber o curso na época da prova: o curso vem do cadastro do aluno
        [, $est] = $this->estatisticas($avaliacao);

        $direito = $est['cursos']['DIREITO'];
        $this->assertSame(2, $direito['previstos']);
        $this->assertSame('resultados', $direito['fontePrevistos']);
        $this->assertTrue($est['previstosPelosResultados']);
    }

    public function test_avaliacao_anulada_nao_aparece_para_o_reitor(): void
    {
        $avaliacao = $this->cenario();
        $avaliacao->update(['status' => Avaliacao::STATUS_ANULADA]);

        $ctx = app(ReitorDashboardService::class)->contexto(null, []);

        $this->assertTrue($ctx['semResultados']);
        $this->actingAs($this->reitor(), 'admin')->get('/reitoria')->assertOk()->assertSee('Ainda não há resultados');
    }

    public function test_painel_sem_nenhum_resultado_mostra_aviso_em_todas_as_telas(): void
    {
        $reitor = $this->reitor();

        foreach (['reitor.visao', 'reitor.desempenho', 'reitor.trajetoria', 'reitor.competencias', 'reitor.evolucao'] as $rota) {
            $this->actingAs($reitor, 'admin')->get(route($rota))->assertOk()->assertSee('Ainda não há resultados');
        }
        $this->actingAs($reitor, 'admin')->get(route('reitor.xlsx'))->assertNotFound();
    }

    public function test_tela_de_visao_mostra_participacao_proficiencia_e_pontos_de_atencao(): void
    {
        $avaliacao = $this->cenario();

        $this->actingAs($this->reitor(), 'admin')->get(route('reitor.visao', ['avaliacao' => $avaliacao->codigo]))
            ->assertOk()
            ->assertSee('Participação por curso')
            ->assertSee('66,7%')
            ->assertSee('Cursos na meta de participação')
            ->assertSee('Ativos sem aplicação')
            ->assertSee('Critério interno da instituição');
    }

    public function test_avaliacao_inexistente_cai_na_mais_recente_em_vez_de_quebrar(): void
    {
        $this->cenario();

        $this->actingAs($this->reitor(), 'admin')->get(route('reitor.visao', ['avaliacao' => 99999, 'cursos' => ['x', ['y']]]))->assertOk();
    }

    public function test_exportacao_xlsx_funciona_e_e_auditada(): void
    {
        $avaliacao = $this->cenario();
        $reitor = $this->reitor();

        $resposta = $this->actingAs($reitor, 'admin')->get(route('reitor.xlsx', ['avaliacao' => $avaliacao->codigo]));

        $resposta->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $resposta->headers->get('content-type'));
        $this->assertTrue(Atividade::where('acao', 'reitor.painel_exportado')->where('admin_id', $reitor->id)->exists());
    }

    // ------------------------------------------------------------------------------------------------------------
    // Filtros: período letivo, categoria ("todas" por padrão) e avaliação (em geral cada avaliação é de um curso)
    // ------------------------------------------------------------------------------------------------------------

    /** Duas avaliações da MESMA categoria e semestre, uma por curso (como acontece na prática). */
    private function categoriaPorCurso(string $periodo = '2026/1', string $data = '2026-03-10'): array
    {
        $categoria = Categoria::firstOrCreate(['nome' => 'Diagnóstico Institucional']);
        $direito = $this->avaliacao("$periodo - Diagnóstico Direito", $data, [['DIREITO', '3º', 10], ['DIREITO', '3º', 7], ['DIREITO', '3º', 6], ['DIREITO', '3º', 3], ['DIREITO', '3º', null]], $categoria->id);
        $medicina = $this->avaliacao("$periodo - Diagnóstico Medicina", $data, [['MEDICINA', '3º', 8], ['MEDICINA', '3º', 5]], $categoria->id);

        return [$categoria, $direito, $medicina];
    }

    public function test_por_padrao_vale_o_periodo_mais_recente_com_todas_as_categorias_e_avaliacoes(): void
    {
        $this->categoriaPorCurso('2025/2', '2025-10-10');
        [$categoria, $direito, $medicina] = $this->categoriaPorCurso();

        $ctx = app(ReitorDashboardService::class)->contexto(null, []);

        $this->assertSame('2026/1', $ctx['avaliacao']['periodoLetivo']);
        $this->assertSame('', $ctx['filtro']['categoria']);
        $this->assertSame('', $ctx['filtro']['avaliacao']);
        $this->assertEqualsCanonicalizing([$direito->codigo, $medicina->codigo], $ctx['avaliacao']['codigos']);
        $this->assertFalse($ctx['avaliacao']['mistura']); // uma categoria só
        $this->assertSame(['2026/1', '2025/2'], $ctx['periodos']);
        $this->assertSame([(string) $categoria->id => ['rotulo' => 'Diagnóstico Institucional', 'caminho' => 'Diagnóstico Institucional', 'nivel' => 0, 'pai' => null, 'avaliacoes' => 2, 'participantes' => 6, 'totalAvaliacoes' => 4]], $ctx['categoriasDoPeriodo']);
    }

    public function test_categoria_reune_as_avaliacoes_de_todos_os_cursos(): void
    {
        [$categoria] = $this->categoriaPorCurso();
        $servico = app(ReitorDashboardService::class);

        $ctx = $servico->contexto(['categoria' => (string) $categoria->id], []);
        $est = $servico->estatisticas($ctx);

        $this->assertSame(['DIREITO' => 'DIREITO', 'MEDICINA' => 'MEDICINA'], $ctx['cursosDisponiveis']);
        $this->assertSame(66.7, $est['total']['proficienciaPct']);
        $this->assertSame(6, $est['total']['n']);
        $this->assertSame(65.0, $est['total']['media']);
        $this->assertSame(85.7, $est['total']['participacao']); // 6 fizeram de 7 com matrícula (1 ausente)
        $this->assertSame(75.0, $est['cursos']['DIREITO']['proficienciaPct']);
        $this->assertSame(50.0, $est['cursos']['MEDICINA']['proficienciaPct']);
    }

    public function test_avaliacao_escolhida_mostra_so_o_curso_dela_e_o_codigo_solto_tambem_vale(): void
    {
        [, $direito] = $this->categoriaPorCurso();
        $servico = app(ReitorDashboardService::class);

        $ctx = $servico->contexto(['avaliacao' => (string) $direito->codigo], []);

        $this->assertTrue($ctx['avaliacao']['avulsa']);
        $this->assertSame([$direito->codigo], $ctx['avaliacao']['codigos']);
        $this->assertSame(['DIREITO' => 'DIREITO'], $ctx['cursosDisponiveis']);
        $this->assertSame((string) $direito->codigo, $ctx['filtro']['avaliacao']);
        $this->assertSame([$direito->codigo], $servico->contexto($direito->codigo, [])['avaliacao']['codigos']);
    }

    public function test_avaliacao_de_outra_categoria_ou_periodo_nao_vale_no_filtro_pedido(): void
    {
        [$categoria] = $this->categoriaPorCurso();
        $outra = $this->avaliacao('2026/1 - Simulado', '2026-04-10', [['ENFERMAGEM', '3º', 5]], Categoria::create(['nome' => 'Simulado'])->id);
        $servico = app(ReitorDashboardService::class);

        // pedir a avaliação do Simulado com a categoria Diagnóstico: a avaliação manda (define a própria categoria)
        $ctx = $servico->contexto(['categoria' => (string) $categoria->id, 'avaliacao' => (string) $outra->codigo], []);
        $this->assertSame([$outra->codigo], $ctx['avaliacao']['codigos']);
        $this->assertSame((string) $outra->categoria_id, $ctx['filtro']['categoria']);

        // categoria que não existe no período volta para "todas"
        $this->assertSame('', $servico->contexto(['categoria' => '9999'], [])['filtro']['categoria']);
    }

    public function test_categoria_todas_mistura_categorias_diferentes_e_avisa(): void
    {
        $this->avaliacao('2026/1 - Tipo X', '2026-03-10', [['ENFERMAGEM', '3º', 5]], Categoria::create(['nome' => 'X'])->id);
        $this->avaliacao('2026/1 - Tipo Y', '2026-03-10', [['PSICOLOGIA', '3º', 5]], Categoria::create(['nome' => 'Y'])->id);
        $this->avaliacao('2026/1 - Sem categoria', '2026-03-10', [['DIREITO', '3º', 5]]);
        $servico = app(ReitorDashboardService::class);

        $ctx = $servico->contexto(null, []);
        $this->assertTrue($ctx['avaliacao']['mistura']);
        $this->assertCount(3, $ctx['cursosDisponiveis']);
        $this->assertArrayHasKey('0', $ctx['categoriasDoPeriodo']); // "Sem categoria" é uma opção

        $this->actingAs($this->reitor(), 'admin')->get(route('reitor.visao'))->assertOk()->assertSee('categorias diferentes');

        // escolhendo uma categoria o aviso some
        $x = Categoria::where('nome', 'X')->first();
        $this->assertFalse($servico->contexto(['categoria' => (string) $x->id], [])['avaliacao']['mistura']);
        $this->assertSame(['ENFERMAGEM'], array_keys($servico->contexto(['categoria' => (string) $x->id], [])['cursosDisponiveis']));
    }

    public function test_barra_de_filtros_tem_tres_campos_e_a_avaliacao_so_lista_as_da_categoria(): void
    {
        [$categoria] = $this->categoriaPorCurso();
        $this->avaliacao('2026/1 - Simulado Enfermagem', '2026-04-10', [['ENFERMAGEM', '3º', 5]], Categoria::create(['nome' => 'Simulado'])->id);
        $reitor = $this->reitor();

        $todas = $this->actingAs($reitor, 'admin')->get(route('reitor.visao'))->assertOk()->getContent();
        $this->assertStringContainsString('Todas as categorias', $todas);
        $this->assertStringContainsString('name="periodo"', $todas);
        $this->assertStringContainsString('name="categoria" value=""', $todas);
        $this->assertStringContainsString('name="avaliacao" value=""', $todas);
        $this->assertStringContainsString('Diagnóstico Direito', $todas);
        $this->assertStringContainsString('Simulado Enfermagem', $todas);

        $filtrada = $this->actingAs($reitor, 'admin')->get(route('reitor.visao', ['categoria' => $categoria->id]))->assertOk()->getContent();
        $this->assertStringContainsString('Todas as avaliações (2)', $filtrada);
        $this->assertStringContainsString('2026/1 - Diagnóstico Direito', $filtrada);
        $this->assertStringNotContainsString('Simulado Enfermagem — ', $filtrada);
    }

    public function test_telas_mantem_o_recorte_nos_links_das_abas(): void
    {
        [$categoria] = $this->categoriaPorCurso();
        $reitor = $this->reitor();

        foreach (['reitor.visao', 'reitor.desempenho', 'reitor.trajetoria', 'reitor.competencias', 'reitor.evolucao'] as $rota) {
            $this->actingAs($reitor, 'admin')->get(route($rota, ['periodo' => '2026/1', 'categoria' => $categoria->id]))
                ->assertOk()
                ->assertSee('Diagnóstico Institucional · 2 avaliações', false)
                ->assertSee('categoria='.$categoria->id, false);
        }
    }

    public function test_evolucao_da_categoria_soma_as_avaliacoes_de_cada_semestre(): void
    {
        [$categoria] = $this->categoriaPorCurso('2026/1', '2026-03-10');
        $this->avaliacao('2025/2 - Diagnóstico Direito', '2025-10-10', [['DIREITO', '3º', 10], ['DIREITO', '3º', 10]], $categoria->id);
        $this->avaliacao('2025/2 - Diagnóstico Medicina', '2025-10-10', [['MEDICINA', '3º', 2], ['MEDICINA', '3º', 2]], $categoria->id);

        $servico = app(ReitorDashboardService::class);
        $serie = app(ReitorEvolucaoService::class)->serie($servico->contexto(['periodo' => '2026/1', 'categoria' => (string) $categoria->id], []));

        $this->assertSame(['2025/2', '2026/1'], array_column($serie['semestres'], 'periodoLetivo'));
        // 2025/2: 100, 100, 20, 20 → média 60 e metade proficiente; 2026/1: o mesmo cenário de sempre
        $this->assertSame(60.0, $serie['semestres'][0]['total']['media']);
        $this->assertSame(50.0, $serie['semestres'][0]['total']['proficienciaPct']);
        $this->assertSame(65.0, $serie['semestres'][1]['total']['media']);
        $this->assertEqualsCanonicalizing(['DIREITO', 'MEDICINA'], array_keys($serie['semestres'][1]['cursos']));
        $this->assertCount(2, $serie['semestres'][0]['avaliacao']['codigos']);
    }

    public function test_competencias_e_exportacao_funcionam_com_a_categoria(): void
    {
        [$categoria] = $this->categoriaPorCurso();
        $servico = app(ReitorDashboardService::class);

        $c = app(ReitorCompetenciasService::class)->gerar($servico->contexto(['categoria' => (string) $categoria->id], []));
        $this->assertSame(93.3, $c['bloom']['total']['lembrar']['pct']);
        $this->assertSame(36.7, $c['bloom']['total']['aplicar']['pct']);

        $this->actingAs($this->reitor(), 'admin')->get(route('reitor.xlsx', ['categoria' => $categoria->id]))->assertOk();
    }

    // ------------------------------------------------------------------------------------------------------------
    // Categorias em árvore: "Diagnóstico Institucional (DI) › Direito", "DI › Medicina"...
    // ------------------------------------------------------------------------------------------------------------

    /** O Diagnóstico como no cadastro real: uma categoria PAI e uma FILHA por curso, cada avaliação na filha do curso. */
    private function diagnosticoEmArvore(string $periodo = '2026/1', string $data = '2026-03-10'): array
    {
        $pai = Categoria::firstOrCreate(['nome' => 'Diagnóstico Institucional (DI)']);
        $filhaDireito = Categoria::firstOrCreate(['nome' => 'Direito', 'categoria_pai_id' => $pai->id]);
        $filhaMedicina = Categoria::firstOrCreate(['nome' => 'Medicina', 'categoria_pai_id' => $pai->id]);

        $direito = $this->avaliacao("$periodo - DI - Direito", $data, [['DIREITO', '3º', 10], ['DIREITO', '3º', 7], ['DIREITO', '3º', 6], ['DIREITO', '3º', 3], ['DIREITO', '3º', null]], $filhaDireito->id);
        $medicina = $this->avaliacao("$periodo - DI - Medicina", $data, [['MEDICINA', '3º', 8], ['MEDICINA', '3º', 5]], $filhaMedicina->id);

        return [$pai, $filhaDireito, $filhaMedicina, $direito, $medicina];
    }

    public function test_escolher_a_categoria_pai_reune_as_avaliacoes_das_filhas(): void
    {
        [$pai, $filhaDireito, , $direito, $medicina] = $this->diagnosticoEmArvore();
        $servico = app(ReitorDashboardService::class);

        $ctx = $servico->contexto(['categoria' => (string) $pai->id], []);
        $est = $servico->estatisticas($ctx);

        $this->assertEqualsCanonicalizing([$direito->codigo, $medicina->codigo], $ctx['avaliacao']['codigos']);
        $this->assertSame(['DIREITO', 'MEDICINA'], array_keys($ctx['cursosDisponiveis']));
        $this->assertSame(6, $est['total']['n']);
        $this->assertSame(66.7, $est['total']['proficienciaPct']);
        $this->assertFalse($ctx['avaliacao']['mistura']);

        // a filha continua escolhível e mostra só o curso dela
        $ctxFilha = $servico->contexto(['categoria' => (string) $filhaDireito->id], []);
        $this->assertSame([$direito->codigo], $ctxFilha['avaliacao']['codigos']);
    }

    public function test_seletor_de_categoria_mostra_o_pai_com_o_total_e_as_filhas_indentadas(): void
    {
        [$pai, $filhaDireito] = $this->diagnosticoEmArvore();
        $ctx = app(ReitorDashboardService::class)->contexto(null, []);
        $opcoes = $ctx['categoriasDoPeriodo'];

        $this->assertSame([(string) $pai->id, (string) $filhaDireito->id, (string) Categoria::where('nome', 'Medicina')->value('id')], array_map('strval', array_keys($opcoes)));
        $this->assertSame(['rotulo' => 'Diagnóstico Institucional (DI)', 'caminho' => 'Diagnóstico Institucional (DI)', 'nivel' => 0, 'pai' => null, 'avaliacoes' => 2, 'participantes' => 6, 'totalAvaliacoes' => 2], $opcoes[(string) $pai->id]);
        $this->assertSame(1, $opcoes[(string) $filhaDireito->id]['nivel']);
        $this->assertSame((string) $pai->id, $opcoes[(string) $filhaDireito->id]['pai']);
        $this->assertSame(1, $opcoes[(string) $filhaDireito->id]['avaliacoes']);

        // "Todas": as filhas do mesmo pai são a mesma prova — sem aviso de mistura
        $this->assertFalse($ctx['avaliacao']['mistura']);
    }

    public function test_pai_junto_de_outra_familia_de_categoria_continua_avisando_a_mistura(): void
    {
        $this->diagnosticoEmArvore();
        $this->avaliacao('2026/1 - Simulado', '2026-04-10', [['ENFERMAGEM', '3º', 5]], Categoria::create(['nome' => 'Simulado MedCof'])->id);

        $ctx = app(ReitorDashboardService::class)->contexto(null, []);

        $this->assertTrue($ctx['avaliacao']['mistura']);
        $this->assertCount(3, $ctx['cursosDisponiveis']);
    }

    public function test_escolher_uma_avaliacao_da_filha_mantem_o_pai_escolhido(): void
    {
        [$pai, , , , $medicina] = $this->diagnosticoEmArvore();

        $ctx = app(ReitorDashboardService::class)->contexto(['categoria' => (string) $pai->id, 'avaliacao' => (string) $medicina->codigo], []);

        $this->assertSame((string) $pai->id, $ctx['filtro']['categoria']);
        $this->assertSame((string) $medicina->codigo, $ctx['filtro']['avaliacao']);
        $this->assertSame([$medicina->codigo], $ctx['avaliacao']['codigos']);
        // e o campo Avaliação lista as de TODAS as filhas do pai
        $this->assertCount(2, $ctx['avaliacoesDaCategoria']);
    }

    public function test_evolucao_pela_categoria_pai_soma_as_filhas_em_cada_semestre(): void
    {
        [$pai] = $this->diagnosticoEmArvore();
        $this->diagnosticoEmArvore('2025/2', '2025-10-10');

        $servico = app(ReitorDashboardService::class);
        $serie = app(ReitorEvolucaoService::class)->serie($servico->contexto(['periodo' => '2026/1', 'categoria' => (string) $pai->id], []));

        $this->assertSame(['2025/2', '2026/1'], array_column($serie['semestres'], 'periodoLetivo'));
        $this->assertCount(2, $serie['semestres'][0]['avaliacao']['codigos']);
        $this->assertEqualsCanonicalizing(['DIREITO', 'MEDICINA'], array_keys($serie['semestres'][0]['cursos']));
    }

    public function test_filhas_ficam_logo_abaixo_do_pai_e_nao_no_fim_da_lista(): void
    {
        [$pai] = $this->diagnosticoEmArvore();
        // outras categorias que, por nome, ficam antes e depois do pai
        $this->avaliacao('2026/1 - Disciplina', '2026-03-10', [['DIREITO', '3º', 5]], Categoria::create(['nome' => 'Disciplinas'])->id);
        $this->avaliacao('2026/1 - Simulado', '2026-03-10', [['DIREITO', '3º', 5]], Categoria::create(['nome' => 'Simulado MedCof'])->id);

        $caminhos = array_column(app(ReitorDashboardService::class)->contexto(null, [])['categoriasDoPeriodo'], 'caminho');

        $this->assertSame([
            'Diagnóstico Institucional (DI)',
            'Diagnóstico Institucional (DI) › Direito',
            'Diagnóstico Institucional (DI) › Medicina',
            'Disciplinas',
            'Simulado MedCof',
        ], array_values($caminhos));
        $this->assertSame((string) $pai->id, (string) array_key_first(app(ReitorDashboardService::class)->contexto(null, [])['categoriasDoPeriodo']));
    }

    public function test_seletores_com_busca_trazem_a_arvore_de_categorias_e_as_avaliacoes(): void
    {
        [$pai, $filhaDireito] = $this->diagnosticoEmArvore();

        $html = $this->actingAs($this->reitor(), 'admin')->get(route('reitor.visao'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'role="combobox"')); // categoria e avaliação
        $this->assertStringContainsString('placeholder="Buscar categoria..."', $html);
        $this->assertStringContainsString('placeholder="Buscar avaliação ou curso..."', $html);
        $this->assertStringContainsString('Selecionado', $html);
        // a filha aponta para o pai (a árvore é montada no navegador a partir de data-pai)
        $this->assertMatchesRegularExpression('/data-valor="'.$filhaDireito->id.'"[^>]*data-pai="'.$pai->id.'"/', $html);
        $this->assertStringContainsString('Todas as avaliações (2)', $html);
        $this->assertStringContainsString('data-limpa="avaliacao"', $html);
        $this->assertStringContainsString('data-cursos-busca', $html);
    }

    // ------------------------------------------------------------------------------------------------------------
    // "Todos os períodos": uma categoria com avaliações em vários semestres (os simulados, por exemplo)
    // ------------------------------------------------------------------------------------------------------------

    /** Simulado MedCof: 2 avaliações em 2026/1 e 1 em 2026/2, todas do mesmo curso. */
    private function simulados(): array
    {
        $categoria = Categoria::create(['nome' => 'Simulado MedCof']);
        $s1 = $this->avaliacao('Simulado 01', '2026-04-01', [['DIREITO', '3º', 10], ['DIREITO', '3º', 4]], $categoria->id);
        $s2 = $this->avaliacao('Simulado 02', '2026-05-27', [['DIREITO', '3º', 8], ['DIREITO', '3º', 6]], $categoria->id);
        $s3 = $this->avaliacao('Simulado 03', '2026-08-01', [['DIREITO', '3º', 2], ['DIREITO', '3º', 6]], $categoria->id);

        return [$categoria, $s1, $s2, $s3];
    }

    public function test_por_periodo_a_categoria_mostra_so_as_avaliacoes_daquele_semestre(): void
    {
        [$categoria] = $this->simulados();
        $servico = app(ReitorDashboardService::class);

        $ctx = $servico->contexto(['categoria' => (string) $categoria->id], []);

        $this->assertSame('2026/2', $ctx['avaliacao']['periodoLetivo']);
        $this->assertSame(1, $ctx['categoriasDoPeriodo'][(string) $categoria->id]['avaliacoes']);
        $this->assertFalse($ctx['avaliacao']['todosPeriodos']);
    }

    public function test_seletor_e_aviso_dizem_que_ha_mais_avaliacoes_em_outros_periodos(): void
    {
        [$categoria] = $this->simulados();
        $reitor = $this->reitor();

        $ctx = app(ReitorDashboardService::class)->contexto(['categoria' => (string) $categoria->id], []);
        $this->assertSame(1, $ctx['categoriasDoPeriodo'][(string) $categoria->id]['avaliacoes']);
        $this->assertSame(3, $ctx['categoriasDoPeriodo'][(string) $categoria->id]['totalAvaliacoes']);
        $this->assertSame(2, $ctx['avaliacao']['emOutrosPeriodos']);

        $html = $this->actingAs($reitor, 'admin')->get(route('reitor.visao', ['categoria' => $categoria->id]))->assertOk()->getContent();
        $this->assertStringContainsString('esta categoria tem mais <strong>2</strong> avaliações em outros períodos letivos', $html);
        $this->assertStringContainsString('1 de 3', $html);
        $this->assertStringContainsString('Ver todos os períodos', $html);

        // sem aviso quando já são todos os períodos
        $todos = $this->actingAs($reitor, 'admin')->get(route('reitor.visao', ['periodo' => '*', 'categoria' => $categoria->id]))->getContent();
        $this->assertStringNotContainsString('em outros períodos letivos', $todos);
    }

    public function test_todos_os_periodos_somam_as_avaliacoes_da_categoria_de_todos_os_semestres(): void
    {
        [$categoria, $s1, $s2, $s3] = $this->simulados();
        $servico = app(ReitorDashboardService::class);

        $ctx = $servico->contexto(['periodo' => '*', 'categoria' => (string) $categoria->id], []);
        $est = $servico->estatisticas($ctx);

        $this->assertTrue($ctx['avaliacao']['todosPeriodos']);
        $this->assertSame('*', $ctx['filtro']['periodo']);
        $this->assertSame(3, $ctx['categoriasDoPeriodo'][(string) $categoria->id]['avaliacoes']);
        $this->assertEqualsCanonicalizing([$s1->codigo, $s2->codigo, $s3->codigo], $ctx['avaliacao']['codigos']);
        $this->assertCount(3, $ctx['avaliacoesDaCategoria']);

        // 6 participações (2 por avaliação); os previstos de cada semestre vêm das matrículas daquele semestre
        $this->assertSame(6, $est['total']['n']);
        $this->assertSame(6, $est['total']['fizeram']);
        $this->assertSame(6, $est['total']['previstos']);
        $this->assertSame(100.0, $est['total']['participacao']);
        $this->assertSame(['DIREITO'], array_keys($est['cursos']));
        // 100, 40, 80, 60, 20, 60 → média 60, proficientes (≥60): 100, 80, 60, 60
        $this->assertSame(60.0, $est['total']['media']);
        $this->assertSame(66.7, $est['total']['proficienciaPct']);
    }

    public function test_todos_os_periodos_nas_cinco_telas_e_na_evolucao(): void
    {
        [$categoria] = $this->simulados();
        $reitor = $this->reitor();
        $parametros = ['periodo' => '*', 'categoria' => $categoria->id];

        foreach (['reitor.visao', 'reitor.desempenho', 'reitor.trajetoria', 'reitor.competencias', 'reitor.evolucao'] as $rota) {
            $this->actingAs($reitor, 'admin')->get(route($rota, $parametros))
                ->assertOk()
                ->assertSee('todos os períodos letivos')
                ->assertSee('Somando');
        }

        $serie = app(ReitorEvolucaoService::class)->serie(app(ReitorDashboardService::class)->contexto($parametros + ['categoria' => (string) $categoria->id], []));
        $this->assertSame(['2026/1', '2026/2'], array_column($serie['semestres'], 'periodoLetivo'));
        $this->assertSame([false, false], array_column($serie['semestres'], 'ehSelecionada'));
        $this->assertCount(2, $serie['semestres'][0]['avaliacao']['codigos']);

        $this->actingAs($reitor, 'admin')->get(route('reitor.xlsx', $parametros))->assertOk();
    }

    public function test_seletor_de_periodo_oferece_todos_os_periodos_e_a_regra_de_hidden_esta_na_pagina(): void
    {
        $this->simulados();

        $html = $this->actingAs($this->reitor(), 'admin')->get(route('reitor.visao'))->assertOk()->getContent();

        $this->assertStringContainsString('<option value="*"', $html);
        // `hidden` perde para classes de display do Tailwind: a regra garante que o recolher/expandir funcione
        $this->assertStringContainsString('[hidden] { display: none !important; }', $html);
        // o <main> posicionado impede que os `sr-only` (legendas de tabela) estiquem a rolagem da página
        $this->assertMatchesRegularExpression('/<main[^>]*id="conteudo-principal"[^>]*class="relative /', $html);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Visão do coordenador de UM curso (para o reitor analisar a fundo)
    // ------------------------------------------------------------------------------------------------------------

    public function test_reitor_abre_a_visao_do_coordenador_de_um_curso_e_so_enxerga_aquele_curso(): void
    {
        [, $direito, $medicina] = $this->categoriaPorCurso();
        $reitor = $this->reitor();

        $this->actingAs($reitor, 'admin')->get(route('reitor.curso.abrir', ['curso' => 'DIREITO']))->assertRedirect(route('coordenador.painel'));
        $this->assertTrue(Atividade::where('acao', 'reitor.visao_de_curso')->where('admin_id', $reitor->id)->exists());

        // painel do curso, com o aviso de que é a visão do coordenador
        $this->actingAs($reitor, 'admin')->get(route('coordenador.painel'))
            ->assertOk()
            ->assertSee('Você está vendo')
            ->assertSee('como o coordenador')
            ->assertSee('Voltar ao painel da reitoria')
            ->assertSee('Reitor (visão do curso)');

        // alunos: só os do curso escolhido (nome do aluno de Direito aparece; o de Medicina não)
        $alunoDireito = Aluno::where('curso', 'DIREITO')->first();
        $alunoMedicina = Aluno::where('curso', 'MEDICINA')->first();
        $this->actingAs($reitor, 'admin')->get(route('coordenador.alunos'))
            ->assertOk()->assertSee($alunoDireito->nome)->assertDontSee($alunoMedicina->nome);
        $this->actingAs($reitor, 'admin')->get(route('coordenador.alunos.show', $alunoDireito))->assertOk();
        $this->actingAs($reitor, 'admin')->get(route('coordenador.alunos.show', $alunoMedicina))->assertNotFound();

        // avaliações e Dashboard: só os do curso
        $this->actingAs($reitor, 'admin')->get('/avaliacoes')->assertOk()->assertSee('Diagnóstico Direito')->assertDontSee('Diagnóstico Medicina');
        $this->actingAs($reitor, 'admin')->get("/avaliacoes/{$direito->codigo}/bi")->assertOk();
        $this->actingAs($reitor, 'admin')->get("/avaliacoes/{$medicina->codigo}/bi")->assertNotFound();

        // as outras telas do coordenador
        $this->actingAs($reitor, 'admin')->get(route('coordenador.desempenho'))->assertOk();
        $this->actingAs($reitor, 'admin')->get(route('coordenador.comparativo'))->assertOk();
    }

    public function test_drill_down_abre_o_curso_na_tela_e_no_recorte_clicado(): void
    {
        [, $direito] = $this->categoriaPorCurso();
        $reitor = $this->reitor();

        $this->actingAs($reitor, 'admin')->get(route('reitor.curso.abrir', ['curso' => 'DIREITO', 'destino' => 'alunos', 'periodo_letivo' => '2026/1', 'periodo_curso' => '3']))
            ->assertRedirect(route('coordenador.alunos', ['periodo_letivo' => '2026/1', 'periodo_curso' => '3']));
        $this->actingAs($reitor, 'admin')->get(route('reitor.curso.abrir', ['curso' => 'DIREITO', 'destino' => 'desempenho', 'periodo_letivo' => '2026/1', 'periodo_curso' => '3']))
            ->assertRedirect(route('coordenador.desempenho', ['periodo_letivo' => '2026/1']));
        $this->actingAs($reitor, 'admin')->get(route('reitor.curso.abrir', ['curso' => 'DIREITO', 'destino' => 'bi', 'avaliacao' => $direito->codigo]))
            ->assertRedirect(route('avaliacoes.bi', $direito->codigo));
        $this->actingAs($reitor, 'admin')->get(route('reitor.curso.abrir', ['curso' => 'DIREITO', 'destino' => 'comparativo']))
            ->assertRedirect(route('coordenador.comparativo'));

        // valores estranhos não são repassados
        $this->actingAs($reitor, 'admin')->get(route('reitor.curso.abrir', ['curso' => 'DIREITO', 'periodo_letivo' => 'x"><script>', 'destino' => 'http://evil.test']))
            ->assertRedirect(route('coordenador.painel'));
    }

    public function test_nomes_de_curso_e_graficos_levam_ao_drill_down_so_para_o_reitor(): void
    {
        $this->categoriaPorCurso();
        $reitor = $this->reitor();

        foreach (['reitor.visao', 'reitor.desempenho', 'reitor.trajetoria', 'reitor.competencias', 'reitor.evolucao'] as $rota) {
            $html = $this->actingAs($reitor, 'admin')->get(route($rota, ['categoria' => Categoria::first()->id]))->assertOk()->getContent();
            $this->assertStringContainsString('window.ReitorDrill = {', $html, $rota);
        }
        $this->assertStringContainsString(route('reitor.curso.abrir'), $this->actingAs($reitor, 'admin')->get(route('reitor.desempenho'))->getContent());

        $admin = $this->admin();
        $htmlAdmin = $this->actingAs($admin, 'admin')->get(route('reitor.desempenho'))->getContent();
        $this->assertStringNotContainsString('window.ReitorDrill = {', $htmlAdmin);
        $this->assertStringNotContainsString('title="Abrir a análise de', $htmlAdmin);
    }

    public function test_pontos_de_atencao_apontam_para_o_quadro_de_origem(): void
    {
        $this->cenario();

        $html = $this->actingAs($this->reitor(), 'admin')->get(route('reitor.visao'))->assertOk()->getContent();

        $this->assertStringContainsString('Ver participação por curso', $html);
        $this->assertStringContainsString('#secao-participacao', $html);
    }

    public function test_visao_de_curso_recusa_qualquer_escrita(): void
    {
        $this->categoriaPorCurso();
        $reitor = $this->reitor();
        $this->actingAs($reitor, 'admin')->get(route('reitor.curso.abrir', ['curso' => 'DIREITO']));

        $this->actingAs($reitor, 'admin')->post('/notificacoes/lidas')->assertForbidden();
    }

    public function test_visao_de_curso_continua_sendo_so_leitura_e_sem_area_de_gestao(): void
    {
        [, $direito] = $this->categoriaPorCurso();
        $reitor = $this->reitor();
        $this->actingAs($reitor, 'admin')->get(route('reitor.curso.abrir', ['curso' => 'DIREITO']));

        $this->actingAs($reitor, 'admin')->get('/usuarios')->assertForbidden();
        $this->actingAs($reitor, 'admin')->get('/alunos')->assertForbidden();
        $this->actingAs($reitor, 'admin')->get("/avaliacoes/{$direito->codigo}/respondentes")->assertForbidden();
        $this->actingAs($reitor, 'admin')->delete("/avaliacoes/{$direito->codigo}")->assertForbidden();
        $this->actingAs($reitor, 'admin')->get('/sistema/configuracoes')->assertForbidden();
        // nada disso grava o perfil de coordenador no banco
        $this->assertSame(Admin::ROLE_REITOR, $reitor->fresh()->role);
    }

    public function test_voltar_a_reitoria_encerra_a_visao_do_curso(): void
    {
        $this->categoriaPorCurso();
        $reitor = $this->reitor();
        $this->actingAs($reitor, 'admin')->get(route('reitor.curso.abrir', ['curso' => 'DIREITO']));

        $this->actingAs($reitor, 'admin')->get(route('reitor.curso.sair'))->assertRedirect(route('reitor.visao'));
        $this->actingAs($reitor, 'admin')->get('/avaliacoes')->assertRedirect(route('reitor.visao'));

        // abrir o painel da reitoria também solta a visão do curso
        $this->actingAs($reitor, 'admin')->get(route('reitor.curso.abrir', ['curso' => 'DIREITO']));
        $this->actingAs($reitor, 'admin')->get(route('reitor.visao'))->assertOk();
        $this->actingAs($reitor, 'admin')->get(route('coordenador.painel'))->assertRedirect(route('reitor.visao'));
    }

    public function test_curso_que_nao_existe_ou_perfil_errado_nao_abre_a_visao(): void
    {
        $this->categoriaPorCurso();

        $reitor = $this->reitor();

        $this->actingAs($reitor, 'admin')->get(route('reitor.curso.abrir', ['curso' => 'NAO EXISTE']))->assertNotFound();
        $this->actingAs($reitor, 'admin')->get(route('reitor.curso.abrir'))->assertNotFound();
        $this->actingAs($this->coordenador(), 'admin')->get(route('reitor.curso.abrir', ['curso' => 'MEDICINA']))->assertForbidden();
        $this->actingAs($this->admin(), 'admin')->get(route('reitor.curso.abrir', ['curso' => 'MEDICINA']))->assertForbidden();
    }

    public function test_chave_do_curso_aceita_acento_e_caixa_diferentes(): void
    {
        $this->avaliacao('2026/1 - Diagnóstico', '2026-03-10', [['EDUCAÇÃO FÍSICA', '2º', 8]]);

        $reitor = $this->reitor();

        $this->actingAs($reitor, 'admin')->get(route('reitor.curso.abrir', ['curso' => 'educacao fisica']))->assertRedirect(route('coordenador.painel'));
        $this->actingAs($reitor, 'admin')->get(route('coordenador.painel'))->assertOk()->assertSee('EDUCAÇÃO FÍSICA');
    }

    public function test_painel_da_reitoria_oferece_entrada_para_cada_curso(): void
    {
        $this->categoriaPorCurso();

        $html = $this->actingAs($this->reitor(), 'admin')->get(route('reitor.visao'))->assertOk()->getContent();

        // menu lateral e aba do cabeçalho
        $this->assertStringContainsString('Análise do curso', $html);
        $this->assertStringContainsString(route('reitor.cursos'), $html);
        // atalho no nome do curso na tabela de participação
        $this->assertStringContainsString('curso=DIREITO', $html);
        $this->assertStringContainsString('visão do coordenador', $html);
        // o administrador vê o painel mas não ganha a entrada (ele já enxerga tudo)
        $admin = $this->admin();
        $htmlAdmin = $this->actingAs($admin, 'admin')->get(route('reitor.visao'))->getContent();
        $this->assertStringNotContainsString('Análise do curso', $htmlAdmin);
        $this->actingAs($admin, 'admin')->get(route('reitor.cursos'))->assertForbidden();
    }

    public function test_pagina_analise_do_curso_lista_os_cursos_com_numeros_e_o_botao_para_abrir(): void
    {
        $this->categoriaPorCurso();
        $reitor = $this->reitor();

        $this->actingAs($reitor, 'admin')->get(route('reitor.cursos'))
            ->assertOk()
            ->assertSee('Análise do curso')
            ->assertSee('DIREITO')
            ->assertSee('MEDICINA')
            ->assertSee('visão do coordenador')
            ->assertSee(route('reitor.curso.abrir', ['curso' => 'DIREITO']), false)
            ->assertDontSee('Fulano Sigiloso');

        // abrir a página também solta uma visão de curso que tenha ficado aberta
        $this->actingAs($reitor, 'admin')->get(route('reitor.curso.abrir', ['curso' => 'DIREITO']));
        $this->actingAs($reitor, 'admin')->get(route('reitor.cursos'))->assertOk();
        $this->actingAs($reitor, 'admin')->get(route('coordenador.painel'))->assertRedirect(route('reitor.visao'));
    }

    public function test_pagina_analise_do_curso_funciona_sem_resultados(): void
    {
        Aluno::create(['ra' => '1', 'nome' => 'X', 'curso' => 'DIREITO']);

        $this->actingAs($this->reitor(), 'admin')->get(route('reitor.cursos'))->assertOk()->assertSee('DIREITO');
    }

    // ------------------------------------------------------------------------------------------------------------
    // Estudantes em risco (agregado): ausência recorrente e baixo desempenho persistente
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Os MESMOS estudantes em várias aplicações (como nos simulados). `$resultados[i]` = acertos (0–10) de cada aluno na
     * aplicação i, ou null para ausente; `$alunos` é a lista de alunos (criados uma vez).
     *
     * @param  array<int, Aluno>  $alunos
     * @param  array<int, array<int, ?int>>  $resultados  aplicação => [índice do aluno => acertos|null]
     * @return array<int, Avaliacao>
     */
    private function aplicacoes(array $alunos, array $resultados, int $categoriaId, string $periodoLetivo, string $datas): array
    {
        $avaliacoes = [];
        foreach ($resultados as $i => $porAluno) {
            $avaliacao = Avaliacao::create(['nome' => "$periodoLetivo - Simulado ".($i + 1), 'data_avaliacao' => $datas, 'categoria_id' => $categoriaId]);
            foreach (range(1, 10) as $n) {
                Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => $n, 'gabarito' => 'A', 'area' => 'Clínica', 'bloom_nivel' => 'Lembrar']);
            }
            foreach ($porAluno as $indice => $acertos) {
                if ($acertos === false) {
                    continue; // não aparece nesta aplicação
                }
                $aluno = $alunos[$indice];
                foreach (range(1, 10) as $n) {
                    Resposta::create([
                        'avaliacao_codigo' => $avaliacao->codigo, 'aluno_id' => $aluno->id, 'ra' => $aluno->ra, 'periodo' => '3º',
                        'questao_numero' => $n, 'resposta' => $acertos === null ? '' : ($n <= $acertos ? 'A' : 'B'),
                    ]);
                }
            }
            app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);
            $avaliacoes[] = $avaliacao;
        }

        return $avaliacoes;
    }

    /** @return array<int, Aluno> seis alunos de DIREITO (3º período) matriculados em $periodoLetivo */
    private function seisAlunos(string $periodoLetivo): array
    {
        return array_map(fn () => $this->aluno('DIREITO', '3º', $periodoLetivo), range(0, 5));
    }

    /**
     * a0 faltou a 2 de 3 (recorrente) · a1 abaixo do critério nas 3 (persistente) · a2 sempre bem · a3 faltou a 1 ·
     * a4 abaixo em 2 mas acima em 1 (não é persistente) · a5 só fez 1 aplicação (não é elegível).
     *
     * @return array<int, array<int, ?int>>
     */
    private function matrizDeRisco(): array
    {
        return [
            [0 => null, 1 => 3, 2 => 8, 3 => 8, 4 => 5, 5 => 5],
            [0 => null, 1 => 4, 2 => 9, 3 => null, 4 => 7, 5 => false],
            [0 => 8, 1 => 2, 2 => 7, 3 => 8, 4 => 4, 5 => false],
        ];
    }

    public function test_risco_conta_ausencia_recorrente_e_baixo_desempenho_persistente_por_curso(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulado']);
        $alunos = $this->seisAlunos('2026/1');
        $this->aplicacoes($alunos, $this->matrizDeRisco(), $categoria->id, '2026/1', '2026-03-10');

        $servico = app(ReitorDashboardService::class);
        $ctx = $servico->contexto(['categoria' => (string) $categoria->id], []);
        $risco = app(ReitorRiscoService::class)->gerar($ctx);
        $direito = $risco['cursos']['DIREITO'];

        $this->assertTrue($risco['temRecorrencia']);
        $this->assertSame(6, $direito['pessoas']);
        $this->assertSame(5, $direito['elegiveis']);        // a5 só tem uma aplicação
        $this->assertSame(1, $direito['recorrente']);       // a0
        $this->assertSame(1, $direito['persistente']);      // a1 (a4 teve uma acima do critério)
        $this->assertSame(2, $direito['risco']);
        $this->assertSame(20.0, $direito['pctRecorrente']);
        $this->assertSame(20.0, $direito['pctPersistente']);
        $this->assertSame(40.0, $direito['pctRisco']);
        $this->assertSame(40.0, $risco['total']['pctRisco']);
        $this->assertNull($risco['semestreAnterior']);
        $this->assertSame([3], $risco['periodos']);
        $this->assertSame(40.0, $direito['periodos'][3]['pctRisco']);
    }

    public function test_grupo_pequeno_nao_mostra_percentual(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulado']);
        $alunos = $this->seisAlunos('2026/1');
        // só 3 alunos têm duas aplicações: abaixo do mínimo de elegíveis
        $this->aplicacoes($alunos, [[0 => null, 1 => 3, 2 => 8, 3 => false, 4 => false, 5 => false], [0 => null, 1 => 4, 2 => 9, 3 => false, 4 => false, 5 => false]], $categoria->id, '2026/1', '2026-03-10');

        $risco = app(ReitorRiscoService::class)->gerar(app(ReitorDashboardService::class)->contexto(['categoria' => (string) $categoria->id], []));

        $this->assertFalse($risco['temRecorrencia']);
        $this->assertSame(3, $risco['cursos']['DIREITO']['elegiveis']);
        $this->assertNull($risco['cursos']['DIREITO']['pctRisco']);
        $this->assertNull($risco['total']['pctRisco']);

        $this->actingAs($this->reitor(), 'admin')->get(route('reitor.risco', ['categoria' => $categoria->id]))
            ->assertOk()->assertSee('poucos estudantes com duas ou mais aplicações');
    }

    public function test_risco_com_uma_aplicacao_so_avisa_e_nao_quebra(): void
    {
        $this->cenario();

        $this->actingAs($this->reitor(), 'admin')->get(route('reitor.risco'))
            ->assertOk()->assertSee('Estudantes em risco')->assertSee('Na faixa mais baixa');
    }

    public function test_risco_compara_com_o_semestre_anterior_da_serie(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulado']);
        // semestre anterior: ninguém em risco (todos presentes e bem)
        $antes = $this->seisAlunos('2025/2');
        $this->aplicacoes($antes, [array_fill(0, 6, 9), array_fill(0, 6, 9)], $categoria->id, '2025/2', '2025-10-10');
        $alunos = $this->seisAlunos('2026/1');
        $this->aplicacoes($alunos, $this->matrizDeRisco(), $categoria->id, '2026/1', '2026-03-10');

        $risco = app(ReitorRiscoService::class)->gerar(app(ReitorDashboardService::class)->contexto(['periodo' => '2026/1', 'categoria' => (string) $categoria->id], []));

        $this->assertSame('2025/2', $risco['semestreAnterior']);
        $this->assertSame(0.0, $risco['total']['anterior']['pctRisco']);
        $this->assertSame(40.0, $risco['total']['deltaRisco']);
        $this->assertSame(40.0, $risco['cursos']['DIREITO']['deltaRisco']);
    }

    public function test_tela_de_risco_nao_mostra_nome_de_aluno_e_oferece_o_drill_down(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulado']);
        $alunos = $this->seisAlunos('2026/1');
        $this->aplicacoes($alunos, $this->matrizDeRisco(), $categoria->id, '2026/1', '2026-03-10');

        $html = $this->actingAs($this->reitor(), 'admin')->get(route('reitor.risco', ['categoria' => $categoria->id]))
            ->assertOk()
            ->assertSee('Ausência recorrente')
            ->assertSee('Baixo desempenho persistente')
            ->assertDontSee('Fulano Sigiloso')
            ->getContent();

        $this->assertStringContainsString('situacao=atencao', $html);
        $this->assertStringContainsString('40,0%', $html);
    }

    public function test_drill_down_do_risco_abre_a_lista_de_alunos_em_atencao(): void
    {
        $this->categoriaPorCurso();

        $this->actingAs($this->reitor(), 'admin')->get(route('reitor.curso.abrir', ['curso' => 'DIREITO', 'destino' => 'alunos', 'situacao' => 'atencao']))
            ->assertRedirect(route('coordenador.alunos', ['situacao' => 'atencao', 'ordem' => 'prioridade']));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Análise institucional dos itens
    // ------------------------------------------------------------------------------------------------------------

    /**
     * 40 estudantes (índice = "habilidade") e 10 questões de gabarito A:
     *  Q1, Q5–Q10 acertam os de índice acima de um limiar (bons itens);
     *  Q2 só os 4 melhores acertam (difícil, mas discrimina → lacuna de formação);
     *  Q3 5 acertos espalhados (difícil e não discrimina → problema da questão);
     *  Q4 acertam só os MAIS FRACOS e os fortes marcam B (gabarito suspeito).
     * Quem erra marca B.
     */
    private function avaliacaoDeItens(string $nome = '2026/1 - Itens', ?int $categoriaId = null): Avaliacao
    {
        $avaliacao = Avaliacao::create(['nome' => $nome, 'data_avaliacao' => '2026-03-10', 'categoria_id' => $categoriaId]);
        foreach (range(1, 10) as $n) {
            Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => $n, 'gabarito' => 'A', 'area' => $n <= 5 ? 'Clínica' : 'Ética', 'tema' => "Tema $n"]);
        }

        $limiares = [1 => 6, 5 => 8, 6 => 12, 7 => 16, 8 => 20, 9 => 24, 10 => 28];
        foreach (range(0, 39) as $i) {
            $aluno = $this->aluno('DIREITO', '3º', '2026/1');
            foreach (range(1, 10) as $n) {
                $acertou = match (true) {
                    isset($limiares[$n]) => $i >= $limiares[$n],
                    $n === 2 => $i >= 36,
                    $n === 3 => in_array($i, [5, 15, 25, 35, 38], true),
                    $n === 4 => $i <= 8,
                };
                Resposta::create([
                    'avaliacao_codigo' => $avaliacao->codigo, 'aluno_id' => $aluno->id, 'ra' => $aluno->ra, 'periodo' => '3º',
                    'questao_numero' => $n, 'resposta' => $acertou ? 'A' : 'B',
                ]);
            }
        }
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        return $avaliacao;
    }

    public function test_itens_distingue_problema_da_questao_lacuna_de_formacao_e_gabarito_suspeito(): void
    {
        $avaliacao = $this->avaliacaoDeItens();
        $servico = app(ReitorDashboardService::class);

        $analise = app(ReitorItensService::class)->gerar($servico->contexto(['avaliacao' => (string) $avaliacao->codigo], []));
        $porNumero = collect($analise['itens'])->keyBy('numero');

        $this->assertSame(10, $analise['total']['itens']);
        $this->assertSame('formacao', $porNumero[2]['diagnostico']);   // 10% de acerto, mas separa os fortes dos fracos
        $this->assertSame('questao', $porNumero[3]['diagnostico']);    // 12,5% de acerto e quase não discrimina
        $this->assertSame('gabarito', $porNumero[4]['diagnostico']);   // D negativo e B mais marcada que o gabarito
        $this->assertSame('B', $porNumero[4]['distrator']);
        $this->assertGreaterThan($porNumero[4]['gabaritoPct'], $porNumero[4]['distratorPct']);
        foreach ([1, 5, 6, 7, 8, 9, 10] as $numero) {
            $this->assertNull($porNumero[$numero]['diagnostico'], "questão $numero");
        }

        $this->assertSame(3, $analise['total']['aRevisar']);
        $this->assertSame(30.0, $analise['total']['pctARevisar']);
        $this->assertSame(['gabarito' => 1, 'questao' => 1, 'formacao' => 1, 'fraco' => 0, 'todos_cursos' => 0], $analise['contagem']);
        // do mais urgente (gabarito) para o menos
        $this->assertSame([4, 3, 2], array_column($analise['criticos'], 'numero'));
        // por área: Clínica tem as 3 questões problemáticas (Q2, Q3, Q4) de 5
        $clinica = collect($analise['areas'])->firstWhere('area', 'Clínica');
        $this->assertSame(3, $clinica['aRevisar']);
        $this->assertSame(60.0, $clinica['pctARevisar']);
        $this->assertSame(40, $analise['avaliacoes'][0]['respondentes']);
    }

    public function test_tela_de_itens_mostra_os_diagnosticos_sem_nome_de_aluno(): void
    {
        $avaliacao = $this->avaliacaoDeItens();

        $this->actingAs($this->reitor(), 'admin')->get(route('reitor.itens', ['avaliacao' => $avaliacao->codigo]))
            ->assertOk()
            ->assertSee('Revisar o gabarito')
            ->assertSee('Provável problema da questão')
            ->assertSee('Lacuna de formação')
            ->assertSee('Mapa dos itens')
            ->assertDontSee('Fulano Sigiloso');
    }

    public function test_itens_de_avaliacao_pequena_ou_sem_questoes_nao_quebram(): void
    {
        $this->cenario(); // 7 respondentes: abaixo do mínimo da psicometria

        $analise = app(ReitorItensService::class)->gerar(app(ReitorDashboardService::class)->contexto(null, []));
        $this->assertSame(0, $analise['total']['itens']);

        $this->actingAs($this->reitor(), 'admin')->get(route('reitor.itens'))->assertOk()->assertSee('Nenhum item analisável');
    }

    public function test_itens_com_dois_cursos_na_mesma_avaliacao_mostram_o_acerto_por_curso(): void
    {
        $avaliacao = $this->avaliacaoDeItens();
        // metade dos estudantes passa a ser de outro curso (o curso do resultado é o da matrícula na época da prova)
        $metade = Aluno::orderBy('id')->limit(20)->pluck('id')->all();
        AlunoMatricula::whereIn('aluno_id', $metade)->update(['curso' => 'MEDICINA']);
        Aluno::whereIn('id', $metade)->update(['curso' => 'MEDICINA']);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $analise = app(ReitorItensService::class)->gerar(app(ReitorDashboardService::class)->contexto(['avaliacao' => (string) $avaliacao->codigo], []));

        // por curso só aparece onde o curso tem respondentes suficientes (mínimo da psicometria: 10) — aqui 20 em cada
        $this->assertCount(2, collect($analise['itens'])->firstWhere('numero', 5)['porCurso']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Relatório institucional (PDF / PowerPoint)
    // ------------------------------------------------------------------------------------------------------------

    public function test_relatorio_institucional_traz_capa_resumo_secoes_e_leituras_sem_dado_nominal(): void
    {
        $this->cenario();
        $reitor = $this->reitor();

        $html = $this->actingAs($reitor, 'admin')->get(route('reitor.relatorio'))
            ->assertOk()
            ->assertSee('Relatório institucional')
            ->assertSee('1. Resumo')
            ->assertSee('2. Participação por curso')
            ->assertSee('3. Proficiência institucional')
            ->assertSee('4. Desempenho na prova')
            ->assertSee('5. Trajetória ao longo do curso')
            ->assertSee('6. Competências')
            ->assertSee('Imprimir / salvar como PDF')
            ->assertSee('Baixar PowerPoint')
            ->assertSee('Participação de 75% (6 de 8 previstos)')
            ->assertSee('Gerado por')
            ->assertDontSee('Fulano Sigiloso')
            ->getContent();

        $this->assertStringContainsString('pptxgenjs', $html);
        $this->assertTrue(Atividade::where('acao', 'reitor.relatorio_aberto')->where('admin_id', $reitor->id)->exists());
    }

    public function test_relatorio_mostra_a_evolucao_so_com_dois_semestres_e_acompanha_o_filtro(): void
    {
        [$categoria] = $this->categoriaPorCurso('2026/1', '2026-03-10');
        $reitor = $this->reitor();

        $this->actingAs($reitor, 'admin')->get(route('reitor.relatorio', ['categoria' => $categoria->id]))
            ->assertOk()->assertDontSee('7. Evolução entre semestres');

        $this->categoriaPorCurso('2025/2', '2025-10-10');
        $this->actingAs($reitor, 'admin')->get(route('reitor.relatorio', ['periodo' => '2026/1', 'categoria' => $categoria->id]))
            ->assertOk()->assertSee('7. Evolução entre semestres')->assertSee('Período letivo 2026/1');
    }

    public function test_relatorio_sem_resultados_e_o_botao_no_painel(): void
    {
        $reitor = $this->reitor();
        $this->actingAs($reitor, 'admin')->get(route('reitor.relatorio'))->assertOk()->assertSee('Ainda não há resultados');

        $this->cenario();
        $this->actingAs($reitor, 'admin')->get(route('reitor.visao'))->assertSee('Relatório (PDF / PowerPoint)')->assertSee(route('reitor.relatorio'), false);
        $this->actingAs($this->usuario('coord2', Admin::ROLE_COORDENADOR), 'admin')->get(route('reitor.relatorio'))->assertForbidden();
    }

    public function test_layout_tem_regras_de_impressao_para_esconder_o_menu(): void
    {
        $this->cenario();

        $html = $this->actingAs($this->reitor(), 'admin')->get(route('reitor.visao'))->getContent();

        $this->assertMatchesRegularExpression('/<aside[^>]*id="sidebar"[^>]*print:hidden/', $html);
        $this->assertStringContainsString('print:overflow-visible', $html);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Trajetória, evolução e competências
    // ------------------------------------------------------------------------------------------------------------

    public function test_trajetoria_separa_os_periodos_do_curso(): void
    {
        $avaliacao = $this->avaliacao('2026/1 - Diagnóstico', '2026-03-10', [
            ['DIREITO', '1º', 4], ['DIREITO', '1º', 6], ['DIREITO', '2º', 8], ['DIREITO', '2º', 10],
        ]);

        [, $est] = $this->estatisticas($avaliacao);
        $periodos = $est['cursos']['DIREITO']['periodos'];

        $this->assertSame([1, 2], array_keys($periodos));
        $this->assertSame(50.0, $periodos[1]['media']);
        $this->assertSame(50.0, $periodos[1]['proficienciaPct']);
        $this->assertSame(90.0, $periodos[2]['media']);
        $this->assertSame(100.0, $periodos[2]['proficienciaPct']);
        $this->assertSame('1º, 2º', $est['cursos']['DIREITO']['periodosAvaliadosRotulo']);
    }

    public function test_trajetoria_oferece_minigraficos_e_um_curso_so_com_mais_de_um_curso(): void
    {
        $this->avaliacao('2026/1 - Diagnóstico', '2026-03-10', [['DIREITO', '1º', 4], ['DIREITO', '1º', 6], ['MEDICINA', '2º', 8], ['MEDICINA', '2º', 10]]);
        $reitor = $this->reitor();

        $this->actingAs($reitor, 'admin')->get(route('reitor.trajetoria'))
            ->assertOk()
            ->assertSee('Minigráficos')
            ->assertSee('id="traj-um-curso"', false)
            ->assertSee('id="traj-prof-mini"', false)
            ->assertSee('id="traj-acerto-mini"', false);
    }

    public function test_evolucao_compara_semestres_da_mesma_categoria(): void
    {
        $categoria = Categoria::create(['nome' => 'Diagnóstico']);
        $this->avaliacao('2025/2 - Diagnóstico', '2025-10-10', [['DIREITO', '3º', 4], ['DIREITO', '3º', 6], ['DIREITO', '3º', 8], ['DIREITO', '3º', 10]], $categoria->id);
        $atual = $this->avaliacao('2026/1 - Diagnóstico', '2026-03-10', [['DIREITO', '3º', 6], ['DIREITO', '3º', 7], ['DIREITO', '3º', 8], ['DIREITO', '3º', 9]], $categoria->id);
        // outra categoria: não entra na série
        $this->avaliacao('2026/1 - Prova de outro tipo', '2026-04-10', [['DIREITO', '3º', 1]], Categoria::create(['nome' => 'Outra'])->id);

        $servico = app(ReitorDashboardService::class);
        $ctx = $servico->contexto($atual->codigo, []);
        $serie = app(ReitorEvolucaoService::class)->serie($ctx);

        $this->assertSame(['2025/2', '2026/1'], array_column($serie['semestres'], 'periodoLetivo'));
        $this->assertSame([false, true], array_column($serie['semestres'], 'ehSelecionada'));
        $this->assertSame(70.0, $serie['semestres'][0]['total']['media']);
        $this->assertSame(75.0, $serie['semestres'][1]['total']['media']);
        $this->assertSame(75.0, $serie['semestres'][0]['total']['proficienciaPct']); // 60, 80, 100 de 4
        $this->assertSame(100.0, $serie['semestres'][1]['total']['proficienciaPct']);

        $this->actingAs($this->reitor(), 'admin')->get(route('reitor.evolucao', ['avaliacao' => $atual->codigo]))
            ->assertOk()->assertSee('A instituição ao longo dos semestres')->assertSee('2025/2');
    }

    public function test_evolucao_de_avaliacao_sem_categoria_avisa_que_falta_serie(): void
    {
        $avaliacao = $this->cenario();

        $this->actingAs($this->reitor(), 'admin')->get(route('reitor.evolucao', ['avaliacao' => $avaliacao->codigo]))
            ->assertOk()->assertSee('A evolução aparece quando houver ao menos dois períodos letivos');
    }

    public function test_competencias_por_bloom_e_area_com_proficientes_e_nao_proficientes(): void
    {
        $avaliacao = $this->cenario();
        $ctx = app(ReitorDashboardService::class)->contexto($avaliacao->codigo, []);

        $c = app(ReitorCompetenciasService::class)->gerar($ctx);
        $bloom = $c['bloom'];

        // 6 alunos com nota × 5 questões por nível: Lembrar acertou 28 de 30; Aplicar, 11 de 30
        $this->assertSame(93.3, $bloom['total']['lembrar']['pct']);
        $this->assertSame(36.7, $bloom['total']['aplicar']['pct']);
        $this->assertSame(100.0, $bloom['proficientes']['lembrar']['pct']);
        $this->assertSame(55.0, $bloom['proficientes']['aplicar']['pct']);
        $this->assertSame(80.0, $bloom['naoProficientes']['lembrar']['pct']);
        $this->assertSame(0.0, $bloom['naoProficientes']['aplicar']['pct']);
        // curso × nível com menos de 30 respostas fica oculto
        $this->assertNull($bloom['porCurso']['MEDICINA']['lembrar']['pct']);
        $this->assertNull($bloom['total']['criar']['pct']);

        $areas = collect($c['areas']['ranking'])->keyBy('area');
        $this->assertSame(36.7, $areas['Ética']['pct']);
        $this->assertSame(93.3, $areas['Clínica']['pct']);
        $this->assertSame(['Ética', 'Clínica'], array_column($c['areas']['ranking'], 'area')); // da mais frágil à mais forte

        $this->actingAs($this->reitor(), 'admin')->get(route('reitor.competencias', ['avaliacao' => $avaliacao->codigo]))
            ->assertOk()->assertSee('Acerto por nível cognitivo')->assertSee('Ética');
    }

    public function test_nivel_de_bloom_aceita_variacoes_de_escrita(): void
    {
        $this->assertSame('lembrar', ReitorCompetenciasService::nivelDeBloom('LEMBRAR'));
        $this->assertSame('lembrar', ReitorCompetenciasService::nivelDeBloom('1 - Lembrar'));
        $this->assertSame('compreender', ReitorCompetenciasService::nivelDeBloom('Compreensão'));
        $this->assertSame('analisar', ReitorCompetenciasService::nivelDeBloom('Analisar'));
        $this->assertSame('criar', ReitorCompetenciasService::nivelDeBloom('Criação'));
        $this->assertNull(ReitorCompetenciasService::nivelDeBloom('qualquer coisa'));
        $this->assertNull(ReitorCompetenciasService::nivelDeBloom(null));
    }

    public function test_leitura_do_painel_fala_do_que_esta_na_tela(): void
    {
        $avaliacao = $this->cenario();

        $html = $this->actingAs($this->reitor(), 'admin')->get(route('reitor.visao', ['avaliacao' => $avaliacao->codigo]))->getContent();

        $this->assertStringContainsString('Participação de 75% (6 de 8 previstos)', $html);
        $this->assertStringContainsString('Sobre este quadro', $html);
    }

    public function test_configuracoes_guardam_corte_e_meta_validando_os_limites(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post('/sistema/configuracoes', ['backup_manter_ultimos' => 5, 'reitor_corte_proficiencia' => 65, 'reitor_meta_participacao' => '95,5'])
            ->assertSessionHasErrors('reitor_meta_participacao');

        $this->actingAs($admin, 'admin')->post('/sistema/configuracoes', ['backup_manter_ultimos' => 5, 'reitor_corte_proficiencia' => 65, 'reitor_meta_participacao' => '95.5'])
            ->assertSessionHasNoErrors();
        $this->assertSame(65.0, ReitorDashboardService::corte());
        $this->assertSame(95.5, ReitorDashboardService::meta());

        $this->actingAs($admin, 'admin')->post('/sistema/configuracoes', ['backup_manter_ultimos' => 5, 'reitor_corte_proficiencia' => 10])
            ->assertSessionHasErrors('reitor_corte_proficiencia');

        // o formulário antigo (sem os campos novos) continua válido e não apaga o que já estava salvo
        $this->actingAs($admin, 'admin')->post('/sistema/configuracoes', ['backup_manter_ultimos' => 7])->assertSessionHasNoErrors();
        $this->assertSame(65.0, ReitorDashboardService::corte());
    }
}
