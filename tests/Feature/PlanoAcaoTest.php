<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\Configuracao;
use App\Models\Notificacao;
use App\Models\PlanoAcao;
use App\Models\PlanoAcaoAcao;
use App\Models\PlanoAcaoEvento;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\PlanoAcaoLembreteService;
use App\Services\Portal\SmtpEmailSender;
use App\Services\PlanoAcaoResultadoService;
use App\Services\ResumoResultadoService;
use App\Support\PlanoAcaoChecagem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
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

    private ?Admin $colaboradorCriado = null;

    private function colaborador(): Admin
    {
        return $this->colaboradorCriado ??= Admin::create(['username' => 'colab', 'email' => 'colab@example.test', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_COLABORADOR]);
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

    public function test_nenhuma_etapa_e_obrigatoria_enviar_incompleto_funciona_e_as_lacunas_so_informam(): void
    {
        $this->avaliacao('D1', '2026-03-10');

        $plano = $this->criar($this->coordenador(), ['causa_raiz' => '', 'acoes' => [['descricao' => 'Reunião com docentes', 'execucao' => '', 'responsavel' => '', 'prazo' => '', 'verificacao' => '']]], 'enviar');

        $plano->refresh();
        $this->assertSame(PlanoAcao::EM_ANALISE, $plano->status);
        $this->assertSame(1, $plano->envios);

        $lacunas = collect(PlanoAcaoChecagem::lacunas($plano->load('acoes')))->pluck('mensagem')->implode(' | ');
        $this->assertStringContainsString('causa-raiz', $lacunas);
        $this->assertStringContainsString('inicie com um verbo no infinitivo', $lacunas);
        $this->assertStringContainsString('informe o responsável', $lacunas);
        $this->assertStringContainsString('informe o prazo', $lacunas);

        // o colaborador vê o que ficou em branco, mas pode analisar normalmente
        $this->actingAs($this->colaborador(), 'admin')->get("/colaboracao/planos/{$plano->id}")->assertOk()
            ->assertSee('Pontos deixados em branco pelo coordenador')->assertSee('informe o responsável');
    }

    public function test_plano_totalmente_vazio_pode_ser_salvo_enviado_e_aprovado(): void
    {
        $this->avaliacao('D1', '2026-03-10');
        $coordenador = $this->coordenador();

        $this->actingAs($coordenador, 'admin')->post('/painel/planos', [
            'acao' => 'enviar',
            'origem' => ['curso' => 'MEDICINA', 'periodo_letivo' => '2026/1', 'categoria' => $this->categoria->id, 'visual' => 'geral'],
        ])->assertSessionHasNoErrors();

        $plano = PlanoAcao::firstOrFail();
        $this->assertSame(PlanoAcao::EM_ANALISE, $plano->status);
        $this->assertSame(0, $plano->acoes()->count());

        $this->get("/painel/planos/{$plano->id}")->assertOk()->assertSee('Não informado');
        $this->actingAs($this->colaborador(), 'admin')->post("/colaboracao/planos/{$plano->id}/decisao", ['decisao' => 'aprovar'])->assertSessionHasNoErrors();
        $this->assertSame(PlanoAcao::APROVADO, $plano->fresh()->status);

        $this->actingAs($coordenador, 'admin')->get("/painel/planos/{$plano->id}")->assertOk();
        $this->get('/painel/planos')->assertOk()->assertSee($plano->origem_rotulo);
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

    public function test_prazo_no_passado_e_acao_sem_verbo_viram_lacunas_e_nao_impedem_o_envio(): void
    {
        $this->avaliacao('D1', '2026-03-10');

        $plano = $this->criar($this->coordenador(), ['acoes' => [[
            'descricao' => 'Atividades integradoras', 'execucao' => 'Quinzenal', 'responsavel' => 'Ana', 'prazo' => now()->subDay()->toDateString(), 'verificacao' => 'Mini-teste',
        ]]], 'enviar');

        $lacunas = collect(PlanoAcaoChecagem::lacunas($plano->fresh()->load('acoes')))->pluck('mensagem')->implode(' | ');
        $this->assertSame(PlanoAcao::EM_ANALISE, $plano->fresh()->status);
        $this->assertStringContainsString('verbo no infinitivo', $lacunas);
        $this->assertStringContainsString('o prazo já passou', $lacunas);
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

    // ---- link para a avaliação ----

    public function test_plano_guarda_as_avaliacoes_do_recorte_e_cada_perfil_ve_o_que_pode_abrir(): void
    {
        $d1 = $this->avaliacao('D1', '2026-03-10');
        $this->avaliacao('D2', '2026-09-10'); // outro período letivo: fora do recorte
        $plano = $this->criar($this->coordenador(), [], 'enviar');

        $this->assertSame([$d1->codigo], array_column($plano->fresh()->avaliacoesDoRecorte(), 'codigo'));

        $link = route('avaliacoes.bi', $d1->codigo);
        // o coordenador do curso abre o Dashboard da avaliação, e também volta ao painel de desempenho do recorte
        $this->actingAs($plano->autor, 'admin')->get("/painel/planos/{$plano->id}")->assertOk()
            ->assertSee('Avaliação do plano')->assertSee('D1')->assertSee($link, false)->assertSee('Ver este dado no painel de desempenho');
        // o administrador também abre
        $admin = Admin::create(['username' => 'adm', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_ADMIN]);
        $this->actingAs($admin, 'admin')->get("/colaboracao/planos/{$plano->id}")->assertOk()->assertSee($link, false);
        // o colaborador também abre o Dashboard (já tem acesso às planilhas importadas), só leitura
        $this->actingAs($this->colaborador(), 'admin')->get("/colaboracao/planos/{$plano->id}")->assertOk()
            ->assertSee('D1')->assertSee('#'.$d1->codigo)->assertSee($link, false);
        $this->get($link)->assertOk();
        $this->get('/avaliacoes')->assertOk();
        // ...mas não gerencia: tela de edição da avaliação e dados do aluno continuam fora
        $this->get("/avaliacoes/{$d1->codigo}")->assertForbidden();
        $this->get('/alunos')->assertForbidden();
    }

    public function test_formulario_mostra_as_avaliacoes_do_recorte_com_link(): void
    {
        $d1 = $this->avaliacao('D1', '2026-03-10');

        $this->actingAs($this->coordenador(), 'admin')->get('/painel/planos/novo?visual=geral&periodo_letivo=2026/1&categoria='.$this->categoria->id)->assertOk()
            ->assertSee('Avaliação do plano')->assertSee(route('avaliacoes.bi', $d1->codigo), false)->assertSee('target="_blank"', false);
    }

    public function test_plano_de_avaliacao_especifica_aponta_so_para_ela(): void
    {
        $this->avaliacao('D1', '2026-03-10');
        $d2 = $this->avaliacao('D1b', '2026-04-10');

        $origem = $this->actingAs($this->coordenador(), 'admin')->get("/painel/planos/novo?visual=avaliacao&avaliacao={$d2->codigo}")->assertOk()->viewData('origem');

        $this->assertSame([$d2->codigo], array_column($origem['avaliacoes'], 'codigo'));
    }

    // ---- melhorias do processo ----

    public function test_avisa_quando_ja_existe_plano_sobre_o_mesmo_recorte(): void
    {
        $this->avaliacao('D1', '2026-03-10');
        $coordenador = $this->coordenador();
        $existente = $this->criar($coordenador); // visual=area, item=Clínica

        $url = '/painel/planos/novo?visual=area&item=Clínica&periodo_letivo=2026/1&categoria='.$this->categoria->id;
        $this->actingAs($coordenador, 'admin')->get($url)->assertOk()->assertSee('Já existe plano sobre este mesmo recorte')->assertSee(route('coordenador.planos.show', $existente), false);

        // outro item ou um plano já recusado: sem aviso
        $this->get('/painel/planos/novo?visual=area&item=Outra&periodo_letivo=2026/1&categoria='.$this->categoria->id)->assertDontSee('Já existe plano');
        $existente->update(['status' => PlanoAcao::RECUSADO]);
        $this->get($url)->assertDontSee('Já existe plano');
    }

    public function test_tela_do_plano_tem_botao_de_imprimir(): void
    {
        $plano = $this->enviado();

        $this->actingAs($plano->autor, 'admin')->get("/painel/planos/{$plano->id}")->assertOk()->assertSee('Imprimir / salvar em PDF');
        $this->actingAs($this->colaborador(), 'admin')->get("/colaboracao/planos/{$plano->id}")->assertOk()->assertSee('Imprimir / salvar em PDF');
    }

    /** Um SmtpEmailSender que só guarda o que seria enviado (ou falha). */
    private function remetenteFalso(bool $falha = false): object
    {
        $falso = new class($falha) extends SmtpEmailSender
        {
            /** @var array<int, array{0: string, 1: string, 2: string}> */
            public array $enviados = [];

            public function __construct(private readonly bool $falha) {}

            public function enviar(string $destinatario, string $assunto, string $corpoHtml): void
            {
                if ($this->falha) {
                    throw new \RuntimeException('SMTP fora do ar');
                }
                $this->enviados[] = [$destinatario, $assunto, $corpoHtml];
            }
        };
        $this->app->instance(SmtpEmailSender::class, $falso);

        return $falso;
    }

    private function configurarSmtp(): void
    {
        Configuracao::definir('smtp_ativo', '1');
        Configuracao::definir('smtp_host', 'smtp.example.test');
        Configuracao::definir('smtp_from_email', 'avisos@example.test');
    }

    public function test_email_avisa_o_colaborador_do_plano_enviado_e_o_coordenador_da_decisao(): void
    {
        $this->configurarSmtp();
        $falso = $this->remetenteFalso();
        $this->avaliacao('D1', '2026-03-10');
        $coordenador = $this->coordenador();
        $colaborador = $this->colaborador();

        $this->criar($coordenador, [], 'enviar');
        $plano = PlanoAcao::firstOrFail();

        $this->assertCount(1, $falso->enviados);
        $this->assertSame('colab@example.test', $falso->enviados[0][0]);
        $this->assertStringContainsString('Novo plano de ação para análise', $falso->enviados[0][1]);
        $this->assertStringContainsString(route('colaborador.planos.show', $plano), $falso->enviados[0][2]);

        $this->actingAs($colaborador, 'admin')->post("/colaboracao/planos/{$plano->id}/decisao", ['decisao' => 'ajustes', 'justificativa' => 'Detalhe melhor a verificação.']);

        $this->assertCount(2, $falso->enviados);
        $this->assertSame('coord@example.test', $falso->enviados[1][0]);
        $this->assertStringContainsString('devolvido para ajustes', $falso->enviados[1][1]);
        $this->assertStringContainsString('Detalhe melhor a verificação.', $falso->enviados[1][2]);

        $this->actingAs($coordenador, 'admin')->post("/painel/planos/{$plano->id}/comentarios", ['texto' => 'Vou detalhar hoje.']);
        $this->assertSame('colab@example.test', end($falso->enviados)[0]);
    }

    public function test_sem_smtp_configurado_nao_envia_e_falha_de_envio_nao_quebra_o_fluxo(): void
    {
        $falso = $this->remetenteFalso();
        $this->avaliacao('D1', '2026-03-10');
        $colaborador = $this->colaborador();

        $plano = $this->criar($this->coordenador(), [], 'enviar');
        $this->assertSame([], $falso->enviados); // SMTP não configurado
        $this->assertSame(PlanoAcao::EM_ANALISE, $plano->fresh()->status);

        $this->configurarSmtp();
        $this->remetenteFalso(falha: true);
        $this->actingAs($colaborador, 'admin')->post("/colaboracao/planos/{$plano->id}/decisao", ['decisao' => 'aprovar'])->assertSessionHasNoErrors();
        $this->assertSame(PlanoAcao::APROVADO, $plano->fresh()->status);
    }

    // ---- evidências anexas ----

    public function test_evidencia_em_link_e_arquivo_acompanha_a_atualizacao_e_aparece_no_historico(): void
    {
        Storage::fake('local');
        [$plano, $coordenador] = $this->aprovado();
        $acao = $plano->acoes->first();

        $this->put("/painel/planos/{$plano->id}/acoes/{$acao->id}", [
            'status' => 'concluida',
            'nota' => 'Oficina realizada com as quatro turmas.',
            'link_url' => 'https://drive.example.test/pasta-oficina',
            'link_titulo' => 'Fotos da oficina',
            'arquivo' => UploadedFile::fake()->create('lista-de-presenca.pdf', 120, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $anexos = $plano->anexos()->get();
        $this->assertCount(2, $anexos);
        $link = $anexos->firstWhere('tipo', 'link');
        $arquivo = $anexos->firstWhere('tipo', 'arquivo');
        $this->assertSame('Fotos da oficina', $link->titulo);
        $this->assertSame($acao->id, $arquivo->acao_id);
        $this->assertSame('lista-de-presenca.pdf', $arquivo->nome_original);
        // disco PRIVADO: nada em public/
        Storage::disk('local')->assertExists($arquivo->caminho);
        $this->assertStringStartsWith('planos/'.$plano->id.'/', $arquivo->caminho);
        // ligado ao evento do histórico
        $this->assertSame(PlanoAcaoEvento::ACAO_STATUS, $plano->eventos()->latest('id')->first()->tipo);
        $this->assertSame($plano->eventos()->latest('id')->first()->id, $arquivo->evento_id);

        $this->get("/painel/planos/{$plano->id}")->assertOk()->assertSee('Fotos da oficina')->assertSee('lista-de-presenca.pdf')
            ->assertSee(route('coordenador.planos.anexos.show', [$plano, $arquivo]), false);
    }

    public function test_anexo_sozinho_vale_como_registro_de_andamento(): void
    {
        Storage::fake('local');
        [$plano] = $this->aprovado();
        $acao = $plano->acoes->first();

        $this->put("/painel/planos/{$plano->id}/acoes/{$acao->id}", ['link_url' => 'https://exemplo.test/video'])->assertSessionHasNoErrors();

        $this->assertSame(PlanoAcaoEvento::ANDAMENTO, $plano->eventos()->first()->tipo);
        $this->assertSame(1, $plano->anexos()->count());
    }

    public function test_arquivo_baixa_por_rota_autenticada_para_coordenador_e_colaborador_e_nao_para_outro_curso(): void
    {
        Storage::fake('local');
        [$plano, $coordenador] = $this->aprovado();
        $acao = $plano->acoes->first();
        $this->put("/painel/planos/{$plano->id}/acoes/{$acao->id}", ['nota' => 'Primeiro registro.', 'arquivo' => UploadedFile::fake()->create('relatorio.docx', 50)]);
        $anexo = $plano->anexos()->firstOrFail();

        $this->get("/painel/planos/{$plano->id}/anexos/{$anexo->id}")->assertOk()->assertDownload('relatorio.docx');
        $this->actingAs($this->colaborador(), 'admin')->get("/colaboracao/planos/{$plano->id}/anexos/{$anexo->id}")->assertOk()->assertDownload('relatorio.docx');

        // outro curso: 404; sem login: login; o anexo de outro plano não abre por este plano
        $this->actingAs($this->coordenador('direito', 'DIREITO'), 'admin')->get("/painel/planos/{$plano->id}/anexos/{$anexo->id}")->assertNotFound();
        $outro = PlanoAcao::create(['admin_id' => $coordenador->id, 'curso' => 'MEDICINA', 'origem_visual' => 'geral', 'origem_rotulo' => 'x', 'status' => PlanoAcao::APROVADO]);
        $this->actingAs($coordenador, 'admin')->get("/painel/planos/{$outro->id}/anexos/{$anexo->id}")->assertNotFound();
    }

    public function test_so_aceita_link_http_e_arquivo_de_tipo_permitido(): void
    {
        Storage::fake('local');
        [$plano] = $this->aprovado();
        $acao = $plano->acoes->first();
        $url = "/painel/planos/{$plano->id}/acoes/{$acao->id}";

        $this->put($url, ['nota' => 'Registro de andamento.', 'link_url' => 'javascript:alert(1)'])->assertSessionHasErrors('link_url');
        $this->put($url, ['nota' => 'Registro de andamento.', 'arquivo' => UploadedFile::fake()->create('virus.exe', 10)])->assertSessionHasErrors('arquivo');
        $this->put($url, ['nota' => 'Registro de andamento.', 'arquivo' => UploadedFile::fake()->create('enorme.pdf', 20000, 'application/pdf')])->assertSessionHasErrors('arquivo');

        $this->assertSame(0, $plano->anexos()->count());
    }

    public function test_encerramento_aceita_evidencia_e_ela_aparece_na_sintese(): void
    {
        Storage::fake('local');
        [$plano] = $this->aprovado();
        $acao = $plano->acoes->first();
        $this->put("/painel/planos/{$plano->id}/acoes/{$acao->id}", ['status' => 'concluida', 'nota' => 'Casos aplicados em todas as turmas.']);

        $this->post("/painel/planos/{$plano->id}/encerrar", [
            'conclusao' => 'Casos aplicados em todas as turmas; docentes querem manter.',
            'link_url' => 'https://exemplo.test/relatorio-final', 'link_titulo' => 'Relatório final',
        ])->assertSessionHasNoErrors();

        $this->assertSame(PlanoAcao::CONCLUIDO, $plano->fresh()->status);
        $this->get("/painel/planos/{$plano->id}")->assertOk()->assertSee('Síntese do encerramento')->assertSee('Relatório final');
        $this->actingAs($this->colaborador(), 'admin')->get("/colaboracao/planos/{$plano->id}")->assertOk()->assertSee('Relatório final');
    }

    // ---- banco de ações ----

    public function test_banco_de_acoes_sugere_acoes_concluidas_de_planos_concluidos_sem_identificar_o_curso(): void
    {
        $this->avaliacao('D1', '2026-03-10');
        $outroCurso = $this->coordenador('direito', 'DIREITO');
        $concluido = PlanoAcao::create(['admin_id' => $outroCurso->id, 'curso' => 'DIREITO', 'origem_visual' => 'area', 'origem_item' => 'Clínica', 'origem_rotulo' => 'x', 'status' => PlanoAcao::CONCLUIDO, 'categoria_id' => $this->categoria->id]);
        $concluido->acoes()->createMany([
            ['descricao' => 'Implementar casos clínicos semanais', 'execucao' => 'Um caso por semana', 'verificacao' => 'Mini-teste', 'responsavel' => 'Profa. Secreta', 'status' => 'concluida', 'concluida_em' => now()],
            ['descricao' => 'Criar monitoria', 'status' => 'nao_iniciada'], // não concluída: fora
        ]);
        // plano ainda em execução ou de outro item: fora
        $emExecucao = PlanoAcao::create(['admin_id' => $outroCurso->id, 'curso' => 'DIREITO', 'origem_visual' => 'area', 'origem_item' => 'Clínica', 'origem_rotulo' => 'y', 'status' => PlanoAcao::APROVADO]);
        $emExecucao->acoes()->create(['descricao' => 'Ação ainda em andamento', 'status' => 'concluida', 'concluida_em' => now()]);
        $outroItem = PlanoAcao::create(['admin_id' => $outroCurso->id, 'curso' => 'DIREITO', 'origem_visual' => 'area', 'origem_item' => 'Pediatria', 'origem_rotulo' => 'z', 'status' => PlanoAcao::CONCLUIDO]);
        $outroItem->acoes()->create(['descricao' => 'Ação de outra área', 'status' => 'concluida', 'concluida_em' => now()]);

        $url = '/painel/planos/novo?visual=area&item=Clínica&periodo_letivo=2026/1&categoria='.$this->categoria->id;
        $resposta = $this->actingAs($this->coordenador(), 'admin')->get($url)->assertOk();

        $resposta->assertSee('Ideias de planos já concluídos (1)')->assertSee('Implementar casos clínicos semanais')->assertSee('Um caso por semana');
        $resposta->assertDontSee('Criar monitoria')->assertDontSee('Ação ainda em andamento')->assertDontSee('Ação de outra área');
        // anônimo: nem o curso do plano de origem, nem o responsável
        $resposta->assertDontSee('Profa. Secreta')->assertDontSee('DIREITO');
        $this->assertSame(1, count($resposta->viewData('bancoDeAcoes')));
    }

    public function test_banco_de_acoes_junta_acoes_iguais_e_conta_quantas_vezes_foram_usadas(): void
    {
        $banco = app(\App\Services\PlanoAcaoBancoDeAcoes::class);
        $autor = $this->coordenador();
        foreach (['MEDICINA', 'DIREITO'] as $curso) {
            $p = PlanoAcao::create(['admin_id' => $autor->id, 'curso' => $curso, 'origem_visual' => 'bloom', 'origem_item' => 'Analisar', 'origem_rotulo' => 'x', 'status' => PlanoAcao::CONCLUIDO]);
            $p->acoes()->create(['descricao' => '  Aplicar   estudos de caso ', 'status' => 'concluida', 'concluida_em' => now()]);
        }

        $ideias = $banco->sugerir('bloom', 'Analisar');

        $this->assertCount(1, $ideias);
        $this->assertSame(2, $ideias[0]['vezes']);
        $this->assertSame([], $banco->sugerir('bloom', 'Lembrar'));
    }

    // ---- o que mudou no reenvio ----

    public function test_reenvio_mostra_ao_colaborador_so_o_que_mudou(): void
    {
        $coordenador = $this->coordenador();
        $plano = $this->enviado($coordenador);

        // primeiro envio: nada para comparar
        $this->actingAs($this->colaborador(), 'admin')->get("/colaboracao/planos/{$plano->id}")->assertOk()->assertDontSee('O que mudou desde o envio anterior');

        $this->post("/colaboracao/planos/{$plano->id}/decisao", ['decisao' => 'ajustes', 'justificativa' => 'Detalhe melhor a causa-raiz e a verificação.']);

        $acaoId = $plano->acoes()->first()->id;
        $this->actingAs($coordenador, 'admin')->put("/painel/planos/{$plano->id}", [
            ...$this->dados([
                'causa_raiz' => 'Causa-raiz reescrita com mais evidências',
                'acoes' => [
                    ['id' => $acaoId, 'descricao' => 'Implementar casos clínicos integrados nas aulas', 'execucao' => 'Um caso por quinzena, com devolutiva',
                        'responsavel' => 'Profa. Ana', 'prazo' => now()->addDays(30)->toDateString(), 'verificacao' => 'Rubrica de avaliação de cada caso'],
                    ['descricao' => 'Criar monitoria de casos', 'execucao' => 'Duas vezes por semana', 'responsavel' => 'Monitor', 'prazo' => now()->addDays(40)->toDateString(), 'verificacao' => 'Lista de presença'],
                ],
            ]),
            'acao' => 'enviar',
        ])->assertSessionHasNoErrors();

        $plano->refresh();
        $this->assertSame(2, $plano->envios);

        $this->actingAs($this->colaborador(), 'admin')->get("/colaboracao/planos/{$plano->id}")->assertOk()
            ->assertSee('O que mudou desde o envio anterior')
            ->assertSee('Causa-raiz')->assertSee('Causa-raiz reescrita com mais evidências')->assertSee('Plano de ensino sem casos clínicos integrados')
            ->assertSee('Rubrica de avaliação de cada caso')->assertSee('Criar monitoria de casos')->assertSee('Nova');
        // o que não mudou não aparece como mudança
        $comparacao = \App\Support\PlanoAcaoComparacao::doUltimoEnvio($plano->load('eventos'));
        $rotulos = array_column($comparacao['mudancas'], 'rotulo');
        $this->assertSame(['Causa-raiz'], $rotulos);
        $this->assertSame(['alterada', 'nova'], array_column($comparacao['acoes'], 'situacao'));
    }

    public function test_reenvio_sem_alteracao_avisa_que_o_conteudo_e_o_mesmo(): void
    {
        $coordenador = $this->coordenador();
        $plano = $this->enviado($coordenador);
        $this->actingAs($this->colaborador(), 'admin')->post("/colaboracao/planos/{$plano->id}/decisao", ['decisao' => 'ajustes', 'justificativa' => 'Não concordo com a meta.']);

        // reenvia sem mexer em nada
        $this->actingAs($coordenador, 'admin')->put("/painel/planos/{$plano->id}", [
            ...$this->dados(['acoes' => [['id' => $plano->acoes()->first()->id, ...$this->dados()['acoes'][0]]]]), 'acao' => 'enviar',
        ]);

        $this->actingAs($this->colaborador(), 'admin')->get("/colaboracao/planos/{$plano->id}")->assertOk()->assertSee('O conteúdo do plano é o mesmo do envio anterior');
    }

    // ---- selo "já existe plano" nos visuais ----

    public function test_icone_do_visual_ganha_selo_quando_ja_existe_plano_sobre_o_dado(): void
    {
        $this->avaliacao('D1', '2026-03-10');
        $coordenador = $this->coordenador();
        $url = '/painel/desempenho?periodo_letivo=2026/1&categoria='.$this->categoria->id;

        $antes = $this->actingAs($coordenador, 'admin')->get($url)->assertOk()->getContent();
        $this->assertStringNotContainsString('Já existe', $antes);
        $this->assertStringNotContainsString('já tem plano', $antes);

        $this->criar($coordenador); // visual=area, item=Clínica, período e categoria deste recorte

        $depois = $this->get($url)->assertOk()->getContent();
        // o ícone do gráfico de área ganha o selo e o item Clínica marca o plano; os demais visuais seguem sem selo
        $this->assertStringContainsString('Já existe 1 plano sobre este dado', $depois);
        $this->assertStringContainsString("rascunho</span>", $depois);
        $this->assertSame(1, substr_count($depois, 'title="Já existe 1 plano sobre este dado"'));
        $this->assertStringNotContainsString('já tem plano', $depois);

        // plano encerrado/recusado não conta; outro período letivo também não
        PlanoAcao::query()->update(['status' => PlanoAcao::RECUSADO]);
        $this->get($url)->assertDontSee('Já existe 1 plano');
    }

    public function test_selo_so_considera_planos_do_proprio_curso_e_recorte(): void
    {
        $this->avaliacao('D1', '2026-03-10');
        $coordenador = $this->coordenador();
        $this->criar($coordenador);
        PlanoAcao::query()->update(['periodo_letivo' => '2025/2']);

        $this->get('/painel/desempenho?periodo_letivo=2026/1&categoria='.$this->categoria->id)->assertOk()->assertDontSee('Já existe 1 plano');
    }

    // ---- mais lembretes ----

    public function test_lembra_do_rascunho_parado_e_do_plano_devolvido_que_espera_ajustes(): void
    {
        $this->avaliacao('D1', '2026-03-10');
        $coordenador = $this->coordenador();
        $rascunho = $this->criar($coordenador);
        $devolvido = $this->enviado($coordenador);
        $this->actingAs($this->colaborador(), 'admin')->post("/colaboracao/planos/{$devolvido->id}/decisao", ['decisao' => 'ajustes', 'justificativa' => 'Detalhe melhor a verificação.']);
        Notificacao::query()->delete();

        // novos demais: nada
        $this->assertSame(0, app(PlanoAcaoLembreteService::class)->gerar());

        $this->travel(PlanoAcaoLembreteService::DIAS_RASCUNHO + 1)->days();
        $this->assertSame(2, app(PlanoAcaoLembreteService::class)->gerar());
        $titulos = Notificacao::where('admin_id', $coordenador->id)->pluck('titulo')->all();
        $this->assertContains('Rascunho de plano parado', $titulos);
        $this->assertContains('Plano aguardando os seus ajustes', $titulos);

        // uma vez só por mês
        $this->assertSame(0, app(PlanoAcaoLembreteService::class)->gerar());
        $this->assertNotNull($rascunho->fresh());
    }

    public function test_colaborador_recebe_resumo_por_email_dos_planos_que_passaram_do_prazo_de_analise(): void
    {
        $this->configurarSmtp();
        $falso = $this->remetenteFalso();
        $this->colaborador();
        $plano = $this->enviado();
        $falso->enviados = []; // descarta o e-mail do envio

        $this->travel(PlanoAcaoLembreteService::PRAZO_ANALISE_DIAS - 1)->days();
        $this->assertSame(0, app(PlanoAcaoLembreteService::class)->gerar());

        $this->travel(2)->days();
        $this->assertSame(1, app(PlanoAcaoLembreteService::class)->gerar());
        $this->assertCount(1, $falso->enviados);
        $this->assertSame('colab@example.test', $falso->enviados[0][0]);
        $this->assertStringContainsString('aguardando análise há mais de 7 dias', $falso->enviados[0][1]);
        $this->assertStringContainsString(route('colaborador.planos.show', $plano), $falso->enviados[0][2]);
        $this->assertContains(PlanoAcaoEvento::LEMBRETE, $plano->eventos()->pluck('tipo')->all());

        // não repete antes de outro prazo
        $this->travel(1)->days();
        $this->assertSame(0, app(PlanoAcaoLembreteService::class)->gerar());
        $this->travel(PlanoAcaoLembreteService::PRAZO_ANALISE_DIAS)->days();
        $this->assertSame(1, app(PlanoAcaoLembreteService::class)->gerar());
    }

    // ---- exportação, quadro por curso, reitoria, busca ----

    /** @return \PhpOffice\PhpSpreadsheet\Spreadsheet a planilha baixada */
    private function planilhaBaixada(\Illuminate\Testing\TestResponse $resposta): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $resposta->assertOk();
        $caminho = tempnam(sys_get_temp_dir(), 'plano').'.xlsx';
        file_put_contents($caminho, $resposta->streamedContent());

        return \PhpOffice\PhpSpreadsheet\IOFactory::load($caminho);
    }

    public function test_coordenador_e_colaborador_exportam_planos_em_xlsx_sem_executar_formulas(): void
    {
        $this->avaliacao('D1', '2026-03-10');
        $coordenador = $this->coordenador();
        $this->criar($coordenador, ['causa_raiz' => '=HYPERLINK("http://evil.test","clique")'], 'enviar');
        $rascunho = $this->criar($coordenador);

        // coordenador: os planos do curso dele, rascunho incluído
        $planilha = $this->planilhaBaixada($this->actingAs($coordenador, 'admin')->get('/painel/planos/exportar.xlsx'));
        $planos = $planilha->getSheetByName('Planos');
        $this->assertSame(3, $planos->getHighestRow()); // cabeçalho + 2
        $this->assertSame('Curso', $planos->getCell('B1')->getValue());
        $this->assertSame('MEDICINA', $planos->getCell('B2')->getValue());
        // a causa-raiz que parece fórmula virou TEXTO (coluna R)
        $celula = $planos->getCell('R2');
        $this->assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING, $celula->getDataType());
        $this->assertStringStartsWith('=HYPERLINK', $celula->getValue());
        $acoes = $planilha->getSheetByName('Ações');
        $this->assertSame('Implementar casos clínicos integrados nas aulas', $acoes->getCell('E2')->getValue());

        // colaborador: só os enviados, de todos os cursos; filtro por situação
        $this->actingAs($this->colaborador(), 'admin');
        $todos = $this->planilhaBaixada($this->get('/colaboracao/planos/exportar.xlsx'))->getSheetByName('Planos');
        $this->assertSame(2, $todos->getHighestRow()); // o rascunho não sai
        $vazia = $this->planilhaBaixada($this->get('/colaboracao/planos/exportar.xlsx?aba=execucao'))->getSheetByName('Planos');
        $this->assertSame(1, $vazia->getHighestRow());
        $this->assertNotNull($rascunho);
    }

    public function test_coordenador_de_outro_curso_nao_exporta_planos_alheios(): void
    {
        $this->enviado();

        $planilha = $this->planilhaBaixada($this->actingAs($this->coordenador('direito', 'DIREITO'), 'admin')->get('/painel/planos/exportar.xlsx'));

        $this->assertSame(1, $planilha->getSheetByName('Planos')->getHighestRow());
    }

    public function test_reitoria_ve_so_numeros_por_curso_e_nunca_o_texto_dos_planos(): void
    {
        [$plano] = $this->aprovado();
        $acao = $plano->acoes->first();
        $this->put("/painel/planos/{$plano->id}/acoes/{$acao->id}", ['status' => 'concluida', 'nota' => 'Casos aplicados em todas as turmas.']);
        $plano->update(['causa_raiz' => 'TEXTO CONFIDENCIAL DA CAUSA-RAIZ']);

        $html = $this->actingAs($this->reitor(), 'admin')->get('/reitoria/planos')->assertOk()
            ->assertSee('Planos de ação')->assertSee('MEDICINA')->assertSee('1/1 (100%)')->assertSee('Aprovados dos decididos')
            ->getContent();

        $this->assertStringNotContainsString('TEXTO CONFIDENCIAL', $html);
        $this->assertStringNotContainsString('Implementar casos clínicos', $html);
        $this->assertStringNotContainsString('Profa. Ana', $html);

        // o menu do reitor tem o link; coordenador e colaborador não entram
        $this->assertStringContainsString(route('reitor.planos'), $html);
        $this->actingAs($plano->autor, 'admin')->get('/reitoria/planos')->assertForbidden();
        $this->actingAs($this->colaborador(), 'admin')->get('/reitoria/planos')->assertRedirect(route('colaborador.index'));
    }

    public function test_quadro_por_curso_conta_situacoes_acoes_atrasadas_e_tempo_de_analise(): void
    {
        $this->avaliacao('D1', '2026-03-10');
        $coordenador = $this->coordenador();
        $this->criar($coordenador, [], 'enviar'); // em análise
        $aprovado = $this->criar($coordenador, [], 'enviar');
        $this->travel(3)->days();
        $this->actingAs($this->colaborador(), 'admin')->post("/colaboracao/planos/{$aprovado->id}/decisao", ['decisao' => 'aprovar']);
        $aprovado->acoes()->update(['prazo' => now()->subDay()->toDateString()]);

        $planos = PlanoAcao::enviados()->with(['acoes', 'eventos'])->get();
        $quadro = app(\App\Services\PlanoAcaoQuadroService::class);
        $linhas = $quadro->porCurso($planos);

        $this->assertCount(1, $linhas);
        $this->assertSame(2, $linhas[0]['total']);
        $this->assertSame(1, $linhas[0]['em_analise']);
        $this->assertSame(1, $linhas[0]['em_execucao']);
        $this->assertSame(1, $linhas[0]['acoes_atrasadas']);
        $this->assertSame(3.0, $linhas[0]['dias_analise']);
        $totais = $quadro->totais($linhas, $planos);
        $this->assertSame(100, $totais['taxa_aprovacao']);

        $this->get('/colaboracao/planos')->assertOk()->assertSee('Quadro por curso');
    }

    public function test_busca_na_fila_e_outros_planos_do_curso_na_analise(): void
    {
        $this->avaliacao('D1', '2026-03-10');
        $coordenador = $this->coordenador();
        $a = $this->criar($coordenador, ['causa_raiz' => 'Falta de monitoria em casos clínicos'], 'enviar');
        $b = $this->criar($coordenador, ['causa_raiz' => 'Calendário de provas apertado'], 'enviar');

        $this->actingAs($this->colaborador(), 'admin');
        $this->get('/colaboracao/planos?aba=todos&q=monitoria')->assertOk()->assertSee(route('colaborador.planos.show', $a), false)->assertDontSee(route('colaborador.planos.show', $b), false);
        $this->get('/colaboracao/planos?aba=todos&q=100%25')->assertOk()->assertDontSee(route('colaborador.planos.show', $a), false);

        $this->get("/colaboracao/planos/{$a->id}")->assertOk()->assertSee('Outros planos deste curso')->assertSee('Calendário de provas apertado');
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
