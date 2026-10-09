<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\Portal\RelatorioAlunoService;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tela do aluno numa avaliação (/portal/resultados/avaliacoes/{avaliacao}): sem comparação com a turma, desempenho por
 * área contra a meta de cada área, e trilha/lacunas/consolidados só com o que o aluno precisava acertar pelo período.
 */
class PortalAvaliacaoMetaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    private function aluno(): Aluno
    {
        return Aluno::create(['ra' => '2026001', 'cpf' => '12345678909', 'data_nascimento' => '2000-03-15', 'nome' => 'Fulano de Tal']);
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: ?int, 3: string}>  $questoes  [área, tema, período mínimo, resposta] — gabarito "A"
     */
    private function prova(Aluno $aluno, string $periodoDoAluno, array $questoes): Avaliacao
    {
        $avaliacao = Avaliacao::create(['nome' => 'Diagnóstica']);

        foreach ($questoes as $i => [$area, $tema, $minimo, $resposta]) {
            Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => $i + 1, 'gabarito' => 'A', 'area' => $area, 'tema' => $tema, 'periodo_minimo' => $minimo]);
            Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => $aluno->ra, 'periodo' => $periodoDoAluno, 'questao_numero' => $i + 1, 'resposta' => $resposta]);
        }
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        return $avaliacao;
    }

    /** @return array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection} */
    private function respostasEGabaritos(Avaliacao $avaliacao): array
    {
        return [
            Resposta::where('avaliacao_codigo', $avaliacao->codigo)->orderBy('questao_numero')->get(),
            Questao::where('avaliacao_codigo', $avaliacao->codigo)->pluck('gabarito', 'numero'),
        ];
    }

    public function test_trilha_so_cobra_o_erro_de_questao_que_o_aluno_precisava_acertar(): void
    {
        $aluno = $this->aluno();
        $avaliacao = $this->prova($aluno, '2º', [
            ['Cardiologia', 'Arritmias', 1, 'B'],  // devia acertar e errou → entra
            ['Cardiologia', 'Valvulopatias', 5, 'B'], // 5º período: à frente, errou → NÃO entra
            ['Pneumologia', 'Asma', null, 'A'],    // sem meta, acertou
            ['Pneumologia', 'DPOC', 2, 'A'],       // acertou
        ]);
        [$respostas, $gabaritos] = $this->respostasEGabaritos($avaliacao);

        $servico = app(RelatorioAlunoService::class);

        $semFiltro = $servico->trilhaDeEstudo($respostas, $gabaritos, $avaliacao);
        $this->assertSame(['Arritmias', 'Valvulopatias'], array_column($semFiltro, 'tema'));

        $trilha = $servico->trilhaDeEstudo($respostas, $gabaritos, $avaliacao, 6, 2);
        $this->assertSame(['Arritmias'], array_column($trilha, 'tema'));
        // O ganho segue sendo sobre a prova inteira (4 questões): 1 erro = 25%.
        $this->assertSame(25.0, $trilha[0]['ganho']);
    }

    public function test_lacunas_e_consolidados_ignoram_as_questoes_de_periodos_a_frente(): void
    {
        $aluno = $this->aluno();
        $avaliacao = $this->prova($aluno, '2º', [
            ['Cardiologia', 'Arritmias', 1, 'B'],        // lacuna
            ['Cardiologia', 'Valvulopatias', 5, 'B'],    // à frente: nem lacuna
            ['Pneumologia', 'Asma', 4, 'A'],             // à frente: acertou, nem consolidado
            ['Pneumologia', 'DPOC', 2, 'A'],             // consolidado
        ]);
        [$respostas, $gabaritos] = $this->respostasEGabaritos($avaliacao);

        $resultado = app(RelatorioAlunoService::class)->lacunasEConsolidados($respostas, $gabaritos, $avaliacao, 2);

        $this->assertSame([['Cardiologia', 1]], array_map(fn ($c) => [$c['area'], $c['total']], $resultado['lacunas']));
        $this->assertStringContainsString('Arritmias', $resultado['lacunas'][0]['texto']);
        $this->assertStringNotContainsString('Valvulopatias', $resultado['lacunas'][0]['texto']);
        $this->assertSame([['Pneumologia', 1]], array_map(fn ($c) => [$c['area'], $c['total']], $resultado['consolidados']));
        $this->assertStringNotContainsString('Asma', $resultado['consolidados'][0]['texto']);
    }

    public function test_sem_periodo_do_aluno_nada_e_filtrado(): void
    {
        $aluno = $this->aluno();
        $avaliacao = $this->prova($aluno, '', [['Cardiologia', 'Valvulopatias', 5, 'B']]);
        [$respostas, $gabaritos] = $this->respostasEGabaritos($avaliacao);

        $this->assertCount(1, app(RelatorioAlunoService::class)->trilhaDeEstudo($respostas, $gabaritos, $avaliacao, 6, null));
    }

    public function test_desempenho_por_area_traz_a_meta_de_cada_area(): void
    {
        $aluno = $this->aluno();
        $avaliacao = $this->prova($aluno, '2º', [
            ['Cardiologia', 'Arritmias', 1, 'A'],     // esperada, acertou
            ['Cardiologia', 'Valvulopatias', 5, 'B'], // à frente, errou
            ['Pneumologia', 'Asma', null, 'A'],       // sem meta nenhuma nesta área → meta padrão
            ['Pneumologia', 'DPOC', null, 'B'],
        ]);
        [$respostas, $gabaritos] = $this->respostasEGabaritos($avaliacao);

        $areas = collect(app(RelatorioAlunoService::class)->desempenhoPorAreaComMeta($respostas, $gabaritos, $avaliacao, 2))->keyBy('area');

        $this->assertSame(50.0, $areas['Cardiologia']['percentual']);
        $this->assertSame(50.0, $areas['Cardiologia']['meta']); // 1 de 2 questões cabe no 2º período
        $this->assertTrue($areas['Cardiologia']['comMeta']);
        $this->assertSame(50.0, $areas['Pneumologia']['percentual']);
        $this->assertSame(60.0, $areas['Pneumologia']['meta']); // padrão
        $this->assertFalse($areas['Pneumologia']['comMeta']);
        // Mais longe da meta primeiro: Pneumologia (50 vs 60) antes de Cardiologia (50 vs 50).
        $this->assertSame('Pneumologia', array_values($areas->all())[0]['area'] ?? null);
    }

    public function test_leitura_da_prova_explica_o_tipo_de_pergunta_sem_falar_em_bloom(): void
    {
        $aluno = $this->aluno();
        $avaliacao = Avaliacao::create(['nome' => 'Diagnóstica']);
        // 4 perguntas de "lembrar" (todas certas) e 4 de "analisar" (todas erradas).
        foreach (range(1, 8) as $n) {
            Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => $n, 'gabarito' => 'A', 'area' => 'Cardiologia', 'tema' => 'T'.$n, 'bloom_nivel' => $n <= 4 ? 'Lembrar' : 'Análise']);
            Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => $aluno->ra, 'periodo' => '', 'questao_numero' => $n, 'resposta' => $n <= 4 ? 'A' : 'B']);
        }
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $this->followingRedirects()->post('/portal/consultar', ['cpf' => '123.456.789-09', 'data_nascimento' => '15/03/2000']);
        $response = $this->get(route('portal.resultados.avaliacao', ['avaliacao' => $avaliacao->codigo, 'periodo' => '']));

        $response->assertOk();
        $response->assertSee('As perguntas que pedem para relacionar dados de um caso e separar o que é relevante foram as que tiveram menor índice de acerto', false);
        $response->assertSee('Seu melhor rendimento foi nas perguntas que pedem para lembrar conceitos', false);
        $response->assertSee('referência de 60%', false);
        $response->assertDontSee('Bloom');
    }

    public function test_tela_da_avaliacao_sem_comparativos_com_a_turma_e_com_desempenho_por_area_contra_a_meta(): void
    {
        $aluno = $this->aluno();
        $avaliacao = $this->prova($aluno, '2º', [
            ['Cardiologia', 'Arritmias', 1, 'B'],
            ['Cardiologia', 'Valvulopatias', 5, 'B'],
            ['Pneumologia', 'Asma', null, 'A'],
        ]);

        $this->followingRedirects()->post('/portal/consultar', ['cpf' => '123.456.789-09', 'data_nascimento' => '15/03/2000']);
        $response = $this->get(route('portal.resultados.avaliacao', ['avaliacao' => $avaliacao->codigo, 'periodo' => '2º']));

        $response->assertOk();

        // O que saiu.
        $response->assertDontSee('Comparativo com a turma');
        $response->assertDontSee('Posição relativa');
        $response->assertDontSee('Sua resposta x turma, por questão');
        $response->assertDontSee('grafico-comparativo-turma', false);

        // Sem o gráfico de Bloom; no lugar, a leitura da prova em palavras simples.
        $response->assertDontSee('Desempenho por nível de Bloom');
        $response->assertDontSee('grafico-bloom', false);
        $response->assertSee('Como você foi nesta prova');
        $response->assertSee('Seu resultado nesta prova ficou abaixo do esperado', false);
        $response->assertSee('Áreas que merecem mais atenção nos estudos', false);

        // Desempenho por área: barras com meta, não mais o radar.
        $response->assertSee('Desempenho por área');
        $response->assertSee('Viz.barrasComMinimo(document.getElementById(\'grafico-area\')', false);
        $response->assertSee('meta da área');

        // Trilha e lacunas dizem o que ficou de fora.
        $response->assertSee('Trilha de estudo');
        $response->assertSee('1 de períodos à frente ficam de fora', false);
        $response->assertSee('1 questão(ões) de períodos à frente ficam de fora', false);
    }
}
