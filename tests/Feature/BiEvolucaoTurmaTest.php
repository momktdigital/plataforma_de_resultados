<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\RelatorioAdminService;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Evolução da média na categoria": aba por PERÍODO do curso (uma linha por
 * turma) e aba da avaliação inteira, ambas com a opção de desconsiderar
 * ausentes. E o percentual da lista nominal segue a regra de cor do resto do
 * sistema: abaixo de 60% é amarelo.
 */
class BiEvolucaoTurmaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'admin', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_ADMIN]);
    }

    /**
     * Avaliação de 5 questões (gabarito A) na categoria.
     * $alunos = [RA => [período, turma, acertos, curso]]; acertos null = prova toda em branco (ausente).
     *
     * @param  array<string, array{0: string, 1: string, 2: ?int, 3?: string}>  $alunos
     */
    private function avaliacao(string $nome, string $data, int $categoriaId, array $alunos): Avaliacao
    {
        $avaliacao = Avaliacao::create(['nome' => $nome, 'data_avaliacao' => $data, 'categoria_id' => $categoriaId]);
        for ($n = 1; $n <= 5; $n++) {
            Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => $n, 'gabarito' => 'A']);
        }

        foreach ($alunos as $ra => $dados) {
            [$periodo, $turma, $acertos] = $dados;
            $aluno = Aluno::firstOrCreate(['ra' => (string) $ra], [
                'nome' => "Aluno {$ra}", 'curso' => $dados[3] ?? 'DIREITO', 'periodo' => $periodo, 'turma' => $turma,
            ]);
            for ($n = 1; $n <= 5; $n++) {
                Resposta::create([
                    'avaliacao_codigo' => $avaliacao->codigo,
                    'aluno_id' => $aluno->id,
                    'ra' => (string) $ra,
                    'periodo' => '',
                    'questao_numero' => $n,
                    'resposta' => $acertos === null ? '' : ($n <= $acertos ? 'A' : 'B'),
                ]);
            }
        }
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        return $avaliacao;
    }

    /**
     * 2º período: turma A (alunos 1 e 2) e turma B (aluno 3); 3º período: turma C (aluno 4).
     * Na segunda prova o aluno 2 falta (ausente).
     *
     * @return array{0: Avaliacao, 1: Avaliacao}
     */
    private function categoriaComDuasProvas(): array
    {
        $categoria = Categoria::create(['nome' => 'Simulados']);

        $primeira = $this->avaliacao('Primeira', '2026-03-10', $categoria->id, [
            '1' => ['2º', 'Turma A', 5], '2' => ['2º', 'Turma A', 3], '3' => ['2º', 'Turma B', 1], '4' => ['3º', 'Turma C', 4],
        ]);
        $segunda = $this->avaliacao('Segunda', '2026-04-10', $categoria->id, [
            '1' => ['2º', 'Turma A', 4], '2' => ['2º', 'Turma A', null], '3' => ['2º', 'Turma B', 2], '4' => ['3º', 'Turma C', 5],
        ]);

        return [$primeira, $segunda];
    }

    public function test_evolucao_da_avaliacao_inteira_traz_a_media_com_e_sem_ausentes(): void
    {
        [, $segunda] = $this->categoriaComDuasProvas();

        $pontos = app(RelatorioAdminService::class)->evolucaoCategoria($segunda);

        $this->assertSame(['Primeira', 'Segunda'], array_column($pontos, 'nome'));
        // 1ª: (100+60+20+80)/4 = 65, sem ausentes igual. 2ª: (80+0+40+100)/4 = 55 com o ausente; (80+40+100)/3 = 73,3 sem ele.
        $this->assertSame([65.0, 55.0], array_column($pontos, 'media'));
        $this->assertSame([65.0, 73.3], array_column($pontos, 'mediaPresentes'));
        $this->assertSame([4, 4], array_column($pontos, 'respondentes'));
        $this->assertSame([4, 3], array_column($pontos, 'presentes'));
    }

    public function test_evolucao_por_periodo_traz_uma_serie_por_turma_do_periodo(): void
    {
        [, $segunda] = $this->categoriaComDuasProvas();

        $porPeriodo = app(RelatorioAdminService::class)->evolucaoCategoriaPorPeriodo($segunda);

        $this->assertSame([2, 3], array_keys($porPeriodo));
        $this->assertSame('2º período', $porPeriodo[2]['rotulo']);
        $this->assertSame(['Turma A', 'Turma B'], array_keys($porPeriodo[2]['turmas']));
        $this->assertSame(['Turma C'], array_keys($porPeriodo[3]['turmas']));

        $turmaA = $porPeriodo[2]['turmas']['Turma A'];
        $this->assertSame([80.0, 40.0], array_column($turmaA, 'media'), 'com ausentes: (100+60)/2 e (80+0)/2');
        $this->assertSame([80.0, 80.0], array_column($turmaA, 'mediaPresentes'), 'sem o ausente da 2ª prova');
        $this->assertSame([2, 1], array_column($turmaA, 'presentes'));
        $this->assertSame([20.0, 40.0], array_column($porPeriodo[2]['turmas']['Turma B'], 'media'));
    }

    public function test_periodo_que_nao_e_do_curso_fica_de_fora(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulados']);
        $this->avaliacao('Primeira', '2026-03-10', $categoria->id, ['1' => ['2026/1', 'Turma X', 5], '2' => ['2º', 'Turma A', 5]]);
        $segunda = $this->avaliacao('Segunda', '2026-04-10', $categoria->id, ['1' => ['2026/1', 'Turma X', 5], '2' => ['2º', 'Turma A', 5]]);

        $this->assertSame([2], array_keys(app(RelatorioAdminService::class)->evolucaoCategoriaPorPeriodo($segunda)));
    }

    public function test_evolucao_respeita_o_escopo_do_curso_do_coordenador(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulados']);
        $primeira = $this->avaliacao('Primeira', '2026-03-10', $categoria->id, [
            '1' => ['2º', 'Turma A', 5, 'DIREITO'], '2' => ['2º', 'Turma A', 0, 'MEDICINA'],
        ]);
        $this->avaliacao('Segunda', '2026-04-10', $categoria->id, [
            '1' => ['2º', 'Turma A', 5, 'DIREITO'], '2' => ['2º', 'Turma A', 0, 'MEDICINA'],
        ]);

        $servico = app(RelatorioAdminService::class);
        $this->assertSame([50.0, 50.0], array_column($servico->evolucaoCategoriaPorPeriodo($primeira)[2]['turmas']['Turma A'], 'media'), 'administrador vê as duas turmas juntas');

        $doCoordenador = $servico->paraCursos(['DIREITO']);
        $this->assertSame([100.0, 100.0], array_column($doCoordenador->evolucaoCategoriaPorPeriodo($primeira)[2]['turmas']['Turma A'], 'media'));
        $this->assertSame([100.0, 100.0], array_column($doCoordenador->evolucaoCategoria($primeira), 'media'));
    }

    public function test_bi_mostra_as_abas_periodo_e_avaliacao_inteira_e_a_caixa_de_ausentes(): void
    {
        [, $segunda] = $this->categoriaComDuasProvas();

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$segunda->codigo}/bi?turma=Turma C");

        $response->assertOk()
            ->assertSee('Evolução da média na categoria')
            ->assertSee('id="aba-evolucao-periodo"', false)
            ->assertSee('id="aba-evolucao-geral"', false)
            ->assertSee('Avaliação inteira')
            ->assertSee('id="seletor-periodo-evolucao"', false)
            ->assertSee('<option value="3" selected>3º período</option>', false)   // período da turma do filtro
            ->assertSee('<option value="2" >2º período</option>', false)
            ->assertSee('id="evolucao-sem-ausentes" checked', false)               // marcada por padrão
            ->assertSee('Desconsiderar ausentes')
            ->assertSee('id="grafico-evolucao-periodo"', false)
            // Linha na regra de cor do desempenho (verde ≥ 60%, amarelo abaixo, degradê no cruzamento).
            ->assertSee('window.LinhaDesempenho', false)
            ->assertSee('LinhaDesempenho.serie(', false)
            ->assertSee('id="grafico-evolucao"', false)
            ->assertViewHas('evolucaoPorPeriodo', fn ($e) => array_keys($e) === [2, 3]);
    }

    /**
     * Regressão: uma edição malfeita da view já repetiu seções inteiras do BI
     * (dispersão, mapa de calor, áreas, alternativas...). Cada seção e cada id
     * da página tem que aparecer uma única vez.
     */
    public function test_bi_nao_repete_secoes_nem_ids(): void
    {
        [, $segunda] = $this->categoriaComDuasProvas();

        $html = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$segunda->codigo}/bi")->assertOk()->getContent();

        preg_match_all('/\sid="([^"]+)"/', $html, $m);
        $repetidos = array_keys(array_filter(array_count_values($m[1]), fn ($n) => $n > 1));
        $this->assertSame([], $repetidos, 'ids repetidos no BI: '.implode(', ', $repetidos));

        // Títulos de seção (h2) únicos e a lista nominal (botão de download) uma vez só.
        preg_match_all('#<h2[^>]*>(.*?)</h2>#s', $html, $h);
        $titulos = array_map(fn ($t) => trim(strip_tags($t)), $h[1]);
        $this->assertSame([], array_keys(array_filter(array_count_values($titulos), fn ($n) => $n > 1)), 'seções repetidas');
        $this->assertSame(1, substr_count($html, 'Baixar XLSX'));
        $this->assertSame(1, substr_count($html, 'function ordenarTabelaAlternativas'));
        $this->assertSame(1, substr_count($html, "getElementById('grafico-histograma')"));
    }

    /**
     * Mesma regressão, no código-fonte da view: o cenário de teste acima não
     * liga todos os visuais (dispersão TRI, mapa de calor...), então uma seção
     * repetida neles passaria batida. Cada título de seção literal e cada
     * gráfico montado em JS aparece uma única vez no template.
     */
    public function test_template_do_bi_nao_tem_secoes_repetidas(): void
    {
        // O Dashboard é montado de vários parciais (bi/*.blade.php, bi/scripts/*.blade.php): vale o conjunto.
        $arquivos = [resource_path('views/admin/avaliacoes/bi.blade.php'), ...glob(resource_path('views/admin/avaliacoes/bi/{,scripts/}*.blade.php'), GLOB_BRACE)];
        $fonte = implode("
", array_map('file_get_contents', $arquivos));

        preg_match_all('#<h2[^>]*>([^<{@]+)</h2>#u', $fonte, $h);
        $titulos = array_map('trim', $h[1]);
        $repetidos = array_keys(array_filter(array_count_values($titulos), fn ($n) => $n > 1));
        $this->assertSame([], $repetidos, 'seções repetidas no template: '.implode(' | ', $repetidos));

        preg_match_all("#getElementById\('(grafico-[a-z-]+)'\)#", $fonte, $g);
        $graficosRepetidos = array_keys(array_filter(array_count_values($g[1]), fn ($n) => $n > 1));
        $this->assertSame([], $graficosRepetidos, 'gráficos montados mais de uma vez: '.implode(', ', $graficosRepetidos));

        $this->assertSame(1, substr_count($fonte, 'function ordenarTabelaAlternativas'));
    }

    public function test_script_da_evolucao_recebe_os_periodos_do_curso(): void
    {
        // Regressão da divisão do bi.blade.php em parciais: uma variável calculada no bloco HTML da evolução não chega
        // ao script (@include tem escopo próprio) e o gráfico por período ficava com a lista vazia.
        [, $segunda] = $this->categoriaComDuasProvas();

        $html = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$segunda->codigo}/bi")->getContent();

        // Js::from() grava o objeto como JSON.parse('...') com as aspas escapadas (").
        $this->assertMatchesRegularExpression('/var porPeriodo = JSON\.parse\(.*u0022rotulo/', $html);
        $this->assertStringNotContainsString('var porPeriodo = [];', $html);
    }

    public function test_sem_periodo_valido_so_ha_a_aba_da_avaliacao_inteira(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulados']);
        $this->avaliacao('Primeira', '2026-03-10', $categoria->id, ['1' => ['', 'Turma A', 5]]);
        $segunda = $this->avaliacao('Segunda', '2026-04-10', $categoria->id, ['1' => ['', 'Turma A', 4]]);

        $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$segunda->codigo}/bi")
            ->assertOk()
            ->assertSee('id="aba-evolucao-geral"', false)
            ->assertDontSee('id="aba-evolucao-periodo"', false);
    }

    public function test_percentual_abaixo_de_60_e_amarelo_na_lista_de_alunos(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulados']);
        // 3/5 = 60% (verde) e 2/5 = 40% (amarelo).
        $avaliacao = $this->avaliacao('Prova', '2026-03-10', $categoria->id, ['1' => ['2º', 'Turma A', 3], '2' => ['2º', 'Turma A', 2]]);

        $html = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi")->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/bg-emerald-100 rounded"[^>]*width: 60[^>]*><\/div>\s*<span class="relative font-bold text-emerald-800 px-1">60,0%/', $html);
        $this->assertMatchesRegularExpression('/bg-amber-100 rounded"[^>]*width: 40[^>]*><\/div>\s*<span class="relative font-bold text-amber-800 px-1">40,0%/', $html);
    }
}
