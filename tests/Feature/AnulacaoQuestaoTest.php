<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\EstatisticaErroService;
use App\Services\Portal\RelatorioAlunoService;
use App\Services\RelatorioAdminService;
use App\Services\ResumoResultadoService;
use App\Support\Anulacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnulacaoQuestaoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    public function test_anular_e_dar_o_ponto_credita_todo_mundo_mas_mantem_a_questao_no_total(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'B']);

        // Aluno 1 acerta as duas; aluno 2 erra a questão 1 (que será anulada).
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 2, 'resposta' => 'B']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'questao_numero' => 1, 'resposta' => 'X']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'questao_numero' => 2, 'resposta' => 'B']);

        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);
        $this->assertDatabaseHas('resultado_resumos', ['ra' => '2', 'acertos' => 1, 'total' => 2]);

        $questao1 = Questao::where('avaliacao_codigo', $avaliacao->codigo)->where('numero', 1)->first();

        $this->actingAs($this->admin(), 'admin')->post(route('avaliacoes.questoes.store', $avaliacao), [
            'numero' => 1,
            'gabarito' => $questao1->gabarito,
            'anulada_modo' => Anulacao::MODO_DAR_PONTO,
        ])->assertRedirect();

        $this->assertDatabaseHas('questoes', ['id' => $questao1->id, 'anulada_modo' => 'dar_ponto']);

        // Aluno 2 agora é creditado com a questão 1 (mesmo tendo respondido X)
        // — acertos sobe pra 2, mas o total continua 2 (a questão não saiu da prova).
        $this->assertDatabaseHas('resultado_resumos', ['ra' => '2', 'acertos' => 2, 'total' => 2]);
        $this->assertDatabaseHas('resultado_resumos', ['ra' => '1', 'acertos' => 2, 'total' => 2]);
    }

    public function test_anular_e_distribuir_pontuacao_remove_a_questao_do_total(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'B']);

        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'X']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 2, 'resposta' => 'B']);

        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);
        $this->assertDatabaseHas('resultado_resumos', ['ra' => '1', 'acertos' => 1, 'total' => 2]);

        $questao1 = Questao::where('avaliacao_codigo', $avaliacao->codigo)->where('numero', 1)->first();

        $this->actingAs($this->admin(), 'admin')->post(route('avaliacoes.questoes.store', $avaliacao), [
            'numero' => 1,
            'gabarito' => $questao1->gabarito,
            'anulada_modo' => Anulacao::MODO_DISTRIBUIR_PONTUACAO,
        ])->assertRedirect();

        // A questão 1 sai do cálculo inteiro: total cai de 2 pra 1, e o
        // aluno segue com 1 acerto (a questão 2, que ele realmente acertou).
        $this->assertDatabaseHas('resultado_resumos', ['ra' => '1', 'acertos' => 1, 'total' => 1]);
    }

    public function test_estatistica_erro_nao_lista_questao_anulada(): void
    {
        $avaliacao = Avaliacao::create([]);
        $questao = Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'B']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'questao_numero' => 1, 'resposta' => 'C']);

        $antes = app(EstatisticaErroService::class)->calcular($avaliacao);
        $this->assertNotEmpty($antes);

        $questao->update(['anulada_modo' => Anulacao::MODO_DAR_PONTO]);

        $depois = app(EstatisticaErroService::class)->calcular($avaliacao);
        $this->assertEmpty($depois);
    }

    public function test_media_por_bloom_credita_dar_ponto_e_exclui_distribuir_pontuacao(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A', 'bloom_nivel' => 'Lembrar', 'anulada_modo' => Anulacao::MODO_DAR_PONTO]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'B', 'bloom_nivel' => 'Lembrar', 'anulada_modo' => Anulacao::MODO_DISTRIBUIR_PONTUACAO]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 3, 'gabarito' => 'C', 'bloom_nivel' => 'Lembrar']);

        // Ninguém acerta a questão 1 de verdade (mas ela dá o ponto), ninguém
        // acerta a 2 (mas ela nem deveria contar), e todo mundo acerta a 3.
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'X']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 2, 'resposta' => 'X']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 3, 'resposta' => 'C']);

        $resultado = (new RelatorioAdminService)->mediaPorBloom($avaliacao);

        // Questão 1 (dar_ponto, sempre acerto) + questão 3 (acerto real) = 2/2 = 100%.
        // Questão 2 (distribuir_pontuacao) nem entra na conta.
        $this->assertEquals(['Lembrar' => 100.0], $resultado);
    }

    public function test_boletim_do_aluno_marca_questao_anulada_e_credita_dar_ponto(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A', 'anulada_modo' => Anulacao::MODO_DAR_PONTO]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'B']);

        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2026001', 'questao_numero' => 1, 'resposta' => 'X']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2026001', 'questao_numero' => 2, 'resposta' => 'B']);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $this->admin();
        Aluno::create(['ra' => '2026001', 'cpf' => '12345678909', 'data_nascimento' => '2000-03-15', 'nome' => 'Fulano']);

        $this->followingRedirects()->post('/portal/consultar', [
            'cpf' => '123.456.789-09',
            'data_nascimento' => '15/03/2000',
        ]);

        $detalhe = $this->get(route('portal.resultados.avaliacao', ['avaliacao' => $avaliacao->codigo, 'periodo' => '']));
        $detalhe->assertOk();
        // Q1 é anulada (dar_ponto) mesmo respondida errada: aparece com "*" e
        // conta no total geral (100%, não 50%).
        $detalhe->assertSee('Q1*', false);
        $detalhe->assertSee('2/2', false);
    }

    public function test_lacunas_e_consolidados_ignoram_questao_distribuir_pontuacao(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A', 'area' => 'Pediatria', 'tema' => 'COVID', 'anulada_modo' => Anulacao::MODO_DISTRIBUIR_PONTUACAO]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'B', 'area' => 'Pediatria', 'tema' => 'Bradicardia']);

        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2026001', 'questao_numero' => 1, 'resposta' => 'X']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2026001', 'questao_numero' => 2, 'resposta' => 'X']);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $this->admin();
        Aluno::create(['ra' => '2026001', 'cpf' => '12345678909', 'data_nascimento' => '2000-03-15', 'nome' => 'Fulano']);

        $this->followingRedirects()->post('/portal/consultar', [
            'cpf' => '123.456.789-09',
            'data_nascimento' => '15/03/2000',
        ]);

        $detalhe = $this->get(route('portal.resultados.avaliacao', ['avaliacao' => $avaliacao->codigo, 'periodo' => '']));
        $detalhe->assertOk();
        // A página ainda pode citar "COVID" em outros lugares (ex.: filtro
        // de tema no detalhamento das respostas, que lista toda questão
        // respondida) — o que importa é que o cartão de lacunas em si só
        // cite a questão que realmente conta na prova (Bradicardia).
        $detalhe->assertSeeInOrder(['Lacunas de aprendizagem', 'Bradicardia']);

        $respostas = Resposta::where('avaliacao_codigo', $avaliacao->codigo)->get();
        $gabaritos = Questao::where('avaliacao_codigo', $avaliacao->codigo)->pluck('gabarito', 'numero');
        $lacunas = app(RelatorioAlunoService::class)
            ->lacunasEConsolidados($respostas, $gabaritos, $avaliacao);
        $this->assertStringNotContainsString('COVID', json_encode($lacunas));
    }
}
