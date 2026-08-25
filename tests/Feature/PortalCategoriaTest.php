<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\Questao;
use App\Models\Resposta;
use App\Models\ResultadoMetrica;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalCategoriaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    private function aluno(): Aluno
    {
        return Aluno::create([
            'ra' => '2026001',
            'cpf' => '12345678909',
            'data_nascimento' => '2000-03-15',
            'nome' => 'Fulano de Tal',
        ]);
    }

    private function resultadoNaAvaliacao(Aluno $aluno, ?int $categoriaId, ?string $dataAvaliacao, string $nome, string $periodo = ''): Avaliacao
    {
        $avaliacao = Avaliacao::create(['nome' => $nome, 'categoria_id' => $categoriaId, 'data_avaliacao' => $dataAvaliacao]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => $aluno->ra, 'periodo' => $periodo, 'questao_numero' => 1, 'resposta' => 'A']);

        // O boletim do portal lê de `resultado_resumos`, não direto de
        // `respostas` — nos imports de verdade isso é recalculado pelos
        // controllers; aqui a fixture cria a resposta direto no banco, então
        // precisa disparar o mesmo recálculo manualmente.
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        return $avaliacao;
    }

    public function test_resultados_mostra_nome_completo_e_resumo_de_desempenho(): void
    {
        $aluno = $this->aluno();
        $this->resultadoNaAvaliacao($aluno, null, null, 'Avaliacao Solta');

        $response = $this->followingRedirects()->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '15/03/2000']);

        $response->assertOk();
        $response->assertSee('Fulano De Tal');
        $response->assertSee('100%');
    }

    public function test_prova_sem_categoria_aparece_fora_da_arvore(): void
    {
        $aluno = $this->aluno();
        $this->resultadoNaAvaliacao($aluno, null, null, 'Avaliacao Solta');

        $response = $this->followingRedirects()->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '15/03/2000']);

        $response->assertOk();
        $response->assertSee('Avaliacao Solta');
    }

    public function test_prova_com_categoria_aparece_dentro_da_arvore(): void
    {
        $aluno = $this->aluno();
        $categoria = Categoria::create(['nome' => 'Simulados']);
        $this->resultadoNaAvaliacao($aluno, $categoria->id, '2026-03-01', 'Simulado 1');

        $response = $this->followingRedirects()->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '15/03/2000']);

        $response->assertOk();
        $response->assertSee('Simulados');
        $response->assertSee('Simulado 1');
    }

    public function test_categoria_sem_resultado_do_aluno_nao_aparece(): void
    {
        $aluno = $this->aluno();
        Categoria::create(['nome' => 'Categoria vazia (sem avaliacoes do aluno)']);
        $categoriaComResultado = Categoria::create(['nome' => 'Com resultado']);
        $this->resultadoNaAvaliacao($aluno, $categoriaComResultado->id, null, 'Avaliacao X');

        $response = $this->followingRedirects()->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '15/03/2000']);

        $response->assertOk();
        $response->assertSee('Com resultado');
        $response->assertDontSee('Categoria vazia (sem avaliacoes do aluno)');
    }

    public function test_subcategoria_aninhada_aparece_dentro_da_categoria_mae(): void
    {
        $aluno = $this->aluno();
        $mae = Categoria::create(['nome' => 'Simulados']);
        $filha = Categoria::create(['nome' => '1º ao 4º período', 'categoria_pai_id' => $mae->id]);
        $this->resultadoNaAvaliacao($aluno, $filha->id, null, 'Simulado do 1º período');

        $response = $this->followingRedirects()->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '15/03/2000']);

        $response->assertOk();
        $response->assertSee('Simulados');
        $response->assertSee('1º ao 4º período');
        $response->assertSee('Simulado do 1º período');
    }

    public function test_data_da_prova_aparece_no_boletim_para_filtro(): void
    {
        $aluno = $this->aluno();
        $avaliacao = $this->resultadoNaAvaliacao($aluno, null, '2026-05-20', 'Avaliacao com data');

        $response = $this->followingRedirects()->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '15/03/2000']);

        $response->assertOk();
        $response->assertSee('20/05/2026');
        $response->assertSee('data-data="2026-05-20"', false);
    }

    public function test_resultados_mostra_card_resumido_com_link_na_mesma_aba_e_pdf_por_prova(): void
    {
        $aluno = $this->aluno();
        $avaliacao = $this->resultadoNaAvaliacao($aluno, null, '2026-05-20', 'ENADE 2026');

        $resultados = $this->followingRedirects()->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '15/03/2000']);
        $htmlResultados = $resultados->getContent();

        $urlDetalhe = route('portal.resultados.avaliacao', ['avaliacao' => $avaliacao->codigo, 'periodo' => '']);

        // Card resumido é um link que abre o detalhe na mesma aba (sem target="_blank").
        $resultados->assertSee('href="'.$urlDetalhe.'"', false);
        $resultados->assertDontSee('target="_blank"', false);

        // Não existe mais popup/modal nem um botão de baixar TODAS as avaliacoes de uma vez.
        $this->assertStringNotContainsString('portalAbrirDetalhe', $htmlResultados);
        $this->assertStringNotContainsString('avaliacao-modal', $htmlResultados);
        $this->assertStringNotContainsString('portalExportarPdf()', $htmlResultados);
        $this->assertStringNotContainsString('id="btn-pdf"', $htmlResultados);

        // A página dedicada da avaliacao tem o detalhamento e o botão de PDF dela.
        $detalhe = $this->get($urlDetalhe);
        $detalhe->assertOk();
        $detalhe->assertSee('ENADE 2026');
        $detalhe->assertSee('portalExportarPdfAvaliacao()', false);
    }

    public function test_detalhe_da_prova_exige_ter_passado_pela_consulta(): void
    {
        $aluno = $this->aluno();
        $avaliacao = $this->resultadoNaAvaliacao($aluno, null, null, 'Avaliacao Restrita');

        $response = $this->get(route('portal.resultados.avaliacao', ['avaliacao' => $avaliacao->codigo, 'periodo' => '']));

        $response->assertRedirect(route('portal.consulta'));
    }

    public function test_detalhe_da_prova_nao_mostra_resultado_de_outro_aluno(): void
    {
        $aluno = $this->aluno();
        $outroAluno = Aluno::create([
            'ra' => '2026002',
            'cpf' => '98765432100',
            'data_nascimento' => '2001-01-01',
            'nome' => 'Ciclano',
        ]);
        $avaliacao = $this->resultadoNaAvaliacao($outroAluno, null, null, 'Avaliacao do outro aluno');

        $this->followingRedirects()->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '15/03/2000']);

        $response = $this->get(route('portal.resultados.avaliacao', ['avaliacao' => $avaliacao->codigo, 'periodo' => '']));

        $response->assertNotFound();
    }

    public function test_filtro_de_periodo_letivo_padrao_e_o_mais_recente_com_opcao_todos(): void
    {
        $aluno = $this->aluno();
        $this->resultadoNaAvaliacao($aluno, null, null, 'Avaliacao 2026/1', '2026/1');
        $this->resultadoNaAvaliacao($aluno, null, null, 'Avaliacao 2026/2', '2026/2');

        $response = $this->followingRedirects()->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '15/03/2000']);

        // Por padrão mostra só o período letivo mais recente.
        $response->assertSee('Avaliacao 2026/2');
        $response->assertDontSee('Avaliacao 2026/1');

        // Selecionando "Todos" mostra os dois.
        $todos = $this->get(route('portal.resultados', ['periodo_letivo' => '']));
        $todos->assertSee('Avaliacao 2026/1');
        $todos->assertSee('Avaliacao 2026/2');

        // Selecionando o período mais antigo explicitamente mostra só ele.
        $antigo = $this->get(route('portal.resultados', ['periodo_letivo' => '2026/1']));
        $antigo->assertSee('Avaliacao 2026/1');
        $antigo->assertDontSee('Avaliacao 2026/2');
    }

    public function test_cabecalho_mostra_menu_de_conta_com_sair_quando_autenticado(): void
    {
        $aluno = $this->aluno();
        $this->resultadoNaAvaliacao($aluno, null, null, 'Avaliacao X');

        $response = $this->followingRedirects()->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '15/03/2000']);

        $response->assertSee('portal-conta-menu', false);
        $response->assertSee(route('portal.sair'), false);
        $response->assertDontSee('Nova consulta');
    }

    public function test_cabecalho_nao_mostra_menu_de_conta_para_visitante(): void
    {
        $response = $this->get('/portal');

        $response->assertDontSee('portal-conta-menu', false);
    }

    public function test_card_total_aparece_em_destaque_na_avaliacao(): void
    {
        $aluno = $this->aluno();
        $avaliacao = $this->resultadoNaAvaliacao($aluno, null, null, 'Avaliacao com Total');
        ResultadoMetrica::create([
            'avaliacao_codigo' => $avaliacao->codigo,
            'ra' => $aluno->ra,
            'periodo' => '',
            'nome_metrica' => 'Total',
            'valor' => '87',
        ]);
        ResultadoMetrica::create([
            'avaliacao_codigo' => $avaliacao->codigo,
            'ra' => $aluno->ra,
            'periodo' => '',
            'nome_metrica' => 'Redação',
            'valor' => '9.5',
        ]);

        $this->followingRedirects()->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '15/03/2000']);

        $urlDetalhe = route('portal.resultados.avaliacao', ['avaliacao' => $avaliacao->codigo, 'periodo' => '']);
        $detalhe = $this->get($urlDetalhe);

        $detalhe->assertOk();
        $detalhe->assertSeeInOrder(['ph-trophy', 'Total', '87', 'Redação'], false);
    }

    public function test_sair_encerra_a_sessao_dos_resultados(): void
    {
        $aluno = $this->aluno();
        $this->resultadoNaAvaliacao($aluno, null, null, 'Avaliacao X');

        $this->followingRedirects()->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '15/03/2000']);
        $this->get(route('portal.resultados'))->assertOk();

        $this->get(route('portal.sair'))->assertRedirect(route('portal.consulta'));

        $this->get(route('portal.resultados'))->assertRedirect(route('portal.consulta'));
    }
}
