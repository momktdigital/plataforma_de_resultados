<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\Questao;
use App\Models\Resposta;
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

    private function resultadoNaAvaliacao(Aluno $aluno, ?int $categoriaId, ?string $dataAvaliacao, string $nome): Avaliacao
    {
        $avaliacao = Avaliacao::create(['nome' => $nome, 'categoria_id' => $categoriaId, 'data_avaliacao' => $dataAvaliacao]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => $aluno->ra, 'questao_numero' => 1, 'resposta' => 'A']);

        // O boletim do portal lê de `resultado_resumos`, não direto de
        // `respostas` — nos imports de verdade isso é recalculado pelos
        // controllers; aqui a fixture cria a resposta direto no banco, então
        // precisa disparar o mesmo recálculo manualmente.
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        return $avaliacao;
    }

    public function test_boletim_mostra_primeiro_nome_e_resumo_de_desempenho(): void
    {
        $aluno = $this->aluno();
        $this->resultadoNaAvaliacao($aluno, null, null, 'Avaliacao Solta');

        $response = $this->followingRedirects()->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '15/03/2000']);

        $response->assertOk();
        $response->assertSee('Fulano');
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

    public function test_boletim_mostra_card_resumido_com_link_para_nova_aba_e_pdf_por_prova(): void
    {
        $aluno = $this->aluno();
        $avaliacao = $this->resultadoNaAvaliacao($aluno, null, '2026-05-20', 'ENADE 2026');

        $boletim = $this->followingRedirects()->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '15/03/2000']);
        $htmlBoletim = $boletim->getContent();

        $urlDetalhe = route('portal.resultados.avaliacao', ['avaliacao' => $avaliacao->codigo, 'periodo' => '']);

        // Card resumido é um link que abre o detalhe em nova aba (target="_blank").
        $boletim->assertSee('href="'.$urlDetalhe.'"', false);
        $boletim->assertSee('target="_blank"', false);

        // Não existe mais popup/modal nem um botão de baixar TODAS as avaliacoes de uma vez.
        $this->assertStringNotContainsString('portalAbrirDetalhe', $htmlBoletim);
        $this->assertStringNotContainsString('avaliacao-modal', $htmlBoletim);
        $this->assertStringNotContainsString('portalExportarPdf()', $htmlBoletim);
        $this->assertStringNotContainsString('id="btn-pdf"', $htmlBoletim);

        // A página dedicada da avaliacao (aberta em nova aba) tem o detalhamento e o botão de PDF dela.
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

    public function test_sair_encerra_a_sessao_do_boletim(): void
    {
        $aluno = $this->aluno();
        $this->resultadoNaAvaliacao($aluno, null, null, 'Avaliacao X');

        $this->followingRedirects()->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '15/03/2000']);
        $this->get(route('portal.resultados'))->assertOk();

        $this->get(route('portal.sair'))->assertRedirect(route('portal.consulta'));

        $this->get(route('portal.resultados'))->assertRedirect(route('portal.consulta'));
    }
}
