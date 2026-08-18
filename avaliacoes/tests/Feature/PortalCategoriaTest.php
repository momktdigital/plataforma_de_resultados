<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Categoria;
use App\Models\Prova;
use App\Models\Questao;
use App\Models\Resposta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalCategoriaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    private function aluno(): Aluno
    {
        return Aluno::create([
            'ra' => '2026001',
            'cpf' => '12345678909',
            'data_nascimento' => '2000-03-15',
            'nome' => 'Fulano de Tal',
        ]);
    }

    private function resultadoNaProva(Aluno $aluno, ?int $categoriaId, ?string $dataProva, string $nome): Prova
    {
        $prova = Prova::create(['nome' => $nome, 'categoria_id' => $categoriaId, 'data_prova' => $dataProva]);
        Questao::create(['prova_codigo' => $prova->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['prova_codigo' => $prova->codigo, 'ra' => $aluno->ra, 'questao_numero' => 1, 'resposta' => 'A']);

        return $prova;
    }

    public function test_prova_sem_categoria_aparece_fora_da_arvore(): void
    {
        $aluno = $this->aluno();
        $this->resultadoNaProva($aluno, null, null, 'Prova Solta');

        $response = $this->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '15/03/2000']);

        $response->assertOk();
        $response->assertSee('Prova Solta');
    }

    public function test_prova_com_categoria_aparece_dentro_da_arvore(): void
    {
        $aluno = $this->aluno();
        $categoria = Categoria::create(['nome' => 'Simulados']);
        $this->resultadoNaProva($aluno, $categoria->id, '2026-03-01', 'Simulado 1');

        $response = $this->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '15/03/2000']);

        $response->assertOk();
        $response->assertSee('Simulados');
        $response->assertSee('Simulado 1');
    }

    public function test_categoria_sem_resultado_do_aluno_nao_aparece(): void
    {
        $aluno = $this->aluno();
        Categoria::create(['nome' => 'Categoria vazia (sem provas do aluno)']);
        $categoriaComResultado = Categoria::create(['nome' => 'Com resultado']);
        $this->resultadoNaProva($aluno, $categoriaComResultado->id, null, 'Prova X');

        $response = $this->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '15/03/2000']);

        $response->assertOk();
        $response->assertSee('Com resultado');
        $response->assertDontSee('Categoria vazia (sem provas do aluno)');
    }

    public function test_subcategoria_aninhada_aparece_dentro_da_categoria_mae(): void
    {
        $aluno = $this->aluno();
        $mae = Categoria::create(['nome' => 'Simulados']);
        $filha = Categoria::create(['nome' => '1º ao 4º período', 'categoria_pai_id' => $mae->id]);
        $this->resultadoNaProva($aluno, $filha->id, null, 'Simulado do 1º período');

        $response = $this->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '15/03/2000']);

        $response->assertOk();
        $response->assertSee('Simulados');
        $response->assertSee('1º ao 4º período');
        $response->assertSee('Simulado do 1º período');
    }

    public function test_data_da_prova_aparece_no_boletim_para_filtro(): void
    {
        $aluno = $this->aluno();
        $prova = $this->resultadoNaProva($aluno, null, '2026-05-20', 'Prova com data');

        $response = $this->post('/portal/consultar', ['cpf' => $aluno->cpf, 'data_nascimento' => '15/03/2000']);

        $response->assertOk();
        $response->assertSee('20/05/2026');
        $response->assertSee('data-data="2026-05-20"', false);
    }
}
