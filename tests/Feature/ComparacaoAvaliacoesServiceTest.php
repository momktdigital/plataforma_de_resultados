<?php

namespace Tests\Feature;

use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Categoria;
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

    /**
     * Cria uma avaliação de 1 questão de área $area, com $n respondentes
     * acertando $acertos deles. Por padrão todas caem na mesma categoria
     * (comparação só vale dentro da categoria).
     */
    private function avaliacao(string $nome, string $area, int $n, int $acertos, string $categoria = 'Padrão'): Avaliacao
    {
        static $ra = 0;

        $avaliacao = Avaliacao::create(['nome' => $nome, 'categoria_id' => Categoria::firstOrCreate(['nome' => $categoria])->id]);
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
        $semResultado = Avaliacao::create(['nome' => 'Sem resultado', 'categoria_id' => $atual->categoria_id]);

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

    public function test_so_compara_com_avaliacoes_da_mesma_categoria(): void
    {
        $atual = $this->avaliacao('Atual', 'Clínica Médica', 12, 6, 'Simulados');
        $mesma = $this->avaliacao('Mesma categoria', 'Clínica Médica', 12, 6, 'Simulados');
        $outra = $this->avaliacao('Outra categoria', 'Clínica Médica', 12, 6, 'Disciplinas');

        $codigos = $this->service()->opcoesDisponiveis($atual)->pluck('codigo')->all();
        $this->assertContains($mesma->codigo, $codigos);
        $this->assertNotContains($outra->codigo, $codigos);

        // E mesmo forçando o código de outra categoria pela URL, ela é descartada.
        $this->assertNull($this->service()->comparar($atual, [$outra->codigo]));
        $resultado = $this->service()->comparar($atual, [$outra->codigo, $mesma->codigo]);
        $this->assertSame(['Atual', 'Mesma categoria'], array_column($resultado['avaliacoes'], 'nome'));
    }

    public function test_avaliacao_sem_categoria_nao_tem_com_quem_comparar(): void
    {
        $semCategoria = Avaliacao::create(['nome' => 'Sem categoria']);
        $outra = $this->avaliacao('Outra', 'Clínica Médica', 12, 6);

        $this->assertTrue($this->service()->opcoesDisponiveis($semCategoria)->isEmpty());
        $this->assertNull($this->service()->comparar($semCategoria, [$outra->codigo]));
    }
}
