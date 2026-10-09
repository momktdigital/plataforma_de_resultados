<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\CoordenadorAlunosService;
use App\Services\CoordenadorDashboardService;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Visão geral do coordenador: faixa verde sem "em atenção", sem a lista/quadro de situação, coluna "dentro do esperado"
 * nas avaliações recentes (meta `questoes.periodo_minimo`) e % de acerto por período do curso.
 */
class CoordenadorVisaoGeralTest extends TestCase
{
    use RefreshDatabase;

    private function coordenador(): Admin
    {
        $coordenador = Admin::create(['username' => 'coord', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_COORDENADOR]);
        $coordenador->sincronizarCursos(['MEDICINA']);

        return $coordenador;
    }

    private function aluno(string $periodo): Aluno
    {
        static $ra = 9000;
        $ra++;

        return Aluno::create(['ra' => (string) $ra, 'nome' => 'Aluno '.$ra, 'curso' => 'MEDICINA', 'periodo' => $periodo]);
    }

    /**
     * Avaliação de 4 questões (gabarito A). `$minimos` = período mínimo de cada questão (null = sem meta).
     * Cada resultado é [Aluno, quantas questões acertou, de 0 a 4].
     *
     * @param  array<int, ?int>  $minimos
     * @param  array<int, array{0: Aluno, 1: int}>  $resultados
     */
    private function avaliacao(string $nome, string $data, array $minimos, array $resultados): Avaliacao
    {
        $avaliacao = Avaliacao::create(['nome' => $nome, 'data_avaliacao' => $data]);
        foreach ($minimos as $i => $minimo) {
            Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => $i + 1, 'gabarito' => 'A', 'area' => 'Clínica', 'periodo_minimo' => $minimo]);
        }

        foreach ($resultados as [$aluno, $acertos]) {
            foreach (array_keys($minimos) as $i) {
                Resposta::create([
                    'avaliacao_codigo' => $avaliacao->codigo, 'aluno_id' => $aluno->id, 'ra' => $aluno->ra, 'periodo' => $aluno->periodo,
                    'questao_numero' => $i + 1, 'resposta' => $i < $acertos ? 'A' : 'B',
                ]);
            }
        }
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        return $avaliacao;
    }

    public function test_faixa_verde_sem_em_atencao_e_sem_a_lista_e_o_quadro_de_situacao(): void
    {
        $this->avaliacao('Prova', '2026-03-10', [null, null, null, null], [[$this->aluno('3º'), 4], [$this->aluno('3º'), 0]]);

        $resposta = $this->actingAs($this->coordenador(), 'admin')->get('/painel?periodo_letivo=2026/1')->assertOk();

        $resposta->assertDontSee('Alunos que precisam de atenção');
        $resposta->assertDontSee('Situação dos alunos');
        $resposta->assertDontSee('Em atenção</div>', false); // o chip da faixa verde
        $resposta->assertViewMissing('emAtencao');
    }

    public function test_alunos_dentro_do_esperado_contam_pelo_periodo_de_cada_aluno(): void
    {
        $segundo = $this->aluno('2º');
        $terceiro = $this->aluno('3º');
        $quarto = $this->aluno('4º');
        // Questões: 1º, 2º, 3º e 4º período. Mínimo esperado: 2º → 50% (2 de 4), 3º → 75%, 4º → 100%.
        $avaliacao = $this->avaliacao('Diagnóstico', '2026-03-10', [1, 2, 3, 4], [
            [$segundo, 2], // 50% ≥ 50% → dentro
            [$terceiro, 2], // 50% < 75% → fora
            [$quarto, 4],   // 100% ≥ 100% → dentro
        ]);

        $esperado = app(CoordenadorDashboardService::class)->alunosDentroDoEsperado(['MEDICINA'], [$avaliacao->codigo]);

        $this->assertTrue($esperado[$avaliacao->codigo]['comMeta']);
        $this->assertSame(3, $esperado[$avaliacao->codigo]['presentes']);
        $this->assertSame(2, $esperado[$avaliacao->codigo]['dentro']);
        $this->assertSame(66.7, $esperado[$avaliacao->codigo]['pct']);
    }

    public function test_avaliacao_sem_meta_mostra_so_a_media_no_lugar(): void
    {
        $avaliacao = $this->avaliacao('Sem meta', '2026-03-10', [null, null, null, null], [[$this->aluno('3º'), 4], [$this->aluno('3º'), 2]]);

        $esperado = app(CoordenadorDashboardService::class)->alunosDentroDoEsperado(['MEDICINA'], [$avaliacao->codigo]);
        $this->assertFalse($esperado[$avaliacao->codigo]['comMeta']);
        $this->assertNull($esperado[$avaliacao->codigo]['pct']);

        $resposta = $this->actingAs($this->coordenador(), 'admin')->get('/painel?periodo_letivo=2026/1')->assertOk();
        $resposta->assertSee('Dentro do esperado');
        $resposta->assertSee('75,0% <span class="text-xs text-slate-500">(média)</span>', false);
    }

    public function test_coluna_dentro_do_esperado_mostra_quantidade_e_percentual(): void
    {
        $this->avaliacao('Com meta', '2026-03-10', [1, 2, 3, 4], [[$this->aluno('2º'), 2], [$this->aluno('2º'), 1], [$this->aluno('2º'), 1], [$this->aluno('2º'), 4]]);

        $resposta = $this->actingAs($this->coordenador(), 'admin')->get('/painel?periodo_letivo=2026/1')->assertOk();

        // 2º período → mínimo 50%: acertos 2 e 4 passam (50% e 100%), 1 acerto (25%) não → 2 de 4 = 50%.
        $resposta->assertSee('Dentro do esperado');
        $resposta->assertSee('<span class="text-xs text-slate-500">(50%)</span>', false);
        $resposta->assertSee('2 de 4 alunos presentes alcançaram o mínimo esperado', false);
    }

    public function test_ausente_nao_entra_na_conta_do_esperado(): void
    {
        $avaliacao = $this->avaliacao('Com meta', '2026-03-10', [1, 2, 3, 4], [[$this->aluno('2º'), 4], [$this->aluno('2º'), 0]]);
        // O segundo aluno deixou a prova toda em branco: ausente, fora da conta.
        Resposta::where('avaliacao_codigo', $avaliacao->codigo)->where('resposta', 'B')->update(['resposta' => '']);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $esperado = app(CoordenadorDashboardService::class)->alunosDentroDoEsperado(['MEDICINA'], [$avaliacao->codigo]);

        $this->assertSame(1, $esperado[$avaliacao->codigo]['presentes']);
        $this->assertSame(1, $esperado[$avaliacao->codigo]['dentro']);
    }

    public function test_alunos_por_periodo_traz_o_percentual_de_acerto(): void
    {
        $this->avaliacao('Prova', '2026-03-10', [null, null, null, null], [
            [$this->aluno('2º'), 4], [$this->aluno('2º'), 2], // média do 2º: (100 + 50) / 2 = 75%
            [$this->aluno('5º'), 1],                          // 25%
        ]);

        $coordenador = $this->coordenador();
        $escopo = app(CoordenadorDashboardService::class)->escopo($coordenador, '', '2026/1');
        $resumo = app(CoordenadorAlunosService::class)->resumo(app(CoordenadorAlunosService::class)->alunos($escopo));

        $porPeriodo = collect($resumo['porPeriodoCurso'])->keyBy('ordinal');
        $this->assertEquals(75.0, $porPeriodo[2]['media']);
        $this->assertEquals(25.0, $porPeriodo[5]['media']);
        $this->assertArrayNotHasKey('soma_media', $porPeriodo[2]);

        $this->actingAs($coordenador, 'admin')->get('/painel?periodo_letivo=2026/1')
            ->assertOk()
            ->assertSee('% de acerto')
            ->assertSee('75,0%')
            ->assertSee('25,0%');
    }
}
