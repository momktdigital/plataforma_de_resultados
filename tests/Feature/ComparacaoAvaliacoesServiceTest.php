<?php

namespace Tests\Feature;

use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\ComparacaoAvaliacoesService;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComparacaoAvaliacoesServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ComparacaoAvaliacoesService
    {
        return app(ComparacaoAvaliacoesService::class);
    }

    /** Cria uma avaliação de 1 questão de área $area, com $n respondentes acertando $acertos deles. */
    private function avaliacao(string $nome, string $area, int $n, int $acertos): Avaliacao
    {
        static $ra = 0;

        $avaliacao = Avaliacao::create(['nome' => $nome]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A', 'area' => $area]);

        for ($i = 0; $i < $n; $i++) {
            $ra++;
            Aluno::create(['ra' => (string) $ra, 'nome' => 'Aluno '.$ra]);
            Resposta::create([
                'avaliacao_codigo' => $avaliacao->codigo,
                'ra' => (string) $ra,
                'periodo' => '',
                'questao_numero' => 1,
                'resposta' => $i < $acertos ? 'A' : 'B',
            ]);
        }

        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        return $avaliacao;
    }

    public function test_opcoes_disponiveis_exclui_a_propria_avaliacao_e_as_sem_resultado(): void
    {
        $atual = $this->avaliacao('Atual', 'Clínica Médica', 3, 2);
        $outra = $this->avaliacao('Outra com resultado', 'Clínica Médica', 3, 2);
        $semResultado = Avaliacao::create(['nome' => 'Sem resultado']);

        $opcoes = $this->service()->opcoesDisponiveis($atual);

        $codigos = $opcoes->pluck('codigo')->all();
        $this->assertContains($outra->codigo, $codigos);
        $this->assertNotContains($atual->codigo, $codigos);
        $this->assertNotContains($semResultado->codigo, $codigos);
    }

    public function test_comparar_retorna_null_sem_nenhum_codigo_valido(): void
    {
        $atual = $this->avaliacao('Atual', 'Clínica Médica', 3, 2);

        $this->assertNull($this->service()->comparar($atual, []));
        // O próprio código da avaliação base não conta como comparação.
        $this->assertNull($this->service()->comparar($atual, [$atual->codigo]));
        // Código de avaliação inexistente também não sustenta uma comparação.
        $this->assertNull($this->service()->comparar($atual, [99999]));
    }

    public function test_comparar_traz_a_base_primeiro_e_preserva_a_ordem_escolhida(): void
    {
        $base = $this->avaliacao('Base', 'Clínica Médica', 12, 9);
        $segunda = $this->avaliacao('Segunda', 'Clínica Médica', 12, 6);
        $terceira = $this->avaliacao('Terceira', 'Clínica Médica', 12, 3);

        $resultado = $this->service()->comparar($base, [$terceira->codigo, $segunda->codigo]);

        $nomes = array_column($resultado['avaliacoes'], 'nome');
        $this->assertSame(['Base', 'Terceira', 'Segunda'], $nomes);
    }

    /**
     * Paleta categórica validada só tem 3 tons — MAX_COMPARACOES trava em 2
     * comparadas (+ a base = 3 séries), então uma 4ª escolhida é descartada.
     */
    public function test_comparar_trava_no_maximo_de_avaliacoes_comparadas(): void
    {
        $base = $this->avaliacao('Base', 'Clínica Médica', 12, 9);
        $outras = [
            $this->avaliacao('A', 'Clínica Médica', 12, 8),
            $this->avaliacao('B', 'Clínica Médica', 12, 7),
            $this->avaliacao('C', 'Clínica Médica', 12, 6),
        ];

        $resultado = $this->service()->comparar($base, array_map(fn ($a) => $a->codigo, $outras));

        $this->assertCount(1 + ComparacaoAvaliacoesService::MAX_COMPARACOES, $resultado['avaliacoes']);
        $this->assertSame(['Base', 'A', 'B'], array_column($resultado['avaliacoes'], 'nome'));
    }

    public function test_comparar_traz_media_por_area_e_omite_resumo_com_poucos_respondentes(): void
    {
        $base = $this->avaliacao('Base', 'Clínica Médica', 3, 2); // abaixo do mínimo de 10 p/ psicometria
        $outra = $this->avaliacao('Outra', 'Clínica Médica', 12, 6);

        $resultado = $this->service()->comparar($base, [$outra->codigo]);

        $this->assertNull($resultado['avaliacoes'][0]['resumo']);
        $this->assertNotNull($resultado['avaliacoes'][1]['resumo']);
        $this->assertSame(50.0, $resultado['avaliacoes'][1]['resumo']['media']);

        // mediaPorArea não depende do mínimo de respondentes da psicometria.
        $this->assertArrayHasKey('Clínica Médica', $resultado['avaliacoes'][0]['mediaPorArea']);
        $this->assertSame(['Clínica Médica'], $resultado['areas']);
    }
}
