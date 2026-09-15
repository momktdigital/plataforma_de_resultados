<?php

namespace Tests\Unit;

use App\Services\ExplicacaoBiService;
use App\Support\Psicometria;
use PHPUnit\Framework\TestCase;

class ExplicacaoBiServiceTest extends TestCase
{
    private ExplicacaoBiService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ExplicacaoBiService;
    }

    public function test_todo_visual_tem_explicacao_generica_mesmo_sem_dado(): void
    {
        $resultado = $this->service->gerar([]);

        $this->assertNotEmpty($resultado);

        foreach ($resultado as $chave => $entrada) {
            $this->assertArrayHasKey('generico', $entrada, "chave: {$chave}");
            $this->assertNotSame('', $entrada['generico'], "chave: {$chave}");
            $this->assertArrayHasKey('leitura', $entrada, "chave: {$chave}");
            $this->assertNull($entrada['leitura'], "sem dado, {$chave} não pode afirmar nada");
        }
    }

    public function test_cobre_os_visuais_do_painel(): void
    {
        $chaves = array_keys($this->service->gerar([]));

        foreach ([
            'estatisticas_gerais', 'mapa_itens', 'histograma', 'radar_disciplina', 'desempenho_area',
            'desempenho_bloom', 'desempenho_miller', 'desempenho_tema', 'ranking_completo',
            'distribuicao_turma', 'curva_dificuldade', 'dispersao_tri', 'heatmap_habilidade_turma',
            'perfil_demografico', 'analise_alternativas', 'correlacao_metricas', 'evolucao_categoria',
            'alinhamento_referencias', 'equidade_demografica',
        ] as $esperada) {
            $this->assertContains($esperada, $chaves);
        }
    }

    public function test_numeros_gerais_avisam_quando_a_confiabilidade_e_baixa(): void
    {
        $leitura = $this->service->gerar([
            'psicometria' => ['media' => 60.0, 'mediana' => 61.0, 'desvio' => 10.0, 'kr20' => 0.45, 'itens' => [], 'simulacao' => null],
        ])['estatisticas_gerais']['leitura'];

        $this->assertStringContainsString('0,45', $leitura);
        $this->assertStringContainsString('acaso', $leitura);
    }

    public function test_numeros_gerais_apontam_cauda_de_notas_baixas(): void
    {
        $leitura = $this->service->gerar([
            'psicometria' => ['media' => 50.0, 'mediana' => 62.0, 'desvio' => 15.0, 'kr20' => 0.85, 'itens' => [], 'simulacao' => null],
        ])['estatisticas_gerais']['leitura'];

        $this->assertStringContainsString('abaixo da mediana', $leitura);
    }

    public function test_mapa_de_itens_destaca_discriminacao_negativa(): void
    {
        $leitura = $this->service->gerar([
            'psicometria' => [
                'media' => 50.0, 'mediana' => 50.0, 'desvio' => 10.0, 'kr20' => 0.7,
                'simulacao' => null,
                'itens' => [
                    ['numero' => 7, 'discriminacao' => -0.39, 'faixa' => Psicometria::FAIXA_REVISAR],
                    ['numero' => 2, 'discriminacao' => 0.45, 'faixa' => Psicometria::FAIXA_OTIMO],
                ],
            ],
        ])['mapa_itens']['leitura'];

        $this->assertStringContainsString('Q7', $leitura);
        $this->assertStringContainsString('gabarito', $leitura);
    }

    public function test_mapa_de_itens_reconhece_prova_sem_item_fraco(): void
    {
        $leitura = $this->service->gerar([
            'psicometria' => [
                'media' => 50.0, 'mediana' => 50.0, 'desvio' => 10.0, 'kr20' => 0.9, 'simulacao' => null,
                'itens' => [['numero' => 1, 'discriminacao' => 0.5, 'faixa' => Psicometria::FAIXA_OTIMO]],
            ],
        ])['mapa_itens']['leitura'];

        $this->assertStringContainsString('Nenhuma questão', $leitura);
    }

    public function test_desempenho_por_campo_so_afirma_prioridade_com_diferenca_relevante(): void
    {
        $grande = $this->service->gerar(['mediaPorArea' => ['Pediatria' => 80.0, 'Cirurgia' => 45.0]]);
        $this->assertStringContainsString('prioridade', $grande['desempenho_area']['leitura']);

        $pequena = $this->service->gerar(['mediaPorArea' => ['Pediatria' => 62.0, 'Cirurgia' => 58.0]]);
        $this->assertStringContainsString('parelho', $pequena['desempenho_area']['leitura']);
    }

    public function test_curva_de_dificuldade_detecta_ordem_incoerente(): void
    {
        $coerente = $this->service->gerar(['curvaDificuldade' => [
            'facil' => ['esperado' => 'Fácil', 'observado' => 85.0, 'questoes' => 10],
            'dificil' => ['esperado' => 'Difícil', 'observado' => 40.0, 'questoes' => 10],
        ]]);
        $this->assertStringContainsString('bate com o esperado', $coerente['curva_dificuldade']['leitura']);

        $incoerente = $this->service->gerar(['curvaDificuldade' => [
            'facil' => ['esperado' => 'Fácil', 'observado' => 40.0, 'questoes' => 10],
            'dificil' => ['esperado' => 'Difícil', 'observado' => 85.0, 'questoes' => 10],
        ]]);
        $this->assertStringContainsString('não bate', $incoerente['curva_dificuldade']['leitura']);
    }

    public function test_dispersao_tri_identifica_relacao_invertida(): void
    {
        // TRI sobe junto com o acerto: o contrário do esperado.
        $leitura = $this->service->gerar(['dispersaoTri' => [
            ['numero' => 1, 'dificuldade_tri' => -1.0, 'taxa_acerto' => 20.0],
            ['numero' => 2, 'dificuldade_tri' => 0.0, 'taxa_acerto' => 50.0],
            ['numero' => 3, 'dificuldade_tri' => 1.0, 'taxa_acerto' => 90.0],
        ]])['dispersao_tri']['leitura'];

        $this->assertStringContainsString('invertida', $leitura);
    }

    public function test_equidade_so_chama_de_relevante_uma_diferenca_grande(): void
    {
        $grande = $this->service->gerar(['equidade' => [
            'sexo' => ['rotulo' => 'Sexo', 'suprimidos' => 0, 'grupos' => [
                ['valor' => 'Feminino', 'respondentes' => 40, 'media' => 72.0],
                ['valor' => 'Masculino', 'respondentes' => 38, 'media' => 55.0],
            ]],
        ]])['equidade_demografica']['leitura'];

        $this->assertStringContainsString('relevante', $grande);

        $pequena = $this->service->gerar(['equidade' => [
            'sexo' => ['rotulo' => 'Sexo', 'suprimidos' => 0, 'grupos' => [
                ['valor' => 'Feminino', 'respondentes' => 40, 'media' => 64.0],
                ['valor' => 'Masculino', 'respondentes' => 38, 'media' => 61.0],
            ]],
        ]])['equidade_demografica']['leitura'];

        $this->assertStringContainsString('pequena', $pequena);
    }

    public function test_alinhamento_avisa_quando_o_eixo_tem_poucas_questoes(): void
    {
        $leitura = $this->service->gerar([
            'psicometria' => ['media' => 70.0, 'mediana' => 70.0, 'desvio' => 10.0, 'kr20' => 0.8, 'itens' => [], 'simulacao' => null],
            'alinhamento' => [
                'dcn' => ['rotulo' => 'DCN', 'itens' => [
                    ['valor' => 'Liderança', 'totalQuestoes' => 2, 'percentual' => 40.0],
                    ['valor' => 'Atenção à saúde', 'totalQuestoes' => 30, 'percentual' => 72.0],
                ]],
            ],
        ])['alinhamento_referencias']['leitura'];

        $this->assertStringContainsString('Liderança', $leitura);
        $this->assertStringContainsString('instável', $leitura);
    }

    public function test_evolucao_da_categoria_nao_afirma_tendencia_em_variacao_pequena(): void
    {
        $leitura = $this->service->gerar(['evolucaoCategoria' => [
            ['codigo' => 1, 'nome' => 'Diag. 1', 'data' => null, 'media' => 60.0, 'respondentes' => 30],
            ['codigo' => 2, 'nome' => 'Diag. 2', 'data' => null, 'media' => 63.0, 'respondentes' => 30],
        ]])['evolucao_categoria']['leitura'];

        $this->assertStringContainsString('estável', $leitura);
    }

    public function test_histograma_le_a_concentracao_da_turma(): void
    {
        // 20 respondentes, a maioria abaixo de 50%.
        $leitura = $this->service->gerar([
            'bi' => ['histograma' => [1, 2, 5, 6, 3, 1, 1, 1, 0, 0], 'totalRespondentes' => 20],
        ])['histograma']['leitura'];

        $this->assertStringContainsString('30–39%', $leitura);
        $this->assertStringContainsString('abaixo de 50%', $leitura);
    }
}
