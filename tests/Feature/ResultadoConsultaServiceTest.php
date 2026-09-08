<?php

namespace Tests\Feature;

use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\ResultadoResumo;
use App\Services\Portal\ResultadoConsultaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResultadoConsultaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_aluno_com_ra_e_cpf_vazios_nao_ve_resultado_de_outro_aluno(): void
    {
        $avaliacao = Avaliacao::create(['nome' => 'ENADE 2026']);

        // Um import malformado deixou o RA (texto livre, nulável) em branco
        // tanto no aluno quanto na linha de resultado importada.
        ResultadoResumo::create([
            'avaliacao_codigo' => $avaliacao->codigo,
            'aluno_chave' => 'ra:',
            'periodo' => '2026/1',
            'ra' => '',
            'acertos' => 8,
            'total' => 10,
            'percentual' => 80,
        ]);

        $alunoSemRa = Aluno::create([
            'ra' => '',
            'cpf' => null,
            'data_nascimento' => '2000-01-01',
            'nome' => 'Sem RA Cadastrado',
        ]);

        $resultados = app(ResultadoConsultaService::class)->buscarPorAluno($alunoSemRa);

        $this->assertSame([], $resultados);
    }

    public function test_aluno_com_ra_preenchido_ve_seu_proprio_resultado(): void
    {
        $avaliacao = Avaliacao::create(['nome' => 'ENADE 2026']);

        ResultadoResumo::create([
            'avaliacao_codigo' => $avaliacao->codigo,
            'aluno_chave' => 'ra:2026001',
            'periodo' => '2026/1',
            'ra' => '2026001',
            'acertos' => 8,
            'total' => 10,
            'percentual' => 80,
        ]);

        $aluno = Aluno::create([
            'ra' => '2026001',
            'cpf' => null,
            'data_nascimento' => '2000-01-01',
            'nome' => 'Fulano de Tal',
        ]);

        $resultados = app(ResultadoConsultaService::class)->buscarPorAluno($aluno);

        $this->assertCount(1, $resultados);
        $this->assertSame(8, $resultados[0]['acertos']);
    }

    public function test_avaliacao_ausente_fica_fora_da_evolucao_e_da_media_por_categoria(): void
    {
        // Um "0%" de uma prova não feita não deveria puxar o gráfico de
        // evolução nem a média por categoria do próprio aluno pra baixo.
        $categoria = Categoria::create(['nome' => 'ENADE']);
        $avaliacaoFeita = Avaliacao::create(['nome' => 'Prova 1', 'categoria_id' => $categoria->id, 'data_avaliacao' => '2026-01-10']);
        $avaliacaoAusente = Avaliacao::create(['nome' => 'Prova 2', 'categoria_id' => $categoria->id, 'data_avaliacao' => '2026-02-10']);

        ResultadoResumo::create([
            'avaliacao_codigo' => $avaliacaoFeita->codigo, 'aluno_chave' => 'ra:2026001', 'periodo' => '',
            'ra' => '2026001', 'acertos' => 8, 'total' => 10, 'percentual' => 80, 'ausente' => false,
        ]);
        ResultadoResumo::create([
            'avaliacao_codigo' => $avaliacaoAusente->codigo, 'aluno_chave' => 'ra:2026001', 'periodo' => '',
            'ra' => '2026001', 'acertos' => 0, 'total' => 10, 'percentual' => 0, 'ausente' => true,
        ]);

        $aluno = Aluno::create(['ra' => '2026001', 'cpf' => null, 'data_nascimento' => '2000-01-01', 'nome' => 'Fulano de Tal']);

        $service = app(ResultadoConsultaService::class);
        $resultados = $service->buscarPorAluno($aluno);

        $evolucao = $service->evolucaoGeral($resultados);
        $this->assertCount(1, $evolucao);
        $this->assertSame(80.0, $evolucao[0]['percentual']);

        $arvore = $service->montarArvore($resultados);
        $resumo = $service->resumoPorCategoria($arvore['arvore']);
        $this->assertSame(80.0, $resumo[0]['media']);
        $this->assertSame(1, $resumo[0]['quantidade']);
    }
}
