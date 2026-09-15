<?php

namespace Tests\Feature;

use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\Questao;
use App\Models\Resposta;
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

    public function test_trilha_de_estudo_ordena_por_ganho_e_calcula_em_pontos_percentuais(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A', 'area' => 'Clínica Médica', 'tema' => 'Insuficiência cardíaca']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'A', 'area' => 'Clínica Médica', 'tema' => 'Insuficiência cardíaca']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 3, 'gabarito' => 'A', 'area' => 'Saúde Coletiva', 'tema' => 'Mortalidade infantil']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 4, 'gabarito' => 'A', 'area' => 'Saúde Coletiva', 'tema' => 'Mortalidade infantil']);

        // Erra as duas de insuficiência cardíaca e uma de mortalidade infantil.
        foreach ([1 => 'B', 2 => 'B', 3 => 'B', 4 => 'A'] as $numero => $resposta) {
            Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => $numero, 'resposta' => $resposta]);
        }

        $respostas = Resposta::where('avaliacao_codigo', $avaliacao->codigo)->get();
        $gabaritos = Questao::where('avaliacao_codigo', $avaliacao->codigo)->pluck('gabarito', 'numero');

        $trilha = app(RelatorioAlunoService::class)->trilhaDeEstudo($respostas, $gabaritos, $avaliacao);

        $this->assertCount(2, $trilha);

        // 2 erros em 4 questões válidas = 50 pp de ganho; vem primeiro.
        $this->assertSame('Insuficiência cardíaca', $trilha[0]['tema']);
        $this->assertSame('Clínica Médica', $trilha[0]['area']);
        $this->assertSame(2, $trilha[0]['erros']);
        $this->assertSame(50.0, $trilha[0]['ganho']);

        $this->assertSame('Mortalidade infantil', $trilha[1]['tema']);
        $this->assertSame(25.0, $trilha[1]['ganho']);
    }

    public function test_trilha_de_estudo_ignora_questao_com_pontuacao_distribuida(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create([
            'avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A',
            'area' => 'Pediatria', 'tema' => 'COVID', 'anulada_modo' => 'distribuir_pontuacao',
        ]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'A', 'area' => 'Pediatria', 'tema' => 'Bradicardia']);

        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'X']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 2, 'resposta' => 'X']);

        $respostas = Resposta::where('avaliacao_codigo', $avaliacao->codigo)->get();
        $gabaritos = Questao::where('avaliacao_codigo', $avaliacao->codigo)->pluck('gabarito', 'numero');

        $trilha = app(RelatorioAlunoService::class)->trilhaDeEstudo($respostas, $gabaritos, $avaliacao);

        // A questão anulada sai do numerador E do denominador: sobra só
        // Bradicardia, 1 erro em 1 questão válida.
        $this->assertCount(1, $trilha);
        $this->assertSame('Bradicardia', $trilha[0]['tema']);
        $this->assertSame(100.0, $trilha[0]['ganho']);
    }

    public function test_trilha_de_estudo_vazia_quando_o_aluno_acertou_tudo(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A', 'area' => 'Pediatria', 'tema' => 'COVID']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);

        $respostas = Resposta::where('avaliacao_codigo', $avaliacao->codigo)->get();
        $gabaritos = Questao::where('avaliacao_codigo', $avaliacao->codigo)->pluck('gabarito', 'numero');

        $this->assertSame([], app(RelatorioAlunoService::class)->trilhaDeEstudo($respostas, $gabaritos, $avaliacao));
    }
}
