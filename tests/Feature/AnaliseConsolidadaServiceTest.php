<?php

namespace Tests\Feature;

use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\Portal\AnaliseConsolidadaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnaliseConsolidadaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function aluno(): Aluno
    {
        return Aluno::create(['ra' => '2026001', 'cpf' => null, 'data_nascimento' => '2000-01-01', 'nome' => 'Fulano de Tal']);
    }

    public function test_curva_dificuldade_soma_acertos_em_varias_avaliacoes_do_mesmo_aluno(): void
    {
        $aluno = $this->aluno();

        $av1 = Avaliacao::create(['nome' => 'Prova 1']);
        Questao::create(['avaliacao_codigo' => $av1->codigo, 'numero' => 1, 'gabarito' => 'A', 'dificuldade_pedagogica' => 'facil']);
        Questao::create(['avaliacao_codigo' => $av1->codigo, 'numero' => 2, 'gabarito' => 'B', 'dificuldade_pedagogica' => 'dificil']);
        Resposta::create(['avaliacao_codigo' => $av1->codigo, 'ra' => $aluno->ra, 'periodo' => '', 'questao_numero' => 1, 'resposta' => 'A']); // acertou fácil
        Resposta::create(['avaliacao_codigo' => $av1->codigo, 'ra' => $aluno->ra, 'periodo' => '', 'questao_numero' => 2, 'resposta' => 'X']); // errou difícil

        $av2 = Avaliacao::create(['nome' => 'Prova 2']);
        Questao::create(['avaliacao_codigo' => $av2->codigo, 'numero' => 1, 'gabarito' => 'C', 'dificuldade_pedagogica' => 'facil']);
        Resposta::create(['avaliacao_codigo' => $av2->codigo, 'ra' => $aluno->ra, 'periodo' => '', 'questao_numero' => 1, 'resposta' => 'X']); // errou fácil

        $resultado = app(AnaliseConsolidadaService::class)->curvaDificuldadePedagogica($aluno, [$av1->codigo, $av2->codigo]);

        // 1 acerto em 2 fáceis (das duas provas) = 50%; 0 acertos em 1 difícil = 0%.
        $this->assertSame(50.0, $resultado['facil']['percentual']);
        $this->assertSame(2, $resultado['facil']['respostas']);
        $this->assertSame(0.0, $resultado['dificil']['percentual']);
    }

    public function test_questao_distribuir_pontuacao_nao_conta_em_nenhum_agrupamento(): void
    {
        $aluno = $this->aluno();
        $avaliacao = Avaliacao::create(['nome' => 'Prova Anulada']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A', 'habilidade' => 'Anamnese', 'anulada_modo' => 'distribuir_pontuacao']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'B', 'habilidade' => 'Anamnese']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => $aluno->ra, 'periodo' => '', 'questao_numero' => 1, 'resposta' => 'X']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => $aluno->ra, 'periodo' => '', 'questao_numero' => 2, 'resposta' => 'B']);

        $resultado = app(AnaliseConsolidadaService::class)->coberturaHabilidade($aluno, [$avaliacao->codigo]);

        // Só a questão 2 (não anulada) entra na conta: 1 acerto em 1 = 100%,
        // não 1 em 2 (que incluiria a distribuir_pontuacao no total).
        $this->assertSame(['Anamnese' => 100.0], $resultado);
    }

    public function test_questoes_divergentes_da_turma_so_lista_erro_do_aluno_com_turma_acertando_acima_do_limiar(): void
    {
        $alunoAvaliado = $this->aluno();
        $colega = Aluno::create(['ra' => '2026002', 'cpf' => null, 'data_nascimento' => '2000-01-01', 'nome' => 'Colega']);

        $avaliacao = Avaliacao::create(['nome' => 'Prova']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A', 'area' => 'Clínica Médica', 'tema' => 'Cardiologia']);

        // O aluno avaliado erra; o colega acerta — turma acerta 50% (1 de 2),
        // abaixo do limiar padrão (60%), então NÃO deve aparecer na lista.
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => $alunoAvaliado->ra, 'periodo' => '', 'questao_numero' => 1, 'resposta' => 'X']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => $colega->ra, 'periodo' => '', 'questao_numero' => 1, 'resposta' => 'X']);

        $semDivergencia = app(AnaliseConsolidadaService::class)->questoesDivergentesDaTurma($alunoAvaliado, [$avaliacao->codigo]);
        $this->assertSame([], $semDivergencia);

        // Adiciona mais 3 colegas que acertam — turma passa a acertar 3 de 5
        // (60%), no limiar, aluno avaliado continua errando.
        foreach (range(3, 5) as $i) {
            $outroColega = Aluno::create(['ra' => "202600{$i}", 'cpf' => null, 'data_nascimento' => '2000-01-01', 'nome' => "Colega {$i}"]);
            Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => $outroColega->ra, 'periodo' => '', 'questao_numero' => 1, 'resposta' => 'A']);
        }

        $comDivergencia = app(AnaliseConsolidadaService::class)->questoesDivergentesDaTurma($alunoAvaliado, [$avaliacao->codigo]);

        $this->assertCount(1, $comDivergencia);
        $this->assertSame('Clínica Médica', $comDivergencia[0]['area']);
        $this->assertSame('Cardiologia', $comDivergencia[0]['tema']);
        $this->assertSame(1, $comDivergencia[0]['ocorrencias']);
        $this->assertSame(60.0, $comDivergencia[0]['taxaAcertoTurmaMedia']);
    }
}
