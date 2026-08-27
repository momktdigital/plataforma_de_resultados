<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\QuestaoMatriz;
use App\Models\Resposta;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvaliacaoVisualizacaoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    public function test_tela_de_configuracao_mostra_pendencia_quando_visual_indisponivel(): void
    {
        $avaliacao = Avaliacao::create([]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('avaliacoes.visualizacoes.edit', $avaliacao));

        $response->assertOk();
        $response->assertSee('Cadastre o gabarito das questões desta avaliação.');
        $response->assertSee('disabled', false);
    }

    public function test_visuais_disponiveis_ficam_visiveis_por_padrao_sem_configuracao_previa(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi");

        $response->assertOk();
        $response->assertSee('1 respondente');
    }

    public function test_admin_pode_desabilitar_um_visual_disponivel(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);
        $admin = $this->admin();

        // Desmarca tudo, exceto "histograma" pra admin (o form não envia entradas desmarcadas).
        $this->actingAs($admin, 'admin')->put(route('avaliacoes.visualizacoes.update', $avaliacao), [
            'visuais' => [
                'histograma' => ['admin' => '1'],
            ],
        ])->assertRedirect();

        $bi = $this->actingAs($admin, 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi");
        $bi->assertOk();
        $bi->assertSee('1 respondente'); // histograma continua
        $bi->assertDontSee('Análise de alternativas por questão'); // foi desmarcado
    }

    public function test_nao_e_possivel_forcar_habilitar_visual_indisponivel(): void
    {
        $avaliacao = Avaliacao::create([]); // sem gabarito: nada disponível
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->put(route('avaliacoes.visualizacoes.update', $avaliacao), [
            'visuais' => [
                'histograma' => ['admin' => '1'],
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('avaliacao_visualizacoes', [
            'avaliacao_codigo' => $avaliacao->codigo,
            'visual' => 'histograma',
            'visivel_admin' => false,
        ]);
    }

    public function test_radar_disciplina_aparece_no_boletim_do_aluno_quando_disponivel(): void
    {
        $avaliacao = Avaliacao::create([]);
        $questao = Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        QuestaoMatriz::create(['questao_id' => $questao->id, 'disciplina' => 'Anatomia']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2026001', 'questao_numero' => 1, 'resposta' => 'A']);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $this->admin();
        $aluno = Aluno::create([
            'ra' => '2026001', 'cpf' => '12345678909', 'data_nascimento' => '2000-03-15', 'nome' => 'Fulano',
        ]);

        $this->followingRedirects()->post('/portal/consultar', [
            'cpf' => '123.456.789-09',
            'data_nascimento' => '15/03/2000',
        ]);

        $detalhe = $this->get(route('portal.resultados.avaliacao', ['avaliacao' => $avaliacao->codigo, 'periodo' => '']));
        $detalhe->assertOk();
        $detalhe->assertSee('grafico-radar-disciplina', false);
    }

    public function test_admin_ve_desempenho_por_area_e_tema_e_distrator_na_analise_de_alternativas(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A', 'area' => 'Pediatria', 'tema' => 'COVID']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'questao_numero' => 1, 'resposta' => 'B']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '3', 'questao_numero' => 1, 'resposta' => 'B']);

        $bi = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi");

        $bi->assertOk();
        $bi->assertSee('Desempenho por área');
        $bi->assertSee('Desempenho por tema');
        $bi->assertSee('Pediatria');
        $bi->assertSee('COVID');
        $bi->assertSee('distrator', false);
    }

    public function test_aluno_ve_desempenho_por_area_e_cartoes_de_lacuna_e_conhecimento_consolidado(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A', 'area' => 'Pediatria', 'tema' => 'COVID']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'B', 'area' => 'Pediatria', 'tema' => 'Bradicardia']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2026001', 'questao_numero' => 1, 'resposta' => 'X']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2026001', 'questao_numero' => 2, 'resposta' => 'B']);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $this->admin();
        Aluno::create(['ra' => '2026001', 'cpf' => '12345678909', 'data_nascimento' => '2000-03-15', 'nome' => 'Fulano']);

        $this->followingRedirects()->post('/portal/consultar', [
            'cpf' => '123.456.789-09',
            'data_nascimento' => '15/03/2000',
        ]);

        $detalhe = $this->get(route('portal.resultados.avaliacao', ['avaliacao' => $avaliacao->codigo, 'periodo' => '']));
        $detalhe->assertOk();
        $detalhe->assertSee('grafico-area', false);
        $detalhe->assertSee('Lacunas de aprendizagem');
        $detalhe->assertSee('Conhecimentos consolidados');
        $detalhe->assertSee('COVID');
        $detalhe->assertSee('Bradicardia');
    }

    public function test_percentil_aparece_mesmo_quando_resposta_importada_so_tem_ra_mas_aluno_tem_cpf_cadastrado(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);

        // As respostas importadas só trazem RA (sem CPF na planilha) — cenário comum —
        // mas o cadastro do Aluno tem CPF preenchido (usado pro login do portal).
        // aluno_chave = COALESCE(cpf, ra) da LINHA importada vira o RA, não o CPF.
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2026001', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2026002', 'questao_numero' => 1, 'resposta' => 'C']);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $this->admin();
        Aluno::create(['ra' => '2026001', 'cpf' => '12345678909', 'data_nascimento' => '2000-03-15', 'nome' => 'Fulano']);

        $this->followingRedirects()->post('/portal/consultar', [
            'cpf' => '123.456.789-09',
            'data_nascimento' => '15/03/2000',
        ]);

        $detalhe = $this->get(route('portal.resultados.avaliacao', ['avaliacao' => $avaliacao->codigo, 'periodo' => '']));
        $detalhe->assertOk();
        $detalhe->assertSee('Posição relativa');
        $detalhe->assertSee('posição 1 de 2', false);
    }
}
