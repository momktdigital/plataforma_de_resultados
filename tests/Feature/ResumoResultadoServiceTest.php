<?php

namespace Tests\Feature;

use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResumoResultadoServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_total_e_calculado_por_aluno_quando_cada_um_respondeu_uma_quantidade_diferente_de_questoes(): void
    {
        // Regressão: uma prova do Avalia Pro com banco de questões aleatório
        // dá uma quantidade de questões diferente por aluno (confirmado com
        // dado real: banco de 18, 12 sorteadas por aluno) — usar um total
        // fixo pra avaliação inteira faria todo aluno ser avaliado sobre o
        // pool inteiro, não só o que ele de fato respondeu.
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'B']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 3, 'gabarito' => 'C']);

        // Aluno 1 "viu" as 3 questões e acertou todas.
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 2, 'resposta' => 'B']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 3, 'resposta' => 'C']);

        // Aluno 2 só "viu" 2 das 3 (não existe linha nenhuma pra questão 3
        // dele) e acertou as duas.
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'questao_numero' => 2, 'resposta' => 'B']);

        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $this->assertDatabaseHas('resultado_resumos', ['ra' => '1', 'acertos' => 3, 'total' => 3]);
        $this->assertDatabaseHas('resultado_resumos', ['ra' => '2', 'acertos' => 2, 'total' => 2]);
    }

    public function test_aluno_sem_nenhuma_resposta_real_e_marcado_como_ausente(): void
    {
        // Regressão: um aluno que não fez a prova (todas as respostas dele
        // são o sentinela "-", ver Resposta::SENTINELAS_SEM_RESPOSTA) não
        // deveria contar como "errou tudo" no boletim/relatórios.
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'B']);

        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => '-']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 2, 'resposta' => '-']);

        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $this->assertDatabaseHas('resultado_resumos', ['ra' => '1', 'acertos' => 0, 'total' => 2, 'ausente' => true]);
    }

    public function test_correta_pre_calculada_vence_a_comparacao_de_letra(): void
    {
        // Caso real: o Avalia Pro embaralha a ordem das alternativas por
        // aluno, então a letra marcada não é comparável com um gabarito
        // único — respostas.correta é o veredito de verdade nesse caso (ver
        // Anulacao::condicaoAcertoSql()).
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => '-']);

        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A', 'correta' => true]);

        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $this->assertDatabaseHas('resultado_resumos', ['ra' => '1', 'acertos' => 1, 'total' => 1]);
    }

    public function test_aluno_com_pelo_menos_uma_resposta_real_nao_e_marcado_como_ausente(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'B']);

        // Respondeu a questão 1 (errado) e deixou a 2 em branco.
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'X']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 2, 'resposta' => 'BLANK']);

        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $this->assertDatabaseHas('resultado_resumos', ['ra' => '1', 'acertos' => 0, 'total' => 2, 'ausente' => false]);
    }
}
