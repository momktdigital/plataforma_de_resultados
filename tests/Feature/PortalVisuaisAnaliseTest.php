<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Painéis novos do portal: trilha de estudo (detalhe da avaliação) e mapa de
 * domínio por área (painéis por categoria do boletim).
 */
class PortalVisuaisAnaliseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sem nenhum admin o app se considera "não instalado" e manda todo
        // mundo pro wizard — ver App\Support\InstallStatus.
        Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    private function autenticar(): Aluno
    {
        $aluno = Aluno::create([
            'ra' => '2026001',
            'cpf' => '12345678909',
            'data_nascimento' => '2000-03-15',
            'nome' => 'Fulano de Tal',
        ]);

        $this->followingRedirects()->post('/portal/consultar', [
            'cpf' => '123.456.789-09',
            'data_nascimento' => '15/03/2000',
        ]);

        return $aluno;
    }

    public function test_trilha_de_estudo_lista_os_temas_por_ganho(): void
    {
        $aluno = $this->autenticar();
        $avaliacao = Avaliacao::create(['nome' => 'Diagnóstica']);

        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A', 'area' => 'Clínica Médica', 'tema' => 'Insuficiência cardíaca']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'A', 'area' => 'Clínica Médica', 'tema' => 'Insuficiência cardíaca']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 3, 'gabarito' => 'A', 'area' => 'Saúde Coletiva', 'tema' => 'Mortalidade infantil']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 4, 'gabarito' => 'A', 'area' => 'Saúde Coletiva', 'tema' => 'Mortalidade infantil']);

        foreach ([1 => 'B', 2 => 'B', 3 => 'B', 4 => 'A'] as $numero => $resposta) {
            Resposta::create([
                'avaliacao_codigo' => $avaliacao->codigo, 'ra' => $aluno->ra,
                'periodo' => '', 'questao_numero' => $numero, 'resposta' => $resposta,
            ]);
        }
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $response = $this->get(route('portal.resultados.avaliacao', ['avaliacao' => $avaliacao->codigo, 'periodo' => '']));

        $response->assertOk();
        $response->assertSee('Trilha de estudo');
        // Insuficiência cardíaca (2 erros de 4 questões = 50 pp) antes de
        // Mortalidade infantil (1 erro = 25 pp).
        $response->assertSeeInOrder(['Insuficiência cardíaca', 'Mortalidade infantil']);
        $response->assertSee('50,0 pp');
    }

    public function test_mapa_de_dominio_aparece_no_boletim_com_duas_avaliacoes_da_categoria(): void
    {
        $aluno = $this->autenticar();
        $categoria = Categoria::create(['nome' => 'Avaliações diagnósticas']);

        foreach ([['Diag. 1', '2026-03-01', 'B'], ['Diag. 2', '2026-06-01', 'A']] as [$nome, $data, $resposta]) {
            $avaliacao = Avaliacao::create(['nome' => $nome, 'data_avaliacao' => $data, 'categoria_id' => $categoria->id]);
            Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A', 'area' => 'Cardiologia', 'tema' => 'Arritmias']);
            Resposta::create([
                'avaliacao_codigo' => $avaliacao->codigo, 'ra' => $aluno->ra,
                'periodo' => '', 'questao_numero' => 1, 'resposta' => $resposta,
            ]);
            app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);
        }

        $response = $this->get(route('portal.resultados'));

        $response->assertOk();
        $response->assertSee('Mapa de domínio por área');
        $response->assertSee('Cardiologia');
        // Errou na primeira e acertou na segunda: consolidação de 100 pp.
        $response->assertSee('0%');
        $response->assertSee('100%');
    }

    public function test_boletim_sem_area_cadastrada_nao_mostra_mapa_de_dominio(): void
    {
        $aluno = $this->autenticar();
        $avaliacao = Avaliacao::create(['nome' => 'Sem área']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create([
            'avaliacao_codigo' => $avaliacao->codigo, 'ra' => $aluno->ra,
            'periodo' => '', 'questao_numero' => 1, 'resposta' => 'A',
        ]);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $response = $this->get(route('portal.resultados'));

        $response->assertOk();
        $response->assertDontSee('Mapa de domínio por área');
    }
}
