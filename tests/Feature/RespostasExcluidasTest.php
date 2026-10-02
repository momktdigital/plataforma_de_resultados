<?php

namespace Tests\Feature;

use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\Portal\AnaliseConsolidadaService;
use App\Services\Portal\RelatorioAlunoService;
use App\Services\RelatorioAdminService;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regressão: respostas apagadas (soft delete — "Excluir resultados do período") não podem entrar em
 * NENHUM visual. Cada consulta crua em `respostas` precisa do `deleted_at IS NULL`; sem ele, a tela do
 * resumo mostrava uma turma e os gráficos mostravam outra (as respostas apagadas seguiam somando).
 */
class RespostasExcluidasTest extends TestCase
{
    use RefreshDatabase;

    private Avaliacao $avaliacao;

    private Aluno $presente;

    private Aluno $apagado;

    protected function setUp(): void
    {
        parent::setUp();

        $this->avaliacao = Avaliacao::create(['nome' => 'Prova', 'data_avaliacao' => '2026-03-10']);

        $tri = [-1.5, -0.5, 0.0, 0.5, 1.0, 1.5];
        $dificuldade = ['facil', 'facil', 'medio', 'medio', 'dificil', 'dificil'];
        foreach ($tri as $i => $valor) {
            Questao::create([
                'avaliacao_codigo' => $this->avaliacao->codigo,
                'numero' => $i + 1,
                'gabarito' => 'A',
                'area' => $i % 2 === 0 ? 'Clínica' : 'Cirurgia',
                'tema' => $i % 2 === 0 ? 'Cardiologia' : 'Trauma',
                'habilidade' => 'H'.($i % 3),
                'bloom_nivel' => 'Aplicar',
                'miller_nivel' => 'Mostra',
                'dificuldade_pedagogica' => $dificuldade[$i],
                'dificuldade_tri' => $valor,
            ]);
        }

        $this->presente = Aluno::create(['ra' => '1001', 'nome' => 'Presente', 'curso' => 'MEDICINA', 'periodo' => '3º']);
        $this->apagado = Aluno::create(['ra' => '1002', 'nome' => 'Apagado', 'curso' => 'MEDICINA', 'periodo' => '3º']);

        // O aluno que fica acerta as 4 primeiras; o que será apagado erra todas.
        $this->responder($this->presente, ['A', 'A', 'A', 'A', 'X', 'X']);
    }

    /** @param  array<int, string>  $respostas */
    private function responder(Aluno $aluno, array $respostas): void
    {
        foreach ($respostas as $i => $resposta) {
            Resposta::create([
                'avaliacao_codigo' => $this->avaliacao->codigo,
                'aluno_id' => $aluno->id,
                'ra' => $aluno->ra,
                'periodo' => '3º',
                'questao_numero' => $i + 1,
                'resposta' => $resposta,
            ]);
        }
    }

    /** @return array<string, mixed> tudo que lê `respostas` com consulta crua */
    private function visuais(): array
    {
        app(ResumoResultadoService::class)->recalcular($this->avaliacao->codigo);

        $admin = new RelatorioAdminService;
        $portal = app(AnaliseConsolidadaService::class);
        $codigos = [$this->avaliacao->codigo];

        $gabaritos = Questao::where('avaliacao_codigo', $this->avaliacao->codigo)->pluck('gabarito', 'numero');
        $doAluno = Resposta::where('avaliacao_codigo', $this->avaliacao->codigo)->where('ra', $this->presente->ra)->get();

        return [
            'admin.curvaDificuldade' => $admin->curvaDificuldade($this->avaliacao),
            'admin.mediaPorBloom' => $admin->mediaPorBloom($this->avaliacao),
            'admin.mediaPorMiller' => $admin->mediaPorMiller($this->avaliacao),
            'admin.desempenhoPorTema' => $admin->desempenhoPorTema($this->avaliacao),
            'admin.heatmapHabilidadeTurma' => $admin->heatmapHabilidadeTurma($this->avaliacao),
            'admin.dispersaoTri' => $admin->dispersaoTri($this->avaliacao),
            'portal.curvaDificuldade' => $portal->curvaDificuldadePedagogica($this->presente, $codigos),
            'portal.dispersaoTri' => $portal->dispersaoTri($this->presente, $codigos),
            'portal.areasDivergentes' => $portal->areasDivergentesDaTurma($this->presente, $codigos, 0.0),
            'portal.mapaDominio' => $portal->mapaDominio($this->presente, $codigos),
            'portal.comparativoQuestao' => app(RelatorioAlunoService::class)->comparativoQuestao($this->avaliacao, '3º', $doAluno, $gabaritos),
        ];
    }

    public function test_respostas_apagadas_nao_entram_em_nenhum_visual(): void
    {
        $semOApagado = $this->visuais();

        $this->responder($this->apagado, ['X', 'X', 'X', 'X', 'X', 'X']);
        $comOApagado = $this->visuais();

        // Sanidade do teste: com o aluno que erra tudo presente, os números MUDAM — senão a comparação
        // abaixo não provaria nada. (Só os visuais que dependem de turma mudam.)
        $mudaram = array_keys(array_filter(
            $semOApagado,
            fn ($valor, $chave) => $valor != $comOApagado[$chave],
            ARRAY_FILTER_USE_BOTH,
        ));
        $this->assertContains('admin.curvaDificuldade', $mudaram);
        $this->assertContains('admin.desempenhoPorTema', $mudaram);
        $this->assertContains('portal.comparativoQuestao', $mudaram);

        // Apaga as respostas do período inteiro desse aluno (soft delete) e volta a olhar tudo.
        Resposta::where('ra', $this->apagado->ra)->delete();
        $depoisDeApagar = $this->visuais();

        foreach ($semOApagado as $visual => $esperado) {
            $this->assertEquals($esperado, $depoisDeApagar[$visual], "O visual {$visual} ainda conta respostas apagadas.");
        }
    }
}
