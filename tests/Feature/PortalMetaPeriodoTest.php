<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\Portal\AnaliseConsolidadaService;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Meta por período (`questoes.periodo_minimo`) no boletim do aluno: o acerto dele x o que se espera para o período
 * em que ele estava na prova, e a "Leitura rápida" escrita embaixo do gráfico.
 */
class PortalMetaPeriodoTest extends TestCase
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
     * @param  array<int, array{0: string, 1: ?int, 2: string}>  $questoes  [área, período mínimo, resposta do aluno] — gabarito é sempre "A"
     */
    private function prova(Aluno $aluno, string $periodoDoAluno, array $questoes, ?int $categoriaId = null, string $data = '2026-03-01'): Avaliacao
    {
        $avaliacao = Avaliacao::create(['nome' => 'Prova '.$data, 'categoria_id' => $categoriaId, 'data_avaliacao' => $data]);

        foreach ($questoes as $i => [$area, $minimo, $resposta]) {
            $numero = $i + 1;
            Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => $numero, 'gabarito' => 'A', 'area' => $area, 'periodo_minimo' => $minimo]);
            Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => $aluno->ra, 'periodo' => $periodoDoAluno, 'questao_numero' => $numero, 'resposta' => $resposta]);
        }
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        return $avaliacao;
    }

    public function test_esperado_e_a_fatia_da_prova_que_ja_cabe_no_periodo_do_aluno(): void
    {
        $aluno = $this->aluno();
        $avaliacao = $this->prova($aluno, '2º', [
            ['Cardiologia', 1, 'A'],    // esperada, acertou
            ['Cardiologia', 3, 'B'],    // à frente (3º em diante), errou — não pesa
            ['Pneumologia', null, 'A'], // sem meta: vale para todos, acertou
            ['Pneumologia', 2, 'B'],    // esperada (2º em diante), errou
        ]);

        $meta = app(AnaliseConsolidadaService::class)->metaPorPeriodo($aluno, [$avaliacao->codigo]);

        $this->assertTrue($meta['comMeta']);
        $this->assertSame(2, $meta['periodoAluno']);
        // 2 acertos em 4 = 50%; esperadas 3 de 4 (a do 3º período fica de fora) = 75%.
        $this->assertSame(50.0, $meta['geral']['percentual']);
        $this->assertSame(75.0, $meta['geral']['esperado']);
        $this->assertSame(['total' => 1, 'acertos' => 0], $meta['adiante']);

        // O gráfico usa o acerto total e o mínimo esperado da avaliação (a fatia que já cabe no período do aluno).
        $this->assertCount(1, $meta['avaliacoes']);
        $this->assertSame(50.0, $meta['avaliacoes'][0]['percentual']);
        $this->assertSame(75.0, $meta['avaliacoes'][0]['minimo']);
        $this->assertTrue($meta['avaliacoes'][0]['comMeta']);

        $porArea = collect($meta['areas'])->keyBy('area');
        $this->assertSame(50.0, $porArea['Cardiologia']['percentual']);
        $this->assertSame(50.0, $porArea['Cardiologia']['esperado']);
        $this->assertSame(50.0, $porArea['Pneumologia']['percentual']);
        $this->assertSame(100.0, $porArea['Pneumologia']['esperado']);
        // O mais distante do esperado vem primeiro.
        $this->assertSame('Pneumologia', $meta['areas'][0]['area']);
    }

    public function test_aluno_de_periodo_maior_e_cobrado_em_toda_a_prova(): void
    {
        $aluno = $this->aluno();
        $avaliacao = $this->prova($aluno, '7° PERÍODO DE MEDICINA - 3377 T/A', [
            ['Cardiologia', 1, 'A'],
            ['Cardiologia', 6, 'B'],
        ]);

        $meta = app(AnaliseConsolidadaService::class)->metaPorPeriodo($aluno, [$avaliacao->codigo]);

        $this->assertSame(7, $meta['periodoAluno']);
        $this->assertSame(100.0, $meta['geral']['esperado']);
        $this->assertSame(0, $meta['adiante']['total']);
    }

    public function test_sem_meta_nas_questoes_ou_sem_periodo_conhecido_nao_ha_linha_de_esperado(): void
    {
        $aluno = $this->aluno();
        $semMeta = $this->prova($aluno, '2º', [['Cardiologia', null, 'A'], ['Cardiologia', null, 'B']]);
        $semPeriodo = $this->prova($aluno, '', [['Pneumologia', 3, 'A'], ['Pneumologia', 5, 'B']], null, '2026-04-01');

        $servico = app(AnaliseConsolidadaService::class);

        $a = $servico->metaPorPeriodo($aluno, [$semMeta->codigo]);
        $this->assertFalse($a['comMeta']);
        $this->assertNull($a['geral']['esperado']);
        $this->assertSame(60.0, $a['avaliacoes'][0]['minimo']);
        $this->assertFalse($a['avaliacoes'][0]['comMeta']);
        $this->assertSame(50.0, $a['geral']['percentual']);

        $b = $servico->metaPorPeriodo($aluno, [$semPeriodo->codigo]);
        $this->assertFalse($b['comMeta']);
        $this->assertNull($b['periodoAluno']);
        $this->assertNull($b['geral']['esperado']);
        $this->assertSame(60.0, $b['avaliacoes'][0]['minimo']);
    }

    public function test_cada_avaliacao_da_categoria_tem_o_proprio_minimo(): void
    {
        $aluno = $this->aluno();
        $comMeta = $this->prova($aluno, '2º', [['Cardiologia', 1, 'A'], ['Cardiologia', 5, 'B']], null, '2026-03-01');
        $semMeta = $this->prova($aluno, '2º', [['Cardiologia', null, 'A'], ['Cardiologia', null, 'A']], null, '2026-04-01');

        $meta = app(AnaliseConsolidadaService::class)->metaPorPeriodo($aluno, [$semMeta->codigo, $comMeta->codigo]);

        // Em ordem cronológica: primeiro a prova de março (com meta: 1 de 2 esperadas = 50%), depois a de abril (padrão 60%).
        $this->assertSame([50.0, 60.0], array_column($meta['avaliacoes'], 'minimo'));
        $this->assertSame([50.0, 100.0], array_column($meta['avaliacoes'], 'percentual'));
    }

    public function test_questao_anulada_distribuindo_pontuacao_nao_entra_na_conta(): void
    {
        $aluno = $this->aluno();
        $avaliacao = $this->prova($aluno, '2º', [['Cardiologia', 1, 'A'], ['Cardiologia', 1, 'B']]);
        Questao::where('avaliacao_codigo', $avaliacao->codigo)->where('numero', 2)->update(['anulada_modo' => 'distribuir_pontuacao']);

        $meta = app(AnaliseConsolidadaService::class)->metaPorPeriodo($aluno, [$avaliacao->codigo]);

        $this->assertSame(1, $meta['geral']['total']);
        $this->assertSame(100.0, $meta['geral']['percentual']);
    }

    public function test_sem_respostas_do_aluno_nao_ha_meta(): void
    {
        $aluno = $this->aluno();
        $this->assertNull(app(AnaliseConsolidadaService::class)->metaPorPeriodo($aluno, []));

        $outra = Avaliacao::create(['nome' => 'Vazia']);
        $this->assertNull(app(AnaliseConsolidadaService::class)->metaPorPeriodo($aluno, [$outra->codigo]));
    }

    public function test_mapa_de_dominio_traz_o_minimo_esperado_de_cada_celula(): void
    {
        $aluno = $this->aluno();
        // Prova com meta: aluno do 2º período. Cardiologia: 1 esperada (acertou) + 1 do 4º período (errou, não pesa) → mínimo 50% (1 de 2), acerto 50%.
        // Pneumologia: sem meta nas questões da célula → mínimo padrão de 60%.
        $comMeta = $this->prova($aluno, '2º', [['Cardiologia', 1, 'A'], ['Cardiologia', 4, 'B'], ['Pneumologia', null, 'A'], ['Pneumologia', null, 'B']]);
        // Prova sem nenhuma meta: tudo cai no padrão.
        $semMeta = $this->prova($aluno, '2º', [['Cardiologia', null, 'A']], null, '2026-04-01');

        $mapa = app(AnaliseConsolidadaService::class)->mapaDominio($aluno, [$comMeta->codigo, $semMeta->codigo]);
        $areas = collect($mapa['areas'])->keyBy('area');

        $this->assertSame(50.0, $areas['Cardiologia']['valores'][$comMeta->codigo]);
        $this->assertSame(50.0, $areas['Cardiologia']['esperados'][$comMeta->codigo]);
        $this->assertSame(50.0, $areas['Pneumologia']['valores'][$comMeta->codigo]);
        $this->assertSame(60.0, $areas['Pneumologia']['esperados'][$comMeta->codigo]);
        $this->assertSame(60.0, $areas['Cardiologia']['esperados'][$semMeta->codigo]);
    }

    public function test_bloom_com_contagem_cai_para_o_verbo_quando_nao_ha_nivel(): void
    {
        $aluno = $this->aluno();
        $avaliacao = Avaliacao::create(['nome' => 'Bloom']);
        foreach ([1 => 'A', 2 => 'B'] as $numero => $resposta) {
            Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => $numero, 'gabarito' => 'A', 'bloom_verbo' => 'Aplicar']);
            Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => $aluno->ra, 'periodo' => '', 'questao_numero' => $numero, 'resposta' => $resposta]);
        }

        $this->assertSame(
            ['Aplicar' => ['percentual' => 50.0, 'total' => 2]],
            app(AnaliseConsolidadaService::class)->bloomComContagem($aluno, [$avaliacao->codigo]),
        );
    }

    public function test_boletim_mostra_so_o_numero_de_avaliacoes_no_card_verde_e_o_novo_grafico_com_leitura_rapida(): void
    {
        $aluno = $this->aluno();
        $categoria = Categoria::create(['nome' => 'Simulados']);
        $this->prova($aluno, '2º', [['Cardiologia', 1, 'A'], ['Cardiologia', 4, 'B'], ['Pneumologia', 1, 'B']], $categoria->id);

        $this->followingRedirects()->post('/portal/consultar', ['cpf' => '123.456.789-09', 'data_nascimento' => '15/03/2000']);
        $response = $this->get(route('portal.resultados'));

        $response->assertOk();
        $response->assertDontSee('Média geral');
        $response->assertSee('Avaliação');
        $response->assertDontSee('Avaliações');
        $response->assertSee('Seu rendimento x mínimo esperado');
        $response->assertDontSee('Rendimento médio nesta categoria');
        $response->assertDontSee('Desempenho por categoria');
        $response->assertSee('Leitura rápida');
        $response->assertSee('abaixo do mínimo esperado (', false);
        $response->assertDontSee('Áreas onde você mais diverge da turma');
        $response->assertSee('grafico-meta-'.$categoria->id, false);
        // Os três gráficos que saíram.
        $response->assertDontSee('Você x turma');
        $response->assertDontSee('Dificuldade pedagógica');
        $response->assertDontSee('Nível de Bloom');
        $response->assertDontSee('grafico-turma-', false);
        $response->assertDontSee('grafico-dificuldade-', false);
        $response->assertDontSee('grafico-bloom-', false);
    }
}
