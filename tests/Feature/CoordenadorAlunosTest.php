<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\AlunoMatricula;
use App\Models\Atividade;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\CoordenadorAlunosService;
use App\Services\CoordenadorDashboardService;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Painel de gestão do coordenador: os alunos do curso, como cada um está indo no semestre e a ficha individual.
 */
class CoordenadorAlunosTest extends TestCase
{
    use RefreshDatabase;

    private function coordenador(string $username = 'coord-direito', array $cursos = ['DIREITO']): Admin
    {
        $coordenador = Admin::create(['username' => $username, 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_COORDENADOR]);
        $coordenador->sincronizarCursos($cursos);

        return $coordenador;
    }

    private function admin(): Admin
    {
        return Admin::create(['username' => 'admin', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_ADMIN]);
    }

    private function aluno(string $nome, string $curso = 'DIREITO', string $periodo = '3º'): Aluno
    {
        static $ra = 5000;
        $ra++;

        return Aluno::create(['ra' => (string) $ra, 'nome' => $nome, 'curso' => $curso, 'periodo' => $periodo]);
    }

    /**
     * Avaliação de 3 questões (gabarito A, mesma área). Cada resultado é [Aluno, resposta]: 'A' acerta as 3, 'B'
     * erra as 3 e '' deixa a prova em branco (ausente).
     *
     * @param  array<int, array{0: Aluno, 1: string}>  $resultados
     */
    private function avaliacao(string $nome, string $data, array $resultados, ?int $categoriaId = null): Avaliacao
    {
        $avaliacao = Avaliacao::create(['nome' => $nome, 'data_avaliacao' => $data, 'categoria_id' => $categoriaId]);
        foreach ([1, 2, 3] as $numero) {
            Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => $numero, 'gabarito' => 'A', 'area' => 'Clínica']);
        }

        foreach ($resultados as [$aluno, $resposta]) {
            foreach ([1, 2, 3] as $numero) {
                Resposta::create([
                    'avaliacao_codigo' => $avaliacao->codigo,
                    'aluno_id' => $aluno->id,
                    'ra' => $aluno->ra,
                    'periodo' => $aluno->periodo,
                    'questao_numero' => $numero,
                    'resposta' => $resposta,
                ]);
            }
        }

        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        return $avaliacao;
    }

    /** @return array<string, array<string, mixed>> nome => aluno da lista do serviço */
    private function lista(Admin $coordenador, string $periodo = '2026/1', string $categoria = ''): array
    {
        $escopo = app(CoordenadorDashboardService::class)->escopo($coordenador, '', $periodo);

        return collect(app(CoordenadorAlunosService::class)->alunos($escopo, $categoria))->keyBy('nome')->all();
    }

    private function cenarioDoSemestre(): array
    {
        $ana = $this->aluno('Ana Destaque');
        $bia = $this->aluno('Bia Baixa');
        $cris = $this->aluno('Cris Ausente');
        $davi = $this->aluno('Davi Faltoso');
        $eva = $this->aluno('Eva Em Queda');
        $caio = $this->aluno('Caio Medicina', 'MEDICINA', '8º');

        // Três provas do mesmo tipo, em ordem.
        $this->avaliacao('Prova 1', '2026-03-10', [[$ana, 'A'], [$bia, 'B'], [$cris, ''], [$davi, 'A'], [$eva, 'A'], [$caio, 'A']]);
        $this->avaliacao('Prova 2', '2026-04-10', [[$ana, 'A'], [$bia, 'B'], [$cris, ''], [$davi, ''], [$eva, 'A'], [$caio, 'A']]);
        $this->avaliacao('Prova 3', '2026-05-10', [[$ana, 'A'], [$bia, 'B'], [$cris, ''], [$davi, ''], [$eva, 'B'], [$caio, 'A']]);

        return compact('ana', 'bia', 'cris', 'davi', 'eva', 'caio');
    }

    public function test_situacao_de_cada_aluno_no_semestre(): void
    {
        $this->cenarioDoSemestre();
        $lista = $this->lista($this->coordenador());

        $this->assertSame('destaque', $lista['Ana Destaque']['situacao']);
        $this->assertSame('atencao', $lista['Bia Baixa']['situacao']);
        $this->assertSame('ausente', $lista['Cris Ausente']['situacao']);
        $this->assertSame('atencao', $lista['Davi Faltoso']['situacao']); // 2 faltas
        $this->assertSame('atencao', $lista['Eva Em Queda']['situacao']); // 100, 100, 0 → queda de 100 pp

        $this->assertEquals(100.0, $lista['Ana Destaque']['media']);
        $this->assertSame(0, $lista['Ana Destaque']['faltas']);
        $this->assertEquals(0.0, $lista['Bia Baixa']['media']);
        $this->assertSame(2, $lista['Davi Faltoso']['faltas']);
        $this->assertNull($lista['Cris Ausente']['media'], 'ausente não entra nas médias');
        $this->assertEquals(66.7, $lista['Eva Em Queda']['media']);
        $this->assertEquals(-100.0, $lista['Eva Em Queda']['tendencia']['delta']);
        $this->assertStringContainsString('Queda de 100', implode(' ', $lista['Eva Em Queda']['motivos']));
    }

    public function test_lista_traz_so_alunos_do_curso_do_coordenador(): void
    {
        $this->cenarioDoSemestre();

        $resposta = $this->actingAs($this->coordenador(), 'admin')->get('/painel/alunos?periodo_letivo=2026/1');

        $resposta->assertOk()
            ->assertSee('Ana Destaque')->assertSee('Bia Baixa')->assertSee('Cris Ausente')
            ->assertDontSee('Caio Medicina')
            ->assertViewHas('resumo', fn ($r) => $r['total'] === 5 && $r['precisamAtencao'] === 4 && $r['porSituacao']['destaque'] === 1);
    }

    public function test_filtros_de_busca_situacao_e_periodo_do_curso(): void
    {
        $this->cenarioDoSemestre();
        $coordenador = $this->coordenador();

        $this->actingAs($coordenador, 'admin')->get('/painel/alunos?periodo_letivo=2026/1&busca=bia')
            ->assertSee('Bia Baixa')->assertDontSee('Ana Destaque');

        // Sem acento/caixa: "FALTOSO" acha o Davi.
        $this->actingAs($coordenador, 'admin')->get('/painel/alunos?periodo_letivo=2026/1&busca=FALTOSO')
            ->assertSee('Davi Faltoso')->assertDontSee('Bia Baixa');

        $this->actingAs($coordenador, 'admin')->get('/painel/alunos?periodo_letivo=2026/1&situacao=destaque')
            ->assertSee('Ana Destaque')->assertDontSee('Bia Baixa');

        // "atencao" junta quem está em atenção e quem faltou em tudo.
        $this->actingAs($coordenador, 'admin')->get('/painel/alunos?periodo_letivo=2026/1&situacao=atencao')
            ->assertSee('Bia Baixa')->assertSee('Cris Ausente')->assertDontSee('Ana Destaque');

        $this->actingAs($coordenador, 'admin')->get('/painel/alunos?periodo_letivo=2026/1&periodo_curso=9')
            ->assertOk()->assertDontSee('Ana Destaque')->assertSee('Nenhum aluno encontrado');
    }

    public function test_filtros_invalidos_sao_ignorados_e_ordenacao_por_prioridade(): void
    {
        $this->cenarioDoSemestre();
        $coordenador = $this->coordenador();

        $this->actingAs($coordenador, 'admin')->get('/painel/alunos?periodo_letivo=2026/1&situacao=hack&ordem=drop&periodo_curso=x&categoria=abc')
            ->assertOk()
            ->assertViewHas('filtros', fn ($f) => $f['situacao'] === '' && $f['ordem'] === 'nome' && $f['periodo_curso'] === '')
            ->assertViewHas('alunosPagina', fn ($p) => $p->total() === 5);

        $ordem = collect(app(CoordenadorAlunosService::class)->filtrar(array_values($this->lista($coordenador)), ['ordem' => 'prioridade']))->pluck('nome');
        $this->assertSame('Cris Ausente', $ordem->first(), 'ausente em tudo vem primeiro');
        $this->assertSame('Ana Destaque', $ordem->last(), 'destaque por último');
    }

    public function test_filtra_o_semestre(): void
    {
        $velho = $this->aluno('Aluno do semestre passado');
        $novo = $this->aluno('Aluno do semestre atual');
        $this->avaliacao('Prova velha', '2026-03-10', [[$velho, 'A']]);
        $this->avaliacao('Prova nova', '2026-09-10', [[$novo, 'A']]);
        $coordenador = $this->coordenador();

        // Sem parâmetro: semestre mais recente.
        $this->actingAs($coordenador, 'admin')->get('/painel/alunos')->assertSee('Aluno do semestre atual')->assertDontSee('Aluno do semestre passado');
        $this->actingAs($coordenador, 'admin')->get('/painel/alunos?periodo_letivo=2026/1')->assertSee('Aluno do semestre passado')->assertDontSee('Aluno do semestre atual');
        $this->actingAs($coordenador, 'admin')->get('/painel/alunos?periodo_letivo=')->assertSee('Aluno do semestre passado')->assertSee('Aluno do semestre atual');
    }

    public function test_filtra_por_categoria_e_tendencia_so_compara_a_mesma_categoria(): void
    {
        $simulados = Categoria::create(['nome' => 'Simulados']);
        $disciplinas = Categoria::create(['nome' => 'Disciplinas']);
        $ana = $this->aluno('Ana');

        // Simulado: 100 → 100 (estável). A prova de disciplina, no meio, é 0 e NÃO pode virar "queda" do simulado.
        $this->avaliacao('Simulado 1', '2026-03-01', [[$ana, 'A']], $simulados->id);
        $this->avaliacao('Disciplina 1', '2026-03-15', [[$ana, 'B']], $disciplinas->id);
        $this->avaliacao('Simulado 2', '2026-04-01', [[$ana, 'A']], $simulados->id);
        $coordenador = $this->coordenador();

        $todas = $this->lista($coordenador)['Ana'];
        $this->assertSame(3, $todas['inscritos']);
        $this->assertEquals(66.7, $todas['media']);
        $this->assertEquals(0.0, $todas['tendencia']['delta'], 'compara Simulado 2 com Simulado 1, ignorando a disciplina');

        $soSimulados = $this->lista($coordenador, '2026/1', (string) $simulados->id)['Ana'];
        $this->assertSame(2, $soSimulados['inscritos']);
        $this->assertEquals(100.0, $soSimulados['media']);

        $this->actingAs($coordenador, 'admin')->get("/painel/alunos?periodo_letivo=2026/1&categoria={$disciplinas->id}")
            ->assertViewHas('alunosPagina', fn ($p) => $p->total() === 1 && $p->items()[0]['inscritos'] === 1 && $p->items()[0]['media'] === 0.0);
    }

    public function test_matriculado_sem_nenhum_resultado_aparece_como_sem_resultado(): void
    {
        $ana = $this->aluno('Ana');
        $fabio = $this->aluno('Fábio Matriculado');
        $saiu = $this->aluno('Gil Transferido');
        $this->avaliacao('Prova', '2026-03-10', [[$ana, 'A']]);
        AlunoMatricula::create(['aluno_id' => $fabio->id, 'curso' => 'DIREITO', 'periodo_letivo' => '2026/1', 'periodo' => '3º', 'status' => 'ATIVA']);
        AlunoMatricula::create(['aluno_id' => $saiu->id, 'curso' => 'DIREITO', 'periodo_letivo' => '2026/1', 'periodo' => '3º', 'status' => 'TRANSFERIDA']);

        $lista = $this->lista($this->coordenador());

        $this->assertSame('sem_resultado', $lista['Fábio Matriculado']['situacao']);
        $this->assertSame(0, $lista['Fábio Matriculado']['inscritos']);
        $this->assertArrayNotHasKey('Gil Transferido', $lista, 'quem saiu do curso não é aluno do semestre');
    }

    public function test_mesma_pessoa_com_chaves_diferentes_aparece_uma_vez(): void
    {
        $gil = Aluno::create(['ra' => '7001', 'cpf' => '12345678901', 'nome' => 'Gil Duas Chaves', 'curso' => 'DIREITO', 'periodo' => '3º']);
        $a = Avaliacao::create(['nome' => 'Com CPF', 'data_avaliacao' => '2026-03-10']);
        $b = Avaliacao::create(['nome' => 'Só RA', 'data_avaliacao' => '2026-04-10']);
        foreach ([$a, $b] as $avaliacao) {
            Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        }
        // aluno_chave = COALESCE(cpf, ra): numa prova a chave é o CPF, na outra o RA — e nenhuma traz aluno_id.
        Resposta::create(['avaliacao_codigo' => $a->codigo, 'ra' => '7001', 'cpf' => '12345678901', 'periodo' => '3º', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $b->codigo, 'ra' => '7001', 'periodo' => '3º', 'questao_numero' => 1, 'resposta' => 'B']);
        app(ResumoResultadoService::class)->recalcular($a->codigo);
        app(ResumoResultadoService::class)->recalcular($b->codigo);

        $lista = $this->lista($this->coordenador());

        $this->assertCount(1, $lista);
        $this->assertSame($gil->id, $lista['Gil Duas Chaves']['id']);
        $this->assertSame(2, $lista['Gil Duas Chaves']['inscritos']);
        $this->assertEquals(50.0, $lista['Gil Duas Chaves']['media']);
    }

    public function test_ficha_do_aluno_mostra_avaliacoes_posicao_e_media_do_curso(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulados']);
        $ana = $this->aluno('Ana');
        $bia = $this->aluno('Bia');
        $cris = $this->aluno('Cris');
        $davi = $this->aluno('Davi');
        $this->avaliacao('Simulado 1', '2026-03-10', [[$ana, 'A'], [$bia, 'B'], [$cris, 'A'], [$davi, 'B']], $categoria->id);
        $this->avaliacao('Simulado 2', '2026-04-10', [[$ana, 'B'], [$bia, 'B'], [$cris, 'A'], [$davi, '']], $categoria->id);
        $coordenador = $this->coordenador();

        $resposta = $this->actingAs($coordenador, 'admin')->get("/painel/alunos/{$ana->id}?periodo_letivo=2026/1");

        $resposta->assertOk()
            ->assertSee('Ficha do aluno')
            ->assertSee('Simulado 1')->assertSee('Simulado 2')
            ->assertSee('Simulados');

        $ficha = $resposta->viewData('ficha');
        $primeira = $ficha['categorias'][0]['avaliacoes'][0];
        $this->assertSame('Simulado 1', $primeira['nome']);
        $this->assertEquals(100.0, $primeira['percentual']);
        $this->assertEquals(50.0, $primeira['mediaCurso']);
        $this->assertSame(1, $primeira['posicao'], 'Ana e Cris empatam em 1º');
        $this->assertSame(4, $primeira['presentesCurso']);

        $segunda = $ficha['categorias'][0]['avaliacoes'][1];
        $this->assertEquals(0.0, $segunda['percentual']);
        $this->assertEquals(33.3, $segunda['mediaCurso']); // (0 + 0 + 100) / 3 presentes: Davi faltou
        $this->assertEquals(-33.3, $segunda['diferenca']);

        $this->assertEquals(50.0, $ficha['media']);
        $this->assertEquals(-100.0, $ficha['tendencia']['delta']);
        $this->assertSame('atencao', $ficha['situacao']);
        $this->assertCount(2, $ficha['categorias'][0]['grafico']);
        $this->assertSame('Clínica', $ficha['categorias'][0]['areas'][0]['area']);
        $this->assertEquals(50.0, $ficha['categorias'][0]['areas'][0]['percentual']);

        // Davi faltou na 2ª prova: aparece como ausente na ficha dele.
        $this->actingAs($coordenador, 'admin')->get("/painel/alunos/{$davi->id}?periodo_letivo=2026/1")
            ->assertOk()->assertViewHas('ficha', fn ($f) => $f['faltas'] === 1 && $f['categorias'][0]['avaliacoes'][1]['ausente'] === true);
    }

    public function test_ficha_de_aluno_de_outro_curso_e_404_e_nao_vaza(): void
    {
        $ana = $this->aluno('Ana');
        $caio = $this->aluno('Caio Medicina', 'MEDICINA', '8º');
        $this->avaliacao('Prova', '2026-03-10', [[$ana, 'A'], [$caio, 'A']]);
        $coordenador = $this->coordenador();

        $this->actingAs($coordenador, 'admin')->get("/painel/alunos/{$ana->id}")->assertOk();
        $this->actingAs($coordenador, 'admin')->get("/painel/alunos/{$caio->id}")->assertNotFound();
        $this->actingAs($coordenador, 'admin')->get('/painel/alunos/999999')->assertNotFound();
        $this->actingAs($coordenador, 'admin')->get('/painel/alunos/abc')->assertNotFound();
    }

    public function test_ficha_de_aluno_transferido_so_mostra_as_provas_do_curso_do_coordenador(): void
    {
        $duda = $this->aluno('Duda Transferida');
        $antiga = $this->avaliacao('Prova quando era de Medicina', '2026-03-10', [[$duda, 'A']]);
        $nova = $this->avaliacao('Prova de Direito', '2026-04-10', [[$duda, 'B']]);
        // O curso do resultado é o da época da prova (CursoDoResultadoService), não o atual do aluno.
        DB::table('resultado_resumos')->where('avaliacao_codigo', $antiga->codigo)->update(['curso' => 'MEDICINA']);

        $this->actingAs($this->coordenador(), 'admin')->get("/painel/alunos/{$duda->id}?periodo_letivo=2026/1")
            ->assertOk()
            ->assertSee('Prova de Direito')
            ->assertDontSee('Prova quando era de Medicina');

        $this->actingAs($this->coordenador('coord-med', ['MEDICINA']), 'admin')->get("/painel/alunos/{$duda->id}?periodo_letivo=2026/1")
            ->assertOk()
            ->assertSee('Prova quando era de Medicina')
            ->assertDontSee('Prova de Direito');
    }

    public function test_perfis_sem_acesso_ao_painel_de_alunos(): void
    {
        $ana = $this->aluno('Ana');
        $this->avaliacao('Prova', '2026-03-10', [[$ana, 'A']]);

        // Administrador não tem curso: volta para a lista de avaliações.
        $admin = $this->admin();
        foreach (['/painel/alunos', "/painel/alunos/{$ana->id}", '/painel/alunos/exportar.xlsx', '/painel/desempenho'] as $url) {
            $this->actingAs($admin, 'admin')->get($url)->assertRedirect(route('avaliacoes.index'));
        }

        // Perfil desconhecido não passa (papel-valido) e visitante vai para o login.
        $this->app['auth']->guard('admin')->logout();
        $this->get('/painel/alunos')->assertRedirect(route('login'));

        $estranho = Admin::create(['username' => 'estranho', 'password_hash' => bcrypt('x'), 'role' => 'coord']);
        $this->actingAs($estranho, 'admin')->get('/painel/alunos')->assertRedirect();
    }

    public function test_coordenador_sem_curso_ve_aviso_na_lista_e_404_na_ficha(): void
    {
        $ana = $this->aluno('Ana');
        $this->avaliacao('Prova', '2026-03-10', [[$ana, 'A']]);
        $semCurso = Admin::create(['username' => 'sem-curso', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_COORDENADOR]);

        $this->actingAs($semCurso, 'admin')->get('/painel/alunos')->assertOk()->assertSee('não está vinculada a nenhum curso');
        $this->actingAs($semCurso, 'admin')->get("/painel/alunos/{$ana->id}")->assertNotFound();
    }

    public function test_exporta_a_lista_filtrada_em_xlsx_e_registra_na_atividade(): void
    {
        $ana = $this->aluno('Ana Destaque');
        $bia = $this->aluno('=HYPERLINK("http://x")');
        $caio = $this->aluno('Caio Medicina', 'MEDICINA', '8º');
        $this->avaliacao('Prova 1', '2026-03-10', [[$ana, 'A'], [$bia, 'B'], [$caio, 'A']]);
        $coordenador = $this->coordenador();

        $resposta = $this->actingAs($coordenador, 'admin')->get('/painel/alunos/exportar.xlsx?periodo_letivo=2026/1');

        $resposta->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', $resposta->headers->get('Content-Type'));
        $this->assertStringContainsString('alunos-do-curso-2026-1.xlsx', $resposta->headers->get('Content-Disposition'));

        $linhas = $this->lerXlsx($resposta->streamedContent());
        $this->assertSame('Nome', $linhas[0][0]);
        $this->assertCount(3, $linhas, 'cabeçalho + 2 alunos de Direito (Caio é de Medicina)');
        $porNome = collect($linhas)->skip(1)->keyBy(0);
        $this->assertEquals(100, $porNome['Ana Destaque'][7]);
        $this->assertSame('Destaque', $porNome['Ana Destaque'][12]);
        $this->assertSame('=HYPERLINK("http://x")', $porNome['=HYPERLINK("http://x")'][0], 'texto "=..." não vira fórmula');
        $this->assertFalse($porNome->has('Caio Medicina'));

        $atividade = Atividade::where('acao', 'coordenador.lista_alunos_exportada')->first();
        $this->assertNotNull($atividade);
        $this->assertSame($coordenador->id, $atividade->admin_id);

        // Só os filtrados: situação "destaque".
        $filtrado = $this->lerXlsx($this->actingAs($coordenador, 'admin')->get('/painel/alunos/exportar.xlsx?periodo_letivo=2026/1&situacao=destaque')->streamedContent());
        $this->assertCount(2, $filtrado);
    }

    public function test_visao_geral_saudacao_abas_e_sem_a_lista_nominal_de_atencao(): void
    {
        $this->cenarioDoSemestre();
        $coordenador = $this->coordenador('matheus.oliveira');

        $resposta = $this->actingAs($coordenador, 'admin')->get('/painel?periodo_letivo=2026/1');

        $resposta->assertOk()
            ->assertSee('id="saudacao-coordenador"', false)
            ->assertSee('data-nome="Matheus"', false)
            ->assertSee('Boa tarde', false) // script da saudação
            // Saíram da visão geral: a lista nominal "Alunos que precisam de atenção" e o quadro "Situação dos alunos"
            // (os alunos ficam na aba Alunos); o cartão "Precisam de atenção" continua, com link para a lista.
            ->assertDontSee('Alunos que precisam de atenção')
            ->assertDontSee('Situação dos alunos')
            ->assertDontSee('Cris Ausente')
            ->assertDontSee('Bia Baixa')
            ->assertSee('Precisam de atenção')
            ->assertSee('Alunos por período do curso')
            ->assertSee('Prova 3')
            ->assertSee('aria-label="Seções do painel"', false)
            ->assertSee(route('coordenador.alunos', ['periodo_letivo' => '2026/1']), false)
            ->assertSee(route('coordenador.desempenho', ['periodo_letivo' => '2026/1']), false);

        $resposta->assertViewHas('resumoAlunos', fn ($r) => $r['total'] === 5 && $r['precisamAtencao'] === 4)
            ->assertViewMissing('emAtencao');
    }

    public function test_menu_do_coordenador_tem_as_secoes_do_painel(): void
    {
        $ana = $this->aluno('Ana');
        $this->avaliacao('Prova', '2026-03-10', [[$ana, 'A']]);

        $this->actingAs($this->coordenador(), 'admin')->get('/painel/alunos')
            ->assertOk()
            ->assertSee('Visão geral')->assertSee('Alunos do curso')->assertSee('Desempenho')->assertSee('Avaliações')
            ->assertSee('aria-current="page"', false);
    }

    public function test_nome_para_a_saudacao(): void
    {
        $nome = fn (string $usuario) => (new Admin(['username' => $usuario]))->nomeParaSaudacao();

        $this->assertSame('Matheus', $nome('matheus.oliveira'));
        $this->assertSame('Maria', $nome('maria_souza'));
        $this->assertSame('Joao', $nome('JOAO-SILVA@faa.edu.br'));
        $this->assertSame('Ágata', $nome('ágata costa'));
        $this->assertSame('12345', $nome('12345'));
    }

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
}
