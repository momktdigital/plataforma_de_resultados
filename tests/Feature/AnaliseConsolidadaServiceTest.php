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

    public function test_areas_divergentes_da_turma_so_lista_areas_abaixo_da_turma_ordenadas_pela_maior_diferenca(): void
    {
        $aluno = $this->aluno();
        $colegas = collect(range(2, 4))->map(fn ($i) => Aluno::create([
            'ra' => "202600{$i}", 'cpf' => null, 'data_nascimento' => '2000-01-01', 'nome' => "Colega {$i}",
        ]));

        $avaliacao = Avaliacao::create(['nome' => 'Prova']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A', 'area' => 'Clínica Médica']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'B', 'area' => 'Clínica Médica']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 3, 'gabarito' => 'C', 'area' => 'Pneumologia']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 4, 'gabarito' => 'D', 'area' => 'Neurologia']);

        // Aluno: Clínica Médica 1/2 (50%), Pneumologia 1/1 (100%, na média
        // da turma), Neurologia 0/1 (0%).
        foreach ([1 => 'A', 2 => 'X', 3 => 'C', 4 => 'X'] as $numero => $resposta) {
            Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => $aluno->ra, 'periodo' => '', 'questao_numero' => $numero, 'resposta' => $resposta]);
        }

        // Colegas 1 e 2: gabarito certo em tudo. Colega 3: erra só a
        // Neurologia — turma fica em Clínica Médica 87.5%, Pneumologia
        // 100% (igual ao aluno) e Neurologia 50%.
        foreach ($colegas as $i => $colega) {
            $respostaNeurologia = $i === 2 ? 'X' : 'D';
            foreach ([1 => 'A', 2 => 'B', 3 => 'C', 4 => $respostaNeurologia] as $numero => $resposta) {
                Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => $colega->ra, 'periodo' => '', 'questao_numero' => $numero, 'resposta' => $resposta]);
            }
        }

        $resultado = app(AnaliseConsolidadaService::class)->areasDivergentesDaTurma($aluno, [$avaliacao->codigo]);

        // Pneumologia fica de fora (aluno empata com a turma, não fica
        // abaixo). Neurologia (diferença de 50 pontos) vem antes de Clínica
        // Médica (37.5 pontos) — maior diferença primeiro.
        $this->assertCount(2, $resultado);
        $this->assertSame('Neurologia', $resultado[0]['area']);
        $this->assertSame(0.0, $resultado[0]['percentualAluno']);
        $this->assertSame(50.0, $resultado[0]['percentualTurma']);
        $this->assertSame(50.0, $resultado[0]['diferenca']);
        $this->assertSame('Clínica Médica', $resultado[1]['area']);
        $this->assertSame(50.0, $resultado[1]['percentualAluno']);
        $this->assertSame(87.5, $resultado[1]['percentualTurma']);
        $this->assertSame(37.5, $resultado[1]['diferenca']);
    }

    public function test_areas_divergentes_da_turma_ignora_diferenca_pequena_por_padrao(): void
    {
        $aluno = $this->aluno();
        $avaliacao = Avaliacao::create(['nome' => 'Prova']);
        for ($numero = 1; $numero <= 4; $numero++) {
            Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => $numero, 'gabarito' => 'A', 'area' => 'Clínica Médica']);
        }

        // Aluno acerta 3 das 4 (75%). 4 colegas: 3 gabaritam tudo, 1 acerta
        // só 1 — turma (incluindo o aluno) fecha em 16/20 = 80%, 5 pontos
        // acima do aluno. Diferença pequena (< 10 pontos), não deve aparecer
        // na lista por padrão, só quando o limiar é reduzido explicitamente.
        $responder = function (Aluno $pessoa, int $corretos) use ($avaliacao) {
            for ($numero = 1; $numero <= 4; $numero++) {
                Resposta::create([
                    'avaliacao_codigo' => $avaliacao->codigo, 'ra' => $pessoa->ra, 'periodo' => '',
                    'questao_numero' => $numero, 'resposta' => $numero <= $corretos ? 'A' : 'X',
                ]);
            }
        };

        $responder($aluno, 3);
        $responder(Aluno::create(['ra' => '2026002', 'cpf' => null, 'data_nascimento' => '2000-01-01', 'nome' => 'Colega 1']), 4);
        $responder(Aluno::create(['ra' => '2026003', 'cpf' => null, 'data_nascimento' => '2000-01-01', 'nome' => 'Colega 2']), 4);
        $responder(Aluno::create(['ra' => '2026004', 'cpf' => null, 'data_nascimento' => '2000-01-01', 'nome' => 'Colega 3']), 4);
        $responder(Aluno::create(['ra' => '2026005', 'cpf' => null, 'data_nascimento' => '2000-01-01', 'nome' => 'Colega 4']), 1);

        $service = app(AnaliseConsolidadaService::class);

        $comLimiarPadrao = $service->areasDivergentesDaTurma($aluno, [$avaliacao->codigo]);
        $this->assertSame([], $comLimiarPadrao);

        $comLimiarReduzido = $service->areasDivergentesDaTurma($aluno, [$avaliacao->codigo], diferencaMinima: 1.0);
        $this->assertCount(1, $comLimiarReduzido);
        $this->assertSame('Clínica Médica', $comLimiarReduzido[0]['area']);
        $this->assertSame(75.0, $comLimiarReduzido[0]['percentualAluno']);
        $this->assertSame(80.0, $comLimiarReduzido[0]['percentualTurma']);
        $this->assertSame(5.0, $comLimiarReduzido[0]['diferenca']);
    }
}
