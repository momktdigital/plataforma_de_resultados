<?php

namespace Tests\Feature;

use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Services\Portal\RelatorioAlunoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelatorioAlunoServiceTest extends TestCase
{
    use RefreshDatabase;

    private function resultado(Avaliacao $avaliacao, ?float $percentual): array
    {
        return ['avaliacao' => $avaliacao, 'percentual' => $percentual];
    }

    public function test_evolucao_por_categoria_usa_nome_e_data_no_rotulo(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulado MedCof']);
        $av1 = Avaliacao::create(['nome' => 'Simulado MedCof', 'categoria_id' => $categoria->id, 'data_avaliacao' => '2026-05-25'])
            ->load('categoria');
        $av2 = Avaliacao::create(['nome' => 'Simulado MedCof', 'categoria_id' => $categoria->id, 'data_avaliacao' => '2026-06-20'])
            ->load('categoria');

        $resultados = [$this->resultado($av1, 53.0), $this->resultado($av2, 66.0)];

        $evolucao = app(RelatorioAlunoService::class)->evolucaoPorCategoria($resultados);

        $this->assertCount(1, $evolucao);
        $this->assertSame('Simulado MedCof', $evolucao[0]['categoria_nome']);
        $this->assertSame([
            ['codigo' => $av1->codigo, 'nome' => 'Simulado MedCof', 'data' => '25/05/2026', 'percentual' => 53.0],
            ['codigo' => $av2->codigo, 'nome' => 'Simulado MedCof', 'data' => '20/06/2026', 'percentual' => 66.0],
        ], $evolucao[0]['pontos']);
    }

    public function test_evolucao_por_categoria_nao_junta_categorias_diferentes(): void
    {
        $diagnostico = Categoria::create(['nome' => 'Diagnóstico Institucional']);
        $simulado = Categoria::create(['nome' => 'Simulado MedCof']);
        $av1 = Avaliacao::create(['nome' => 'Diagnostico', 'categoria_id' => $diagnostico->id, 'data_avaliacao' => '2026-01-10'])->load('categoria');
        $av2 = Avaliacao::create(['nome' => 'Simulado 1', 'categoria_id' => $simulado->id, 'data_avaliacao' => '2026-02-10'])->load('categoria');

        $resultados = [$this->resultado($av1, 40.0), $this->resultado($av2, 90.0)];

        $evolucao = app(RelatorioAlunoService::class)->evolucaoPorCategoria($resultados);

        // Cada categoria só tem 1 avaliação — nenhuma série (nem misturada,
        // nem separada) deve ser formada ainda.
        $this->assertSame([], $evolucao);
    }

    public function test_comparativo_turma_consolidado_e_null_sem_turma(): void
    {
        $aluno = Aluno::create(['ra' => '1', 'cpf' => null, 'data_nascimento' => '2000-01-01', 'nome' => 'Fulano', 'turma' => null]);

        $resultado = app(RelatorioAlunoService::class)->comparativoTurmaConsolidado($aluno, []);

        $this->assertNull($resultado);
    }
}
