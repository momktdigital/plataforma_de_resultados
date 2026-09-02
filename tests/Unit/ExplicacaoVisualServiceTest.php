<?php

namespace Tests\Unit;

use App\Services\Portal\ExplicacaoVisualService;
use PHPUnit\Framework\TestCase;

class ExplicacaoVisualServiceTest extends TestCase
{
    private ExplicacaoVisualService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ExplicacaoVisualService;
    }

    public function test_gerar_retorna_uma_entrada_para_cada_visual(): void
    {
        $resultado = $this->service->gerar($this->analiseVazia());

        $this->assertSame(
            ['evolucaoHistorica', 'comparativoTurma', 'curvaDificuldade', 'dispersaoTri', 'coberturaHabilidade', 'bloom', 'miller', 'divergentes'],
            array_keys($resultado)
        );
        foreach ($resultado as $chave => $entrada) {
            $this->assertArrayHasKey('generico', $entrada, "chave: {$chave}");
            $this->assertNotSame('', $entrada['generico'], "chave: {$chave}");
            $this->assertArrayHasKey('pessoal', $entrada, "chave: {$chave}");
        }
    }

    public function test_evolucao_historica_sem_pontos_suficientes_nao_tem_leitura_pessoal(): void
    {
        $entrada = $this->service->gerar($this->analiseVazia())['evolucaoHistorica'];

        $this->assertNull($entrada['pessoal']);
    }

    public function test_evolucao_historica_identifica_melhora(): void
    {
        $analise = $this->analiseVazia();
        $analise['evolucaoHistorica'] = [
            ['codigo' => 1, 'nome' => 'A', 'data' => '01/01/2026', 'percentual' => 40.0],
            ['codigo' => 2, 'nome' => 'B', 'data' => '01/02/2026', 'percentual' => 60.0],
        ];

        $pessoal = $this->service->gerar($analise)['evolucaoHistorica']['pessoal'];

        $this->assertStringContainsString('40%', $pessoal);
        $this->assertStringContainsString('60%', $pessoal);
        $this->assertStringContainsString('melhora', $pessoal);
    }

    public function test_evolucao_historica_identifica_queda(): void
    {
        $analise = $this->analiseVazia();
        $analise['evolucaoHistorica'] = [
            ['codigo' => 1, 'nome' => 'A', 'data' => '01/01/2026', 'percentual' => 70.0],
            ['codigo' => 2, 'nome' => 'B', 'data' => '01/02/2026', 'percentual' => 50.0],
        ];

        $pessoal = $this->service->gerar($analise)['evolucaoHistorica']['pessoal'];

        $this->assertStringContainsString('atenção', $pessoal);
    }

    public function test_comparativo_turma_null_nao_tem_leitura_pessoal(): void
    {
        $entrada = $this->service->gerar($this->analiseVazia())['comparativoTurma'];

        $this->assertNull($entrada['pessoal']);
    }

    public function test_comparativo_turma_acima_da_media(): void
    {
        $analise = $this->analiseVazia();
        $analise['comparativoTurma'] = ['turma' => 'Turma A', 'suaMedia' => 70.0, 'mediaTurma' => 60.0, 'avaliacoesComparadas' => 3];

        $pessoal = $this->service->gerar($analise)['comparativoTurma']['pessoal'];

        $this->assertStringContainsString('acima', $pessoal);
        $this->assertStringContainsString('10', $pessoal);
    }

    public function test_comparativo_turma_abaixo_da_media(): void
    {
        $analise = $this->analiseVazia();
        $analise['comparativoTurma'] = ['turma' => 'Turma A', 'suaMedia' => 50.0, 'mediaTurma' => 65.0, 'avaliacoesComparadas' => 3];

        $pessoal = $this->service->gerar($analise)['comparativoTurma']['pessoal'];

        $this->assertStringContainsString('abaixo', $pessoal);
        $this->assertStringContainsString('15', $pessoal);
    }

    public function test_curva_dificuldade_sem_facil_ou_dificil_nao_tem_leitura_pessoal(): void
    {
        $analise = $this->analiseVazia();
        $analise['curvaDificuldade'] = ['medio' => ['label' => 'Médio', 'percentual' => 50.0, 'respostas' => 10]];

        $entrada = $this->service->gerar($analise)['curvaDificuldade'];

        $this->assertNull($entrada['pessoal']);
    }

    public function test_curva_dificuldade_dentro_do_esperado(): void
    {
        $analise = $this->analiseVazia();
        $analise['curvaDificuldade'] = [
            'facil' => ['label' => 'Fácil', 'percentual' => 80.0, 'respostas' => 10],
            'dificil' => ['label' => 'Difícil', 'percentual' => 40.0, 'respostas' => 10],
        ];

        $pessoal = $this->service->gerar($analise)['curvaDificuldade']['pessoal'];

        $this->assertStringContainsString('dentro do esperado', $pessoal);
    }

    public function test_curva_dificuldade_fora_do_padrao_quando_facil_e_menor(): void
    {
        $analise = $this->analiseVazia();
        $analise['curvaDificuldade'] = [
            'facil' => ['label' => 'Fácil', 'percentual' => 30.0, 'respostas' => 10],
            'dificil' => ['label' => 'Difícil', 'percentual' => 70.0, 'respostas' => 10],
        ];

        $pessoal = $this->service->gerar($analise)['curvaDificuldade']['pessoal'];

        $this->assertStringContainsString('foge do padrão', $pessoal);
    }

    public function test_dispersao_tri_vazia_nao_tem_leitura_pessoal(): void
    {
        $entrada = $this->service->gerar($this->analiseVazia())['dispersaoTri'];

        $this->assertNull($entrada['pessoal']);
    }

    public function test_dispersao_tri_so_com_acertos_nao_tem_leitura_pessoal(): void
    {
        $analise = $this->analiseVazia();
        $analise['dispersaoTri'] = [
            ['dificuldade_tri' => -1.0, 'acertou' => true],
            ['dificuldade_tri' => 1.0, 'acertou' => true],
        ];

        $entrada = $this->service->gerar($analise)['dispersaoTri'];

        $this->assertNull($entrada['pessoal']);
    }

    public function test_dispersao_tri_padrao_esperado(): void
    {
        $analise = $this->analiseVazia();
        $analise['dispersaoTri'] = [
            ['dificuldade_tri' => -1.0, 'acertou' => true],
            ['dificuldade_tri' => -0.8, 'acertou' => true],
            ['dificuldade_tri' => 1.5, 'acertou' => false],
            ['dificuldade_tri' => 1.7, 'acertou' => false],
        ];

        $pessoal = $this->service->gerar($analise)['dispersaoTri']['pessoal'];

        $this->assertStringContainsString('como esperado', $pessoal);
    }

    public function test_cobertura_habilidade_vazia_nao_tem_leitura_pessoal(): void
    {
        $entrada = $this->service->gerar($this->analiseVazia())['coberturaHabilidade'];

        $this->assertNull($entrada['pessoal']);
    }

    public function test_cobertura_habilidade_com_uma_unica_habilidade(): void
    {
        $analise = $this->analiseVazia();
        $analise['coberturaHabilidade'] = ['Anamnese' => 75.0];

        $pessoal = $this->service->gerar($analise)['coberturaHabilidade']['pessoal'];

        $this->assertStringContainsString('única habilidade', $pessoal);
        $this->assertStringContainsString('Anamnese', $pessoal);
    }

    public function test_cobertura_habilidade_identifica_pior_e_melhor(): void
    {
        $analise = $this->analiseVazia();
        $analise['coberturaHabilidade'] = ['Anamnese' => 30.0, 'Exame físico' => 90.0];

        $pessoal = $this->service->gerar($analise)['coberturaHabilidade']['pessoal'];

        $this->assertStringContainsString('Anamnese', $pessoal);
        $this->assertStringContainsString('Exame físico', $pessoal);
    }

    public function test_bloom_e_miller_tem_explicacoes_genericas_diferentes(): void
    {
        $resultado = $this->service->gerar($this->analiseVazia());

        $this->assertStringContainsString('Bloom', $resultado['bloom']['generico']);
        $this->assertStringContainsString('Miller', $resultado['miller']['generico']);
        $this->assertNotSame($resultado['bloom']['generico'], $resultado['miller']['generico']);
    }

    public function test_nivel_cognitivo_identifica_pior_e_melhor_nivel(): void
    {
        $analise = $this->analiseVazia();
        $analise['bloom'] = ['Lembrar' => 80.0, 'Aplicar' => 40.0];

        $pessoal = $this->service->gerar($analise)['bloom']['pessoal'];

        $this->assertStringContainsString('Aplicar', $pessoal);
        $this->assertStringContainsString('Lembrar', $pessoal);
    }

    public function test_divergentes_vazio_nao_tem_leitura_pessoal(): void
    {
        $entrada = $this->service->gerar($this->analiseVazia())['divergentes'];

        $this->assertNull($entrada['pessoal']);
    }

    public function test_divergentes_cita_o_tema_com_mais_ocorrencias(): void
    {
        $analise = $this->analiseVazia();
        $analise['divergentes'] = [
            ['area' => 'Clínica Médica', 'tema' => 'Cardiologia', 'ocorrencias' => 4, 'taxaErroTurmaMedia' => 20.0],
        ];

        $pessoal = $this->service->gerar($analise)['divergentes']['pessoal'];

        $this->assertStringContainsString('Cardiologia', $pessoal);
        $this->assertStringContainsString('Clínica Médica', $pessoal);
        $this->assertStringContainsString('4', $pessoal);
    }

    /** @return array<string, mixed> */
    private function analiseVazia(): array
    {
        return [
            'evolucaoHistorica' => [],
            'comparativoTurma' => null,
            'curvaDificuldade' => [],
            'dispersaoTri' => [],
            'coberturaHabilidade' => [],
            'bloom' => [],
            'miller' => [],
            'divergentes' => [],
        ];
    }
}
