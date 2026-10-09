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
            ['evolucaoHistorica', 'metaPeriodo', 'dispersaoTri', 'coberturaHabilidade', 'miller', 'mapaDominio'],
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

    public function test_meta_periodo_explica_a_linha_tracejada_sem_repetir_a_leitura_rapida(): void
    {
        $entrada = $this->service->gerar($this->analiseVazia())['metaPeriodo'];

        $this->assertStringContainsString('linha tracejada', $entrada['generico']);
        $this->assertNull($entrada['pessoal']);
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

    public function test_dispersao_tri_diferenca_pequena_nao_afirma_como_esperado(): void
    {
        $analise = $this->analiseVazia();
        $analise['dispersaoTri'] = [
            ['dificuldade_tri' => 0.5, 'acertou' => true],
            ['dificuldade_tri' => 0.5, 'acertou' => true],
            ['dificuldade_tri' => 0.55, 'acertou' => false],
            ['dificuldade_tri' => 0.55, 'acertou' => false],
        ];

        $pessoal = $this->service->gerar($analise)['dispersaoTri']['pessoal'];

        $this->assertStringNotContainsString('como esperado', $pessoal);
        $this->assertStringContainsString('diferença pequena', $pessoal);
    }

    public function test_dispersao_tri_flags_erro_mais_facil_que_a_media_de_acerto(): void
    {
        $analise = $this->analiseVazia();
        $analise['dispersaoTri'] = [
            ['dificuldade_tri' => 0.3, 'acertou' => true],
            ['dificuldade_tri' => 0.4, 'acertou' => true],
            ['dificuldade_tri' => 0.5, 'acertou' => true],
            ['dificuldade_tri' => 0.1, 'acertou' => false],
            ['dificuldade_tri' => 0.6, 'acertou' => false],
        ];

        $pessoal = $this->service->gerar($analise)['dispersaoTri']['pessoal'];

        $this->assertStringContainsString('Atenção', $pessoal);
        $this->assertStringContainsString('1 questão', $pessoal);
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

    public function test_cobertura_habilidade_lista_todas_as_habilidades_empatadas_no_pior_valor(): void
    {
        $analise = $this->analiseVazia();
        $analise['coberturaHabilidade'] = ['Anamnese' => 0.0, 'Comunicação' => 0.0, 'Exame físico' => 90.0];

        $pessoal = $this->service->gerar($analise)['coberturaHabilidade']['pessoal'];

        $this->assertStringContainsString('2 habilidades', $pessoal);
        $this->assertStringContainsString('Anamnese', $pessoal);
        $this->assertStringContainsString('Comunicação', $pessoal);
        $this->assertStringContainsString('Exame físico', $pessoal);
    }

    public function test_cobertura_habilidade_todas_com_mesmo_aproveitamento(): void
    {
        $analise = $this->analiseVazia();
        $analise['coberturaHabilidade'] = ['Anamnese' => 50.0, 'Exame físico' => 50.0];

        $pessoal = $this->service->gerar($analise)['coberturaHabilidade']['pessoal'];

        $this->assertStringContainsString('mesmo aproveitamento', $pessoal);
        $this->assertStringContainsString('50', $pessoal);
    }

    public function test_nivel_cognitivo_identifica_pior_e_melhor_nivel(): void
    {
        $analise = $this->analiseVazia();
        $analise['miller'] = ['Sabe' => 80.0, 'Faz' => 40.0];

        $entrada = $this->service->gerar($analise)['miller'];

        $this->assertStringContainsString('Miller', $entrada['generico']);
        $this->assertStringContainsString('Faz', $entrada['pessoal']);
        $this->assertStringContainsString('Sabe', $entrada['pessoal']);
    }
    public function test_mapa_dominio_aponta_consolidacao_e_queda(): void
    {
        $analise = $this->analiseVazia();
        $analise['mapaDominio'] = [
            'avaliacoes' => [['codigo' => 1, 'nome' => 'Diag. 1'], ['codigo' => 2, 'nome' => 'Diag. 2']],
            'areas' => [
                ['area' => 'Cardiologia', 'valores' => [1 => 40.0, 2 => 85.0]],
                ['area' => 'Saúde Coletiva', 'valores' => [1 => 80.0, 2 => 50.0]],
            ],
        ];

        $pessoal = $this->service->gerar($analise)['mapaDominio']['pessoal'];

        $this->assertStringContainsString('Cardiologia', $pessoal);
        $this->assertStringContainsString('45', $pessoal, 'a alta de 40 para 85 são 45 pp');
        $this->assertStringContainsString('Saúde Coletiva', $pessoal);
    }

    public function test_mapa_dominio_nao_afirma_padrao_com_variacao_pequena(): void
    {
        $analise = $this->analiseVazia();
        $analise['mapaDominio'] = [
            'avaliacoes' => [['codigo' => 1, 'nome' => 'Diag. 1'], ['codigo' => 2, 'nome' => 'Diag. 2']],
            'areas' => [['area' => 'Pediatria', 'valores' => [1 => 70.0, 2 => 73.0]]],
        ];

        $pessoal = $this->service->gerar($analise)['mapaDominio']['pessoal'];

        // 3 pp de um exame para outro é ruído — o texto tem que dizer
        // "estável", nunca "você melhorou".
        $this->assertStringContainsString('estável', $pessoal);
    }

    public function test_mapa_dominio_ignora_area_que_nao_tem_as_duas_pontas(): void
    {
        $analise = $this->analiseVazia();
        $analise['mapaDominio'] = [
            'avaliacoes' => [['codigo' => 1, 'nome' => 'Diag. 1'], ['codigo' => 2, 'nome' => 'Diag. 2']],
            // Só apareceu na segunda prova: não há evolução a afirmar.
            'areas' => [['area' => 'Cirurgia', 'valores' => [1 => null, 2 => 90.0]]],
        ];

        $this->assertNull($this->service->gerar($analise)['mapaDominio']['pessoal']);
    }

    public function test_mapa_dominio_sem_dado_nao_tem_leitura_pessoal(): void
    {
        $this->assertNull($this->service->gerar($this->analiseVazia())['mapaDominio']['pessoal']);
    }

    /** @return array<string, mixed> */
    private function analiseVazia(): array
    {
        return [
            'evolucaoHistorica' => [],
            'dispersaoTri' => [],
            'coberturaHabilidade' => [],
            'miller' => [],
            'mapaDominio' => null,
        ];
    }
}
