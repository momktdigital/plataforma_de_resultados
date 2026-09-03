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

        $insights = app(InsightService::class)->gerar($aluno, $resultados, [], null, []);

        $textos = array_column($insights, 'texto');
        $this->assertTrue(
            collect($textos)->contains(fn ($t) => str_contains($t, 'Farmacologia') && str_contains($t, 'caiu') && str_contains($t, '100')),
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

        $insights = app(InsightService::class)->gerar($aluno, $resultados, [], null, []);

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

        $insights = app(InsightService::class)->gerar($this->aluno(), [], $evolucaoPorCategoria, null, []);

        $textos = array_column($insights, 'texto');
        $this->assertTrue(collect($textos)->contains(fn ($t) => str_contains($t, 'Simulado MedCof') && str_contains($t, '3 avaliações seguidas')));
    }

    public function test_comparativo_com_turma_gera_insight_positivo_e_negativo(): void
    {
        $aluno = $this->aluno();

        $insightsPositivo = app(InsightService::class)->gerar($aluno, [], [], [
            'turma' => 'A',
            'suaMedia' => 80.0,
            'mediaTurma' => 60.0,
            'avaliacoesComparadas' => 3,
        ], []);
        $this->assertTrue(collect(array_column($insightsPositivo, 'texto'))->contains(fn ($t) => str_contains($t, 'acima da média')));

        $insightsNegativo = app(InsightService::class)->gerar($aluno, [], [], [
            'turma' => 'A',
            'suaMedia' => 40.0,
            'mediaTurma' => 60.0,
            'avaliacoesComparadas' => 3,
        ], []);
        $this->assertTrue(collect(array_column($insightsNegativo, 'texto'))->contains(fn ($t) => str_contains($t, 'abaixo da média')));
    }

    public function test_extremos_de_habilidade_geram_insight_de_ponto_fraco_e_forte(): void
    {
        $coberturaHabilidade = ['Anamnese' => 30.0, 'Exame físico' => 55.0, 'Raciocínio clínico' => 90.0];

        $insights = app(InsightService::class)->gerar($this->aluno(), [], [], null, $coberturaHabilidade);

        $textos = array_column($insights, 'texto');
        $this->assertTrue(collect($textos)->contains(fn ($t) => str_contains($t, 'Anamnese') && str_contains($t, 'menor aproveitamento')));
        $this->assertTrue(collect($textos)->contains(fn ($t) => str_contains($t, 'Raciocínio clínico') && str_contains($t, 'ótimo domínio')));
    }

    public function test_sem_dados_suficientes_nao_gera_nenhum_insight(): void
    {
        $insights = app(InsightService::class)->gerar($this->aluno(), [], [], null, []);

        $this->assertSame([], $insights);
    }
}
