<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\Notificacao;
use App\Models\PlanoAcao;
use App\Models\PlanoAcaoAcao;
use App\Models\PlanoAcaoEvento;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\PlanoAcaoLembreteService;
use App\Services\PlanoAcaoResultadoService;
use App\Services\ResumoResultadoService;
use App\Support\PlanoAcaoChecagem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Plano de ação do coordenador: nasce de um dado do painel (ícone nos visuais) já preenchido com curso e indicadores,
 * percorre o roteiro dado → causa → ação, é enviado ao colaborador (aprovar, pedir ajustes ou recusar, sempre com
 * justificativa) e, aprovado, é acompanhado até o encerramento.
 */
class PlanoAcaoTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $categoria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoria = Categoria::create(['nome' => 'Diagnósticos']);
    }

    private function coordenador(string $nome = 'coord', string $curso = 'MEDICINA'): Admin
    {
        $coordenador = Admin::create(['username' => $nome, 'email' => "{$nome}@example.test", 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_COORDENADOR]);
        $coordenador->sincronizarCursos([$curso]);

        return $coordenador;
    }

    private function colaborador(): Admin
    {
        return Admin::create(['username' => 'colab', 'email' => 'colab@example.test', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_COLABORADOR]);
    }

    private function reitor(): Admin
    {
        return Admin::create(['username' => 'reitor', 'email' => 'reitor@example.test', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_REITOR]);
    }

    /**
     * Avaliação de 10 questões (gabarito A, área "Clínica") com seis alunos de MEDICINA; `$acertos` = quantos de 0 a 10
     * cada um acerta. O resumo de resultados é recalculado, como numa importação.
     *
     * @param  array<int, int>  $acertos
     */
    private function avaliacao(string $nome, string $data, array $acertos = [8, 8, 6, 5, 4, 2], string $curso = 'MEDICINA'): Avaliacao
    {
        static $ra = 9000;

        $avaliacao = Avaliacao::create(['nome' => $nome, 'data_avaliacao' => $data, 'categoria_id' => $this->categoria->id]);
        foreach (range(1, 10) as $n) {
            Questao::create([
                'avaliacao_codigo' => $avaliacao->codigo, 'numero' => $n, 'gabarito' => 'A', 'area' => 'Clínica',
                'bloom_nivel' => $n <= 5 ? 'Aplicar' : 'Lembrar', 'tema' => $n <= 5 ? 'Arritmias' : 'Asma',
            ]);
        }

        foreach ($acertos as $certas) {
            $ra++;
            $aluno = Aluno::create(['ra' => (string) $ra, 'nome' => 'Aluno '.$ra, 'curso' => $curso, 'periodo' => '3º']);
            foreach (range(1, 10) as $n) {
                Resposta::create([
                    'avaliacao_codigo' => $avaliacao->codigo, 'aluno_id' => $aluno->id, 'ra' => (string) $ra, 'periodo' => '3º',
                    'questao_numero' => $n, 'resposta' => $n <= $certas ? 'A' : 'B',
                ]);
            }
        }
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        return $avaliacao;
    }

    /** @param array<string, mixed> $extra */
    private function dados(array $extra = []): array
    {
        return [
            'meta_proficiencia' => '70',
            'data_proxima_avaliacao' => now()->addDays(90)->toDateString(),
            'recorte' => '3º período, alunos abaixo de 60%',
            'resultado' => 'Baixo acerto em Clínica',
            'fragilidades' => 'Clínica (58%)',
            'evidencias' => 'Média de 55% na categoria',
            'causas' => ['ensino' => 'Poucos casos clínicos nas aulas'],
            'causa_priorizada' => 'Poucas atividades com casos clínicos',
            'nota_impacto' => 3,
            'nota_evidencia' => 2,
            'nota_governabilidade' => 3,
            'porques' => ['Faltam casos nas aulas', 'O plano de ensino não prevê'],
            'causa_raiz' => 'Plano de ensino sem casos clínicos integrados',
            'acoes' => [[
                'descricao' => 'Implementar casos clínicos integrados nas aulas',
                'execucao' => 'Um caso por quinzena, com devolutiva',
                'responsavel' => 'Profa. Ana',
                'prazo' => now()->addDays(30)->toDateString(),
                'verificacao' => 'Lista de presença e mini-teste ao fim de cada caso',
            ]],
            ...$extra,
        ];
    }

    /** @param array<string, mixed> $dados */
    private function criar(Admin $coordenador, array $dados = [], string $acao = 'salvar'): PlanoAcao
    {
        $this->actingAs($coordenador, 'admin')->post('/painel/planos', [
            ...$this->dados($dados),
            'acao' => $acao,
            'origem' => ['curso' => 'MEDICINA', 'periodo_letivo' => '2026/1', 'categoria' => $this->categoria->id, 'visual' => 'area', 'item' => 'Clínica'],
        ])->assertSessionHasNoErrors();

        return PlanoAcao::latest('id')->firstOrFail();
    }

    private function enviado(?Admin $coordenador = null): PlanoAcao
    {
        $this->avaliacao('D1', '2026-03-10');
        $plano = $this->criar($coordenador ?? $this->coordenador(), [], 'enviar');
        $this->assertSame(PlanoAcao::EM_ANALISE, $plano->fresh()->status);

        return $plano->fresh();
    }

    // ---- o ícone nos visuais e o ponto de partida ----

    public function test_visuais_do_coordenador_trazem_o_icone_de_plano_de_acao(): void
    {
        $this->avaliacao('D1', '2026-03-10');

        $html = $this->actingAs($this->coordenador(), 'admin')->get('/painel/desempenho?periodo_letivo=2026/1')->assertOk()->getContent();

        // um link por visual/dado: o gráfico inteiro, cada área e a linha da avaliação
        $this->assertStringContainsString('visual=area', $html);
        $this->assertStringContainsString('visual=bloom', $html);
        $this->assertStringContainsString('visual=participacao', $html);
        $this->assertStringContainsString('visual=proficiencia', $html);
        $this->assertStringContainsString('visual=avaliacao', $html);
        $this->assertMatchesRegularExpression('/aria-label="Iniciar plano de ação: Desempenho por área/', $html);

        // a visão geral também: presença, destaques e a linha de cada avaliação recente
        $painel = $this->actingAs($this->coordenador('outro'), 'admin')->get('/painel?periodo_letivo=2026/1')->assertOk()->getContent();
        $this->assertStringContainsString('planos/novo?', $painel);
        $this->assertStringContainsString('visual=participacao', $painel);
        $this->assertStringContainsString('visual=avaliacao', $painel);
    }

    public function test_icone_tambem_esta_em_comparar_semestres_e_no_dashboard_da_avaliacao(): void
    {
        $d1 = $this->avaliacao('D1', '2026-03-10');
        $this->avaliacao('D2', '2026-09-10', [9, 9, 8, 8, 7, 6]);
        $coordenador = $this->coordenador();

        $comparativo = $this->actingAs($coordenador, 'admin')->get('/painel/comparativo?periodo_letivo=2026/2&comparar=2026/1')->assertOk()->getContent();
        $this->assertStringContainsString('visual=area', $comparativo);
        $this->assertStringContainsString('visual=periodo_curso', $comparativo);
        $this->assertStringContainsString('visual=proficiencia', $comparativo);

        $bi = $this->get("/avaliacoes/{$d1->codigo}/bi")->assertOk()->getContent();
        $this->assertStringContainsString('visual=area', $bi);
        $this->assertStringContainsString('visual=bloom', $bi);
        $this->assertStringContainsString('avaliacao='.$d1->codigo, $bi);
    }

    public function test_plano_iniciado_no_dashboard_da_avaliacao_assume_o_periodo_e_a_categoria_dela(): void
    {
        $this->avaliacao('D1', '2026-03-10');
        $d2 = $this->avaliacao('D2', '2026-09-10', [9, 9, 8, 8, 7, 6]);

        $origem = $this->actingAs($this->coordenador(), 'admin')->get("/painel/planos/novo?visual=area&avaliacao={$d2->codigo}")->assertOk()->viewData('origem');

        $this->assertSame('2026/2', $origem['periodo_letivo']);
        $this->assertSame($this->categoria->id, $origem['categoria_id']);
        $this->assertSame($d2->codigo, $origem['avaliacao_codigo']);
        $this->assertSame(100.0, $origem['indicadores']['proficiencia']);
    }

    public function test_reitor_olhando_o_curso_nao_ve_o_icone_nem_cria_plano(): void
    {
        $this->avaliacao('D1', '2026-03-10');
        $reitor = $this->reitor();

        $this->actingAs($reitor, 'admin')->withSession(['visao_de_curso' => 'MEDICINA']);
        $html = $this->get('/painel/desempenho?periodo_letivo=2026/1')->assertOk()->getContent();

        $this->assertStringNotContainsString('Iniciar plano de ação', $html);
        $this->get('/painel/planos/novo?visual=geral&periodo_letivo=2026/1')->assertForbidden();
        $this->post('/painel/planos', $this->dados())->assertForbidden();
    }

    public function test_novo_plano_vem_preenchido_com_curso_e_indicadores_do_painel(): void
    {
        $avaliacao = $this->avaliacao('D1', '2026-03-10'); // acertos 8,8,6,5,4,2 → 3 de 6 com 60% ou mais

        $resposta = $this->actingAs($this->coordenador(), 'admin')
            ->get('/painel/planos/novo?visual=area&item=Clínica&periodo_letivo=2026/1&categoria='.$this->categoria->id)
            ->assertOk();

        $origem = $resposta->viewData('origem');
        $this->assertSame('MEDICINA', $origem['curso']);
        $this->assertSame('2026/1', $origem['periodo_letivo']);
        $this->assertSame($this->categoria->id, $origem['categoria_id']);
        $this->assertSame(100.0, $origem['indicadores']['participacao']);
        $this->assertSame(50.0, $origem['indicadores']['proficiencia']);
        $this->assertSame(98.0, $origem['indicadores']['meta_participacao']);
        $this->assertSame('Desempenho por área · Clínica', $origem['rotulo']);
        // o dado do visual e as sugestões de texto
        $this->assertTrue(collect($origem['linhas'])->contains(fn ($l) => $l['rotulo'] === 'Clínica' && $l['destaque']));
        $this->assertStringContainsString('Clínica', $origem['sugestoes']['resultado']);

        $resposta->assertSee('Participação atual')->assertSee('Meta de participação')->assertSee('Proficiência atual')->assertSee('Meta de proficiência', false);
        $resposta->assertSee('MEDICINA');
        $this->assertNotNull($avaliacao);
    }

    public function test_so_o_curso_do_proprio_coordenador_e_aceito(): void
    {
        $this->avaliacao('D1', '2026-03-10');
        $this->avaliacao('Odonto', '2026-03-11', [9, 9, 9, 9, 9, 9], 'ODONTOLOGIA');

        $origem = $this->actingAs($this->coordenador(), 'admin')
            ->get('/painel/planos/novo?visual=geral&curso=ODONTOLOGIA&periodo_letivo=2026/1')->assertOk()->viewData('origem');

        // pediu o curso de outro coordenador: continua no dele
        $this->assertSame('MEDICINA', $origem['curso']);
        $this->assertSame(50.0, $origem['indicadores']['proficiencia']);
    }

    public function test_os_numeros_vem_do_servidor_e_nao_do_navegador(): void
    {
        $this->avaliacao('D1', '2026-03-10');

        $this->actingAs($this->coordenador(), 'admin')->post('/painel/planos', [
            ...$this->dados(),
            'acao' => 'salvar',
            'participacao_atual' => '1', 'proficiencia_atual' => '99', 'meta_participacao' => '5', 'curso' => 'DIREITO', 'status' => 'aprovado',
            'origem' => ['curso' => 'MEDICINA', 'periodo_letivo' => '2026/1', 'categoria' => $this->categoria->id, 'visual' => 'geral'],
        ]);

        $plano = PlanoAcao::firstOrFail();
        $this->assertSame('MEDICINA', $plano->curso);
        $this->assertSame(100.0, $plano->participacao_atual);
        $this->assertSame(50.0, $plano->proficiencia_atual);
        $this->assertSame(98.0, $plano->meta_participacao);
        $this->assertSame(PlanoAcao::RASCUNHO, $plano->status);
    }

    public function test_sem_resultados_no_recorte_nao_cria_plano(): void
    {
        $this->actingAs($this->coordenador(), 'admin')->post('/painel/planos', [
            ...$this->dados(), 'acao' => 'salvar',
            'origem' => ['curso' => 'MEDICINA', 'periodo_letivo' => '2026/1', 'visual' => 'geral'],
        ])->assertSessionHasErrors('origem');

        $this->assertSame(0, PlanoAcao::count());
    }

    // ---- rascunho e envio ----

    public function test_rascunho_pode_ficar_pela_metade_e_depois_ser_editado(): void
    {
        $this->avaliacao('D1', '2026-03-10');
        $coordenador = $this->coordenador();

        $plano = $this->criar($coordenador, ['recorte' => 'Só isto', 'causas' => [], 'acoes' => [], 'causa_raiz' => '', 'meta_proficiencia' => '']);
        $this->assertSame(PlanoAcao::RASCUNHO, $plano->status);
        $this->assertSame('Só isto', $plano->recorte);
        $this->assertSame(0, $plano->acoes()->count());

        $this->put("/painel/planos/{$plano->id}", [...$this->dados(['recorte' => 'Agora completo']), 'acao' => 'salvar', 'etapa_atual' => 3])
            ->assertRedirect(route('coordenador.planos.edit', [$plano, 'etapa' => 3]));

        $plano->refresh();
        $this->assertSame('Agora completo', $plano->recorte);
        $this->assertSame(70.0, $plano->meta_proficiencia);
        $this->assertSame(['ensino' => 'Poucos casos clínicos nas aulas'], $plano->causas);
        $this->assertSame(18, $plano->pontuacao());
        $this->assertSame(1, $plano->acoes()->count());
        // a linha de base não muda ao editar
        $this->assertSame(50.0, $plano->proficiencia_atual);
    }

    public function test_enviar_incompleto_mantem_o_rascunho_e_lista_o_que_falta(): void
    {
        $this->avaliacao('D1', '2026-03-10');

        $plano = $this->criar($this->coordenador(), ['causa_raiz' => '', 'acoes' => [['descricao' => 'Reunião com docentes', 'execucao' => '', 'responsavel' => '', 'prazo' => '', 'verificacao' => '']]], 'enviar');

        $plano->refresh();
        $this->assertSame(PlanoAcao::RASCUNHO, $plano->status);
        $this->assertSame(0, $plano->envios);

        $faltas = collect(PlanoAcaoChecagem::pendencias($plano->load('acoes')))->pluck('mensagem')->implode(' | ');
        $this->assertStringContainsString('causa-raiz', $faltas);
        $this->assertStringContainsString('inicie com um verbo no infinitivo', $faltas);
        $this->assertStringContainsString('informe o responsável', $faltas);
        $this->assertStringContainsString('informe o prazo', $faltas);
    }

    public function test_enviar_completo_vai_para_analise_e_registra_o_evento(): void
    {
        $plano = $this->enviado();

        $this->assertSame(1, $plano->envios);
        $this->assertNotNull($plano->enviado_em);
        $this->assertSame([PlanoAcaoEvento::ENVIADO, PlanoAcaoEvento::CRIADO], $plano->eventos()->pluck('tipo')->all());
        // enviado: não se edita mais
        $this->get("/painel/planos/{$plano->id}/editar")->assertRedirect(route('coordenador.planos.show', $plano));
        $this->put("/painel/planos/{$plano->id}", [...$this->dados(['recorte' => 'Mudei']), 'acao' => 'salvar']);
        $this->assertNotSame('Mudei', $plano->fresh()->recorte);
    }

    public function test_prazo_no_passado_e_acao_sem_verbo_impedem_o_envio(): void
    {
        $this->avaliacao('D1', '2026-03-10');

        $plano = $this->criar($this->coordenador(), ['acoes' => [[
            'descricao' => 'Atividades integradoras', 'execucao' => 'Quinzenal', 'responsavel' => 'Ana', 'prazo' => now()->subDay()->toDateString(), 'verificacao' => 'Mini-teste',
        ]]], 'enviar');

        $faltas = collect(PlanoAcaoChecagem::pendencias($plano->fresh()->load('acoes')))->pluck('mensagem')->implode(' | ');
        $this->assertSame(PlanoAcao::RASCUNHO, $plano->fresh()->status);
        $this->assertStringContainsString('verbo no infinitivo', $faltas);
        $this->assertStringContainsString('o prazo já passou', $faltas);
    }

    public function test_coordenador_retira_o_plano_da_analise(): void
    {
        $plano = $this->enviado();

        $this->post("/painel/planos/{$plano->id}/retirar")->assertRedirect(route('coordenador.planos.edit', $plano));

        $this->assertSame(PlanoAcao::RASCUNHO, $plano->fresh()->status);
        $this->assertContains(PlanoAcaoEvento::RETIRADO, $plano->eventos()->pluck('tipo')->all());
    }

    public function test_so_rascunho_pode_ser_excluido(): void
    {
        $this->avaliacao('D1', '2026-03-10');
        $coordenador = $this->coordenador();
        $rascunho = $this->criar($coordenador);
        $enviado = $this->criar($coordenador, [], 'enviar');

        $this->delete("/painel/planos/{$enviado->id}")->assertRedirect(route('coordenador.planos.show', $enviado));
        $this->assertDatabaseHas('planos_acao', ['id' => $enviado->id]);

        $this->delete("/painel/planos/{$rascunho->id}")->assertRedirect(route('coordenador.planos.index'));
        $this->assertDatabaseMissing('planos_acao', ['id' => $rascunho->id]);
    }

    // ---- a decisão do colaborador ----

    public function test_colaborador_aprova_e_o_coordenador_e_avisado(): void
    {
        $coordenador = $this->coordenador();
        $plano = $this->enviado($coordenador);
        $colaborador = $this->colaborador();

        $this->actingAs($colaborador, 'admin')->post("/colaboracao/planos/{$plano->id}/decisao", [
            'decisao' => 'aprovar', 'justificativa' => 'Plano coerente com a causa-raiz.', 'criterios' => ['dados', 'causa', 'acoes', 'viabilidade', 'verificacao'],
        ])->assertRedirect(route('colaborador.planos.show', $plano));

        $plano->refresh();
        $this->assertSame(PlanoAcao::APROVADO, $plano->status);
        $this->assertSame($colaborador->id, $plano->decidido_por);
        $evento = $plano->eventos()->where('tipo', PlanoAcaoEvento::APROVADO)->firstOrFail();
        $this->assertSame('Plano coerente com a causa-raiz.', $evento->texto);
        $this->assertTrue($evento->dados['criterios']['causa']);

        $aviso = Notificacao::where('admin_id', $coordenador->id)->where('tipo', 'plano_aprovado')->firstOrFail();
        $this->assertSame(route('coordenador.planos.show', $plano), $aviso->url);
        $this->assertNull($aviso->lida_em);
    }

    public function test_pedir_ajustes_e_recusar_exigem_justificativa(): void
    {
        $plano = $this->enviado();
        $colaborador = $this->colaborador();

        foreach (['ajustes', 'recusar'] as $decisao) {
            $this->actingAs($colaborador, 'admin')->post("/colaboracao/planos/{$plano->id}/decisao", ['decisao' => $decisao, 'justificativa' => 'curto'])
                ->assertSessionHasErrors('justificativa');
        }

        $this->assertSame(PlanoAcao::EM_ANALISE, $plano->fresh()->status);

        $this->post("/colaboracao/planos/{$plano->id}/decisao", ['decisao' => 'recusar', 'justificativa' => 'Não há evidência da causa priorizada.'])->assertSessionHasNoErrors();
        $this->assertSame(PlanoAcao::RECUSADO, $plano->fresh()->status);
        $this->assertTrue($plano->fresh()->finalizado());
    }

    public function test_ajustes_voltam_ao_coordenador_que_edita_e_reenvia(): void
    {
        $coordenador = $this->coordenador();
        $plano = $this->enviado($coordenador);

        $this->actingAs($this->colaborador(), 'admin')->post("/colaboracao/planos/{$plano->id}/decisao", [
            'decisao' => 'ajustes', 'justificativa' => 'A ação não responde à causa-raiz; detalhe como será a devolutiva.', 'criterios' => ['dados'],
        ]);
        $this->assertSame(PlanoAcao::AJUSTES, $plano->fresh()->status);
        $this->assertSame(1, Notificacao::where('admin_id', $coordenador->id)->where('tipo', 'plano_ajustes')->count());

        // o coordenador vê o que foi pedido e os critérios não atendidos
        $this->actingAs($coordenador, 'admin')->get("/painel/planos/{$plano->id}/editar")->assertOk()
            ->assertSee('O colaborador pediu ajustes')->assertSee('detalhe como será a devolutiva')->assertSee('A causa-raiz é específica, acionável e tem evidências.');

        $this->put("/painel/planos/{$plano->id}", [...$this->dados(['causa_raiz' => 'Causa-raiz reescrita']), 'acao' => 'enviar']);

        $plano->refresh();
        $this->assertSame(PlanoAcao::EM_ANALISE, $plano->status);
        $this->assertSame(2, $plano->envios);
        $this->assertNull($plano->decidido_em);
        $this->assertContains(PlanoAcaoEvento::REENVIADO, $plano->eventos()->pluck('tipo')->all());
    }

    public function test_plano_ja_decidido_nao_pode_ser_decidido_de_novo(): void
    {
        $plano = $this->enviado();
        $colaborador = $this->colaborador();

        $this->actingAs($colaborador, 'admin')->post("/colaboracao/planos/{$plano->id}/decisao", ['decisao' => 'aprovar']);
        $this->post("/colaboracao/planos/{$plano->id}/decisao", ['decisao' => 'recusar', 'justificativa' => 'Mudei de ideia depois.'])
            ->assertRedirect(route('colaborador.planos.show', $plano))->assertSessionHas('erro');

        $this->assertSame(PlanoAcao::APROVADO, $plano->fresh()->status);
    }

    public function test_administrador_tambem_analisa(): void
    {
        $plano = $this->enviado();
        $admin = Admin::create(['username' => 'admin', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_ADMIN]);

        $this->actingAs($admin, 'admin')->get('/colaboracao/planos')->assertOk()->assertSee('Aguardando análise');
        $this->post("/colaboracao/planos/{$plano->id}/decisao", ['decisao' => 'aprovar'])->assertSessionHasNoErrors();

        $this->assertSame(PlanoAcao::APROVADO, $plano->fresh()->status);
    }

    // ---- quem vê o quê ----

    public function test_coordenador_nao_decide_e_nao_ve_plano_de_outro_curso(): void
    {
        $plano = $this->enviado();
        $outro = $this->coordenador('direito', 'DIREITO');

        $this->actingAs($outro, 'admin');
        $this->post("/colaboracao/planos/{$plano->id}/decisao", ['decisao' => 'aprovar'])->assertForbidden();
        $this->get("/painel/planos/{$plano->id}")->assertNotFound();
        $this->get("/painel/planos/{$plano->id}/editar")->assertNotFound();
        $this->post("/painel/planos/{$plano->id}/duplicar")->assertNotFound();
        $this->put("/painel/planos/{$plano->id}", $this->dados())->assertNotFound();
        $this->get('/painel/planos')->assertOk()->assertDontSee($plano->origem_rotulo);

        // e o dono, sim
        $this->actingAs($plano->autor, 'admin')->get("/painel/planos/{$plano->id}")->assertOk()->assertSee($plano->origem_rotulo);
    }

    public function test_dois_coordenadores_do_mesmo_curso_enxergam_o_mesmo_plano(): void
    {
        $plano = $this->enviado();
        $colega = $this->coordenador('colega', 'MEDICINA');

        $this->actingAs($colega, 'admin')->get("/painel/planos/{$plano->id}")->assertOk();
    }

    public function test_colaborador_nao_ve_rascunho_e_nao_acessa_o_painel_do_coordenador(): void
    {
        $this->avaliacao('D1', '2026-03-10');
        $rascunho = $this->criar($this->coordenador());

        $this->actingAs($this->colaborador(), 'admin');
        $this->get("/colaboracao/planos/{$rascunho->id}")->assertNotFound();
        $this->post("/colaboracao/planos/{$rascunho->id}/decisao", ['decisao' => 'aprovar'])->assertNotFound();
        $this->get('/colaboracao/planos?aba=todos')->assertOk()->assertDontSee($rascunho->origem_rotulo);
        $this->get('/painel/planos')->assertRedirect(route('colaborador.index'));
    }

    public function test_reitor_na_visao_do_curso_le_o_plano_mas_nao_grava(): void
    {
        $plano = $this->enviado();
        $reitor = $this->reitor();

        $this->actingAs($reitor, 'admin')->withSession(['visao_de_curso' => 'MEDICINA']);
        $this->get('/painel/planos')->assertOk()->assertSee($plano->origem_rotulo);
        $this->get("/painel/planos/{$plano->id}")->assertOk()->assertDontSee('Retirar da análise');
        $this->post("/painel/planos/{$plano->id}/retirar")->assertForbidden();
        $this->post("/painel/planos/{$plano->id}/comentarios", ['texto' => 'oi, aqui é o reitor'])->assertForbidden();
        $this->assertSame(PlanoAcao::EM_ANALISE, $plano->fresh()->status);
    }

    public function test_reitor_fora_da_visao_do_curso_nao_alcanca_os_planos(): void
    {
        $plano = $this->enviado();

        $this->actingAs($this->reitor(), 'admin')->get("/painel/planos/{$plano->id}")->assertRedirect(route('reitor.visao'));
        $this->get("/colaboracao/planos/{$plano->id}")->assertRedirect(route('reitor.visao'));
    }

    // ---- execução e acompanhamento ----

    private function aprovado(): array
    {
        $coordenador = $this->coordenador();
        $plano = $this->enviado($coordenador);
        $this->actingAs($this->colaborador(), 'admin')->post("/colaboracao/planos/{$plano->id}/decisao", ['decisao' => 'aprovar']);
        $this->actingAs($coordenador, 'admin');

        return [$plano->fresh()->load('acoes'), $coordenador];
    }

    public function test_concluir_uma_acao_exige_a_nota_de_evidencia(): void
    {
        [$plano] = $this->aprovado();
        $acao = $plano->acoes->first();

        $this->put("/painel/planos/{$plano->id}/acoes/{$acao->id}", ['status' => 'concluida'])->assertSessionHasErrors('nota');
        $this->assertSame(PlanoAcaoAcao::NAO_INICIADA, $acao->fresh()->status);

        $this->put("/painel/planos/{$plano->id}/acoes/{$acao->id}", ['status' => 'em_andamento', 'nota' => ''])->assertSessionHasNoErrors();
        $this->put("/painel/planos/{$plano->id}/acoes/{$acao->id}", ['status' => 'concluida', 'nota' => 'Casos aplicados em 4 turmas; 92% de presença.'])->assertSessionHasNoErrors();

        $acao->refresh();
        $this->assertSame(PlanoAcaoAcao::CONCLUIDA, $acao->status);
        $this->assertNotNull($acao->concluida_em);
        $this->assertSame(100, $plano->fresh()->load('acoes')->progresso()['pct']);
        $ultimo = $plano->eventos()->where('tipo', PlanoAcaoEvento::ACAO_STATUS)->latest('id')->first();
        $this->assertSame(['de' => 'em_andamento', 'para' => 'concluida'], $ultimo->dados);
    }

    public function test_reprogramar_o_prazo_exige_justificativa_e_fica_no_historico(): void
    {
        [$plano] = $this->aprovado();
        $acao = $plano->acoes->first();
        $novo = now()->addDays(45)->toDateString();

        $this->put("/painel/planos/{$plano->id}/acoes/{$acao->id}", ['prazo' => $novo])->assertSessionHasErrors('nota');
        $this->put("/painel/planos/{$plano->id}/acoes/{$acao->id}", ['prazo' => now()->subDay()->toDateString(), 'nota' => 'Querendo voltar no tempo'])->assertSessionHasErrors('prazo');

        $this->put("/painel/planos/{$plano->id}/acoes/{$acao->id}", ['prazo' => $novo, 'nota' => 'Calendário de provas mudou a quinzena.'])->assertSessionHasNoErrors();

        $this->assertSame($novo, $acao->fresh()->prazo->toDateString());
        $evento = $plano->eventos()->where('tipo', PlanoAcaoEvento::PRAZO)->firstOrFail();
        $this->assertSame($novo, $evento->dados['para']);
    }

    public function test_nota_de_andamento_sem_mudar_nada_vira_registro(): void
    {
        [$plano] = $this->aprovado();
        $acao = $plano->acoes->first();

        $this->put("/painel/planos/{$plano->id}/acoes/{$acao->id}", [])->assertSessionHasErrors('nota');
        $this->put("/painel/planos/{$plano->id}/acoes/{$acao->id}", ['nota' => 'Primeira reunião com os docentes realizada.']);

        $this->assertSame(PlanoAcaoEvento::ANDAMENTO, $plano->eventos()->first()->tipo);
    }

    public function test_acoes_so_sao_atualizadas_com_o_plano_em_execucao(): void
    {
        $plano = $this->enviado(); // ainda em análise
        $acao = $plano->acoes()->first();

        $this->put("/painel/planos/{$plano->id}/acoes/{$acao->id}", ['status' => 'em_andamento', 'nota' => 'Já comecei por conta própria'])
            ->assertRedirect(route('coordenador.planos.show', $plano))->assertSessionHas('erro');

        $this->assertSame(PlanoAcaoAcao::NAO_INICIADA, $acao->fresh()->status);
    }

    public function test_acao_de_outro_plano_responde_404(): void
    {
        [$plano] = $this->aprovado();
        $outra = $this->criar($plano->autor);

        $this->put("/painel/planos/{$plano->id}/acoes/{$outra->acoes()->first()->id}", ['status' => 'em_andamento', 'nota' => 'Tentando outra ação'])->assertNotFound();
    }

    public function test_encerrar_exige_acoes_fechadas_e_a_sintese(): void
    {
        [$plano] = $this->aprovado();
        $acao = $plano->acoes->first();

        $this->post("/painel/planos/{$plano->id}/encerrar", ['conclusao' => 'Plano executado e deu certo, aprendemos muito.'])->assertSessionHasErrors('conclusao');
        $this->assertSame(PlanoAcao::APROVADO, $plano->fresh()->status);

        $this->put("/painel/planos/{$plano->id}/acoes/{$acao->id}", ['status' => 'concluida', 'nota' => 'Casos aplicados em todas as turmas.']);

        $this->post("/painel/planos/{$plano->id}/encerrar", ['conclusao' => 'curto'])->assertSessionHasErrors('conclusao');
        $this->post("/painel/planos/{$plano->id}/encerrar", ['conclusao' => 'Casos aplicados em todas as turmas; docentes querem manter.']);

        $plano->refresh();
        $this->assertSame(PlanoAcao::CONCLUIDO, $plano->status);
        $this->assertNotNull($plano->encerrado_em);
        $this->get("/painel/planos/{$plano->id}")->assertOk()->assertSee('Síntese do encerramento');
    }

    public function test_cancelar_plano_em_execucao_exige_o_motivo(): void
    {
        [$plano] = $this->aprovado();

        $this->post("/painel/planos/{$plano->id}/cancelar", ['motivo' => 'x'])->assertSessionHasErrors('motivo');
        $this->post("/painel/planos/{$plano->id}/cancelar", ['motivo' => 'O curso mudou de coordenação e o foco é outro.']);

        $this->assertSame(PlanoAcao::CANCELADO, $plano->fresh()->status);
    }

    public function test_copia_de_um_plano_vira_rascunho_sem_prazos_nem_situacao(): void
    {
        [$plano, $coordenador] = $this->aprovado();
        $acao = $plano->acoes->first();
        $this->put("/painel/planos/{$plano->id}/acoes/{$acao->id}", ['status' => 'concluida', 'nota' => 'Casos aplicados em todas as turmas.']);

        $this->post("/painel/planos/{$plano->id}/duplicar")->assertRedirect();

        $copia = PlanoAcao::latest('id')->first();
        $this->assertNotSame($plano->id, $copia->id);
        $this->assertSame(PlanoAcao::RASCUNHO, $copia->status);
        $this->assertSame($plano->causa_raiz, $copia->causa_raiz);
        $this->assertSame(50.0, $copia->proficiencia_atual); // recalculada, não herdada
        $nova = $copia->acoes()->first();
        $this->assertSame($acao->descricao, $nova->descricao);
        $this->assertNull($nova->prazo);
        $this->assertSame(PlanoAcaoAcao::NAO_INICIADA, $nova->status);
        $this->assertSame($coordenador->id, $copia->admin_id);
    }

    public function test_comentario_do_colaborador_avisa_o_coordenador(): void
    {
        $coordenador = $this->coordenador();
        $plano = $this->enviado($coordenador);

        $this->actingAs($this->colaborador(), 'admin')->post("/colaboracao/planos/{$plano->id}/comentarios", ['texto' => 'Qual a turma-alvo da primeira ação?'])->assertSessionHasNoErrors();

        $this->assertSame(PlanoAcao::EM_ANALISE, $plano->fresh()->status);
        $this->assertSame(1, Notificacao::where('admin_id', $coordenador->id)->where('tipo', 'plano')->count());
        $this->actingAs($coordenador, 'admin')->get("/painel/planos/{$plano->id}")->assertSee('Qual a turma-alvo da primeira ação?');
    }

    // ---- resultado, lembretes, menu ----

    public function test_resultado_compara_a_linha_de_base_com_o_di_seguinte(): void
    {
        $this->avaliacao('D1', '2026-03-10'); // 2026/1 → proficiência 50%
        $plano = $this->criar($this->coordenador());

        $semProximo = app(PlanoAcaoResultadoService::class)->calcular($plano);
        $this->assertNull($semProximo['proximo']);
        $this->assertSame(50.0, $semProximo['base']['proficiencia']);

        $this->avaliacao('D2', '2026-09-10', [9, 9, 8, 8, 7, 6]); // 2026/2, mesma categoria → proficiência 100%
        $resultado = app(PlanoAcaoResultadoService::class)->calcular($plano);

        $this->assertSame('2026/2', $resultado['proximo']['periodo_letivo']);
        $this->assertSame(100.0, $resultado['proximo']['proficiencia']);
        $this->assertSame(50.0, $resultado['proximo']['dProficiencia']);
        $this->assertTrue($resultado['proximo']['metaProficiencia']); // meta de 70%
    }

    public function test_lembretes_avisam_prazo_proximo_vencido_e_nao_se_repetem(): void
    {
        [$plano, $coordenador] = $this->aprovado();
        $acao = $plano->acoes->first();

        $this->travel(29)->days(); // o prazo era hoje + 30
        $this->assertSame(1, app(PlanoAcaoLembreteService::class)->gerar());
        $this->assertSame('Ação com prazo próximo', Notificacao::where('admin_id', $coordenador->id)->where('tipo', 'plano_prazo')->first()->titulo);

        // rodar de novo não cria outro, nem volta a "não lido" o que já foi lido
        Notificacao::where('admin_id', $coordenador->id)->update(['lida_em' => now()]);
        $this->assertSame(0, app(PlanoAcaoLembreteService::class)->gerar());
        $this->assertNotNull(Notificacao::where('admin_id', $coordenador->id)->where('tipo', 'plano_prazo')->first()->lida_em);

        $this->travel(5)->days();
        Artisan::call('planos:lembretes');
        $this->assertSame(1, Notificacao::where('admin_id', $coordenador->id)->where('titulo', 'Ação com prazo vencido')->count());

        // ação concluída não gera lembrete
        $acao->update(['status' => PlanoAcaoAcao::CONCLUIDA]);
        $this->travel(10)->days();
        $antes = Notificacao::count();
        app(PlanoAcaoLembreteService::class)->gerar();
        $this->assertSame($antes, Notificacao::count());
    }

    public function test_plano_em_execucao_sem_movimento_e_lembrado(): void
    {
        [$plano, $coordenador] = $this->aprovado();
        $plano->acoes()->update(['prazo' => now()->addDays(300)->toDateString()]);

        $this->travel(PlanoAcaoLembreteService::DIAS_PARADO + 1)->days();

        $this->assertSame(1, app(PlanoAcaoLembreteService::class)->gerar());
        $this->assertSame('Plano sem atualização', Notificacao::where('admin_id', $coordenador->id)->where('tipo', 'plano_prazo')->first()->titulo);
        $this->assertTrue($plano->fresh()->load(['acoes', 'eventos'])->estaParado());
    }

    public function test_menu_mostra_quantos_planos_aguardam_e_quantos_voltaram(): void
    {
        $coordenador = $this->coordenador();
        $plano = $this->enviado($coordenador);

        $this->actingAs($this->colaborador(), 'admin')->get('/colaboracao/planos')->assertOk()->assertSee('1 aguardando análise');

        $this->post("/colaboracao/planos/{$plano->id}/decisao", ['decisao' => 'ajustes', 'justificativa' => 'Detalhe melhor a verificação.']);
        $this->actingAs($coordenador, 'admin')->get('/painel/planos')->assertOk()->assertSee('1 devolvido(s) para ajustes');
    }

    public function test_lembretes_estao_agendados(): void
    {
        Artisan::call('schedule:list');

        $this->assertStringContainsString('planos:lembretes', Artisan::output());
    }

    // ---- regras puras ----

    public function test_verbo_no_infinitivo(): void
    {
        foreach (['Implementar atividades', '  revisar o plano', '"Aplicar" teste', 'Rever os casos', 'Construir um banco', 'Repor aulas', 'Pôr em prática'] as $ok) {
            $this->assertTrue(PlanoAcaoChecagem::comecaComVerbo($ok), $ok);
        }
        foreach (['Atividades integradoras', 'Reunião com docentes', 'Implementação de casos', '', '123', 'Já'] as $nao) {
            $this->assertFalse(PlanoAcaoChecagem::comecaComVerbo($nao), $nao);
        }
    }

    public function test_alertas_apontam_prazo_depois_da_proxima_avaliacao_e_plano_so_de_reunioes(): void
    {
        $plano = new PlanoAcao(['data_proxima_avaliacao' => now()->addDays(20)->toDateString(), 'causa_raiz' => 'Falta de casos', 'nota_impacto' => 1, 'nota_evidencia' => 1, 'nota_governabilidade' => 2]);
        $plano->setRelation('acoes', collect([new PlanoAcaoAcao(['descricao' => 'Realizar reunião com docentes', 'prazo' => now()->addDays(40)->toDateString(), 'status' => 'nao_iniciada'])]));

        $alertas = implode(' | ', PlanoAcaoChecagem::alertas($plano));

        $this->assertStringContainsString('prazo depois da próxima avaliação', $alertas);
        $this->assertStringContainsString('articulação', $alertas);
        $this->assertStringContainsString('5 Porquês', $alertas);
        $this->assertStringContainsString('pontuação baixa (2 de 27)', $alertas);
    }

    public function test_plano_nao_guarda_dado_nominal_de_aluno(): void
    {
        $this->avaliacao('D1', '2026-03-10');
        $plano = $this->criar($this->coordenador());

        $guardado = json_encode($plano->fresh()->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Aluno 90', $guardado);
        $this->assertStringNotContainsString('"ra"', $guardado);
    }
}
