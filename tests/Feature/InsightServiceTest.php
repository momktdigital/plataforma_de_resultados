<?php

namespace Tests\Feature;

use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\Portal\InsightService;
use App\Services\Portal\ResultadoConsultaService;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsightServiceTest extends TestCase
{
    use RefreshDatabase;

    private function aluno(array $extra = []): Aluno
    {
        return Aluno::create(array_merge([
            'ra' => '2026001',
            'cpf' => null,
            'data_nascimento' => '2000-01-01',
            'nome' => 'Fulano de Tal',
        ], $extra));
    }

    private function avaliacaoComResposta(Aluno $aluno, ?int $categoriaId, string $data, string $nome, array $questoes): Avaliacao
    {
        $avaliacao = Avaliacao::create(['nome' => $nome, 'categoria_id' => $categoriaId, 'data_avaliacao' => $data]);

        foreach ($questoes as $numero => $q) {
            Questao::create([
                'avaliacao_codigo' => $avaliacao->codigo,
                'numero' => $numero,
                'gabarito' => $q['gabarito'],
                'area' => $q['area'] ?? null,
            ]);
            Resposta::create([
                'avaliacao_codigo' => $avaliacao->codigo,
                'ra' => $aluno->ra,
                'periodo' => '',
                'questao_numero' => $numero,
                'resposta' => $q['resposta'],
            ]);
        }

        // buscarPorAluno() lê de `resultado_resumos` (pré-calculado), não
        // direto de `respostas` — precisa disparar o mesmo recálculo que os
        // controllers de import fariam, senão o boletim fica vazio.
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        return $avaliacao;
    }

    public function test_alerta_de_queda_de_area_entre_as_duas_ultimas_avaliacoes_da_mesma_categoria(): void
    {
        $aluno = $this->aluno();
        $categoria = Categoria::create(['nome' => 'Simulado MedCof']);

        // Primeira avaliação: acerta as 2 questões de Farmacologia (100%).
        $this->avaliacaoComResposta($aluno, $categoria->id, '2026-05-01', 'Simulado 1', [
            1 => ['gabarito' => 'A', 'resposta' => 'A', 'area' => 'Farmacologia'],
            2 => ['gabarito' => 'B', 'resposta' => 'B', 'area' => 'Farmacologia'],
        ]);

        // Segunda avaliação (mais recente): erra as 2 de Farmacologia (0%) — queda de 100 pontos, bem acima do limiar.
        $this->avaliacaoComResposta($aluno, $categoria->id, '2026-06-01', 'Simulado 2', [
            1 => ['gabarito' => 'A', 'resposta' => 'X', 'area' => 'Farmacologia'],
            2 => ['gabarito' => 'B', 'resposta' => 'X', 'area' => 'Farmacologia'],
        ]);

        $consultaService = app(ResultadoConsultaService::class);
        $resultados = $consultaService->buscarPorAluno($aluno);

        $insights = app(InsightService::class)->gerar($aluno, $resultados, [], []);

        $textos = array_column($insights, 'texto');
        $this->assertTrue(
            collect($textos)->contains(fn ($t) => str_contains($t, 'Farmacologia') && str_contains($t, 'caiu de 100% para 0%')),
            'Esperava um insight de queda em Farmacologia, recebi: '.json_encode($textos)
        );
    }

    public function test_nao_mistura_categorias_diferentes_para_calcular_variacao_de_area(): void
    {
        $aluno = $this->aluno();
        $diagnostico = Categoria::create(['nome' => 'Diagnóstico Institucional']);
        $simulado = Categoria::create(['nome' => 'Simulado MedCof']);

        // Cada categoria só tem 1 avaliação — não há "duas mais recentes da
        // mesma categoria" pra comparar, então nenhum insight de variação de
        // área deve ser gerado (mesmo que as duas usem a área Farmacologia).
        $this->avaliacaoComResposta($aluno, $diagnostico->id, '2026-01-10', 'Diagnostico', [
            1 => ['gabarito' => 'A', 'resposta' => 'A', 'area' => 'Farmacologia'],
        ]);
        $this->avaliacaoComResposta($aluno, $simulado->id, '2026-02-10', 'Simulado 1', [
            1 => ['gabarito' => 'A', 'resposta' => 'X', 'area' => 'Farmacologia'],
        ]);

        $consultaService = app(ResultadoConsultaService::class);
        $resultados = $consultaService->buscarPorAluno($aluno);

        $insights = app(InsightService::class)->gerar($aluno, $resultados, [], []);

        $this->assertSame([], $insights);
    }

    public function test_alerta_de_tres_quedas_consecutivas_na_mesma_categoria(): void
    {
        $evolucaoPorCategoria = [
            [
                'categoria_nome' => 'Simulado MedCof',
                'pontos' => [
                    ['percentual' => 80.0],
                    ['percentual' => 70.0],
                    ['percentual' => 60.0],
                    ['percentual' => 50.0],
                ],
            ],
        ];

        $insights = app(InsightService::class)->gerar($this->aluno(), [], $evolucaoPorCategoria, []);

        $textos = array_column($insights, 'texto');
        $this->assertTrue(collect($textos)->contains(fn ($t) => str_contains($t, 'Simulado MedCof') && str_contains($t, '3 avaliações seguidas')));
    }

    public function test_nenhum_card_compara_o_aluno_com_a_turma_nem_fala_em_pontos(): void
    {
        $aluno = $this->aluno();
        $categoria = Categoria::create(['nome' => 'Simulado MedCof']);

        $this->avaliacaoComResposta($aluno, $categoria->id, '2026-05-01', 'Simulado 1', [
            1 => ['gabarito' => 'A', 'resposta' => 'A', 'area' => 'Farmacologia'],
            2 => ['gabarito' => 'B', 'resposta' => 'B', 'area' => 'Farmacologia'],
        ]);
        $this->avaliacaoComResposta($aluno, $categoria->id, '2026-06-01', 'Simulado 2', [
            1 => ['gabarito' => 'A', 'resposta' => 'X', 'area' => 'Farmacologia'],
            2 => ['gabarito' => 'B', 'resposta' => 'X', 'area' => 'Farmacologia'],
        ]);
        $resultados = app(ResultadoConsultaService::class)->buscarPorAluno($aluno);

        $textos = array_column(app(InsightService::class)->gerar($aluno, $resultados, [], ['Anamnese' => 30.0, 'Raciocínio' => 90.0]), 'texto');

        $this->assertNotEmpty($textos);
        foreach ($textos as $texto) {
            $this->assertStringNotContainsString('turma', $texto);
            $this->assertStringNotContainsString('melhores', $texto);
            $this->assertStringNotContainsString('pontos', $texto);
        }
    }

    public function test_bloom_mais_dificil_vira_card_explicativo_com_dica_de_estudo(): void
    {
        $bloom = [
            'Lembrar' => ['percentual' => 85.0, 'total' => 10],
            'Análise' => ['percentual' => 40.0, 'total' => 8],
        ];

        $insights = app(InsightService::class)->gerar($this->aluno(), [], [], [], $bloom);

        $this->assertCount(1, $insights);
        $this->assertSame('atencao', $insights[0]['tom']);
        $this->assertStringContainsString('relacionar dados de um caso', $insights[0]['texto']);
        $this->assertStringContainsString('foram as mais difíceis para você', $insights[0]['texto']);
        $this->assertStringContainsString('40%', $insights[0]['texto']);
        $this->assertStringContainsString('casos', $insights[0]['texto']);
    }

    public function test_bloom_com_nivel_desconhecido_usa_texto_generico_com_o_nome_da_planilha(): void
    {
        $bloom = [
            'Lembrar' => ['percentual' => 90.0, 'total' => 10],
            'Nível Z' => ['percentual' => 30.0, 'total' => 10],
        ];

        $texto = app(InsightService::class)->gerar($this->aluno(), [], [], [], $bloom)[0]['texto'];

        $this->assertStringContainsString('"Nível Z"', $texto);
        $this->assertStringContainsString('30%', $texto);
    }

    public function test_bloom_ignora_nivel_com_poucas_questoes_diferenca_pequena_ou_nivel_unico(): void
    {
        $servico = app(InsightService::class);
        $aluno = $this->aluno();

        // O pior nível tem só 2 questões: sorte/azar, não padrão.
        $this->assertSame([], $servico->gerar($aluno, [], [], [], [
            'Lembrar' => ['percentual' => 90.0, 'total' => 10],
            'Aplicar' => ['percentual' => 0.0, 'total' => 2],
        ]));
        // Diferença pequena entre os níveis.
        $this->assertSame([], $servico->gerar($aluno, [], [], [], [
            'Lembrar' => ['percentual' => 52.0, 'total' => 10],
            'Aplicar' => ['percentual' => 48.0, 'total' => 10],
        ]));
        // Um nível só: não há "o mais difícil".
        $this->assertSame([], $servico->gerar($aluno, [], [], [], ['Aplicar' => ['percentual' => 20.0, 'total' => 10]]));
    }

    public function test_bloom_bom_em_todos_os_niveis_vira_card_positivo(): void
    {
        $insights = app(InsightService::class)->gerar($this->aluno(), [], [], [], [
            'Lembrar' => ['percentual' => 95.0, 'total' => 10],
            'Aplicar' => ['percentual' => 75.0, 'total' => 10],
        ]);

        $this->assertCount(1, $insights);
        $this->assertSame('positivo', $insights[0]['tom']);
    }
    public function test_extremos_de_habilidade_geram_insight_de_ponto_fraco_e_forte(): void
    {
        $coberturaHabilidade = ['Anamnese' => 30.0, 'Exame físico' => 55.0, 'Raciocínio clínico' => 90.0];

        $insights = app(InsightService::class)->gerar($this->aluno(), [], [], $coberturaHabilidade);

        $textos = array_column($insights, 'texto');
        $this->assertTrue(collect($textos)->contains(fn ($t) => str_contains($t, 'Anamnese') && str_contains($t, 'menor aproveitamento')));
        $this->assertTrue(collect($textos)->contains(fn ($t) => str_contains($t, 'Raciocínio clínico') && str_contains($t, 'ótimo domínio')));
    }

    public function test_sem_dados_suficientes_nao_gera_nenhum_insight(): void
    {
        $insights = app(InsightService::class)->gerar($this->aluno(), [], [], []);

        $this->assertSame([], $insights);
    }
}
