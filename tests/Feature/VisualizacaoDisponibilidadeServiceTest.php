<?php

namespace Tests\Feature;

use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\Questao;
use App\Models\QuestaoMatriz;
use App\Models\Resposta;
use App\Models\ResultadoMetrica;
use App\Services\ResumoResultadoService;
use App\Services\Visualizacoes\VisualCatalog;
use App\Services\Visualizacoes\VisualizacaoDisponibilidadeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisualizacaoDisponibilidadeServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): VisualizacaoDisponibilidadeService
    {
        return new VisualizacaoDisponibilidadeService;
    }

    public function test_todos_os_visuais_ficam_indisponiveis_sem_gabarito(): void
    {
        $avaliacao = Avaliacao::create([]);

        $estado = $this->service()->calcular($avaliacao);

        $this->assertSame(VisualCatalog::chaves(), array_keys($estado));
        foreach ($estado as $chave => $item) {
            $this->assertFalse($item['disponivel'], "Visual '{$chave}' deveria estar indisponível sem gabarito.");
            $this->assertNotNull($item['pendencia']);
        }
    }

    public function test_visuais_basicos_ficam_disponiveis_com_gabarito_e_respostas(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);

        $estado = $this->service()->calcular($avaliacao);

        $this->assertTrue($estado['histograma']['disponivel']);
        $this->assertTrue($estado['questoes_criticas']['disponivel']);
        $this->assertTrue($estado['analise_alternativas']['disponivel']);
        $this->assertTrue($estado['nota_geral']['disponivel']);
        $this->assertTrue($estado['grade_questoes']['disponivel']);

        // Sem matriz curricular, disciplina ainda não disponível.
        $this->assertFalse($estado['radar_disciplina']['disponivel']);
        $this->assertStringContainsString('disciplina', $estado['radar_disciplina']['pendencia']);
    }

    public function test_radar_disciplina_fica_disponivel_com_matriz_curricular(): void
    {
        $avaliacao = Avaliacao::create([]);
        $questao = Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);
        QuestaoMatriz::create(['questao_id' => $questao->id, 'disciplina' => 'Anatomia']);

        $estado = $this->service()->calcular($avaliacao);

        $this->assertTrue($estado['radar_disciplina']['disponivel']);
        $this->assertNull($estado['radar_disciplina']['pendencia']);
    }

    public function test_visuais_de_turma_dependem_de_aluno_vinculado_com_turma(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => 'RA1', 'questao_numero' => 1, 'resposta' => 'A']);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $estadoSemAluno = $this->service()->calcular($avaliacao);
        $this->assertFalse($estadoSemAluno['distribuicao_turma']['disponivel']);
        $this->assertFalse($estadoSemAluno['comparativo_turma']['disponivel']);

        Aluno::create(['ra' => 'RA1', 'nome' => 'Fulano', 'turma' => 'Turma A']);

        $estadoComAluno = $this->service()->calcular($avaliacao);
        $this->assertTrue($estadoComAluno['distribuicao_turma']['disponivel']);
        $this->assertTrue($estadoComAluno['comparativo_turma']['disponivel']);
    }

    public function test_metricas_nomeadas_e_correlacao_dependem_de_resultado_metrica(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $estadoSemMetrica = $this->service()->calcular($avaliacao);
        $this->assertFalse($estadoSemMetrica['metricas_nomeadas']['disponivel']);
        $this->assertFalse($estadoSemMetrica['correlacao_metricas']['disponivel']);

        ResultadoMetrica::create([
            'avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'periodo' => '',
            'nome_metrica' => 'Redação', 'valor' => '9.0',
        ]);

        $estadoComMetrica = $this->service()->calcular($avaliacao);
        $this->assertTrue($estadoComMetrica['metricas_nomeadas']['disponivel']);
        $this->assertTrue($estadoComMetrica['correlacao_metricas']['disponivel']);
    }

    public function test_evolucao_categoria_exige_categoria_e_pelo_menos_duas_avaliacoes_com_resumo(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulados']);
        $avaliacao1 = Avaliacao::create(['categoria_id' => $categoria->id]);
        Questao::create(['avaliacao_codigo' => $avaliacao1->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao1->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);
        app(ResumoResultadoService::class)->recalcular($avaliacao1->codigo);

        $estado = $this->service()->calcular($avaliacao1);
        $this->assertFalse($estado['evolucao_categoria']['disponivel']);

        $avaliacao2 = Avaliacao::create(['categoria_id' => $categoria->id]);
        Questao::create(['avaliacao_codigo' => $avaliacao2->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao2->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);
        app(ResumoResultadoService::class)->recalcular($avaliacao2->codigo);

        $estado = $this->service()->calcular($avaliacao1);
        $this->assertTrue($estado['evolucao_categoria']['disponivel']);
    }
}
