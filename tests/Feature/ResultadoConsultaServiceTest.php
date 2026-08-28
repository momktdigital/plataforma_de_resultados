<?php

namespace Tests\Feature;

use App\Models\Aluno;
use App\Models\Avaliacao;
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
}
