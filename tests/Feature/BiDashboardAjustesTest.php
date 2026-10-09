<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Ajustes do Dashboard da avaliação: link do gabarito, acertos dentro do esperado na lista de alunos, desempenho por
 * área sem teia, filtro de área nas alternativas, análise demográfica no fim (com a forma de ingresso) e sem a evolução
 * da média na categoria.
 */
class BiDashboardAjustesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'admin', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_ADMIN]);
    }

    /**
     * Avaliação de 10 questões (gabarito A; as 5 primeiras da área Clínica e as 5 últimas da Cirurgia).
     * `$meta` = período mínimo das 5 primeiras e das 5 últimas. `$alunos` = [período, acertos, forma de ingresso].
     *
     * @param  array{0: ?int, 1: ?int}  $meta
     * @param  array<int, array{0: string, 1: int, 2?: ?string}>  $alunos
     */
    private function avaliacao(array $meta, array $alunos, ?string $link = null): Avaliacao
    {
        static $ra = 4000;

        $avaliacao = Avaliacao::create(['nome' => 'Diagnóstico', 'data_avaliacao' => '2026-03-10', 'link_comentado' => $link]);
        foreach (range(1, 10) as $n) {
            Questao::create([
                'avaliacao_codigo' => $avaliacao->codigo, 'numero' => $n, 'gabarito' => 'A', 'area' => $n <= 5 ? 'Clínica' : 'Cirurgia',
                'tema' => 'T'.$n, 'periodo_minimo' => $n <= 5 ? $meta[0] : $meta[1],
            ]);
        }

        foreach ($alunos as $dados) {
            [$periodo, $acertos] = $dados;
            $ra++;
            $aluno = Aluno::create(['ra' => (string) $ra, 'nome' => 'Aluno '.$ra, 'curso' => 'MEDICINA', 'periodo' => $periodo, 'forma_ingresso' => $dados[2] ?? null]);
            foreach (range(1, 10) as $n) {
                Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'aluno_id' => $aluno->id, 'ra' => (string) $ra, 'periodo' => $periodo, 'questao_numero' => $n, 'resposta' => $n <= $acertos ? 'A' : 'B']);
            }
        }
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        return $avaliacao;
    }

    public function test_cabecalho_tem_o_link_do_gabarito(): void
    {
        $avaliacao = $this->avaliacao([null, null], [['3º', 5]], 'https://exemplo.test/gabarito.pdf');
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi")
            ->assertOk()
            ->assertSee('href="https://exemplo.test/gabarito.pdf"', false)
            ->assertSee('Gabarito comentado')
            ->assertSee('Gabarito da avaliação')
            ->assertSee(route('avaliacoes.show', $avaliacao).'#gabarito', false);

        // Sem o link cadastrado, só o atalho para o gabarito da avaliação.
        $semLink = $this->avaliacao([null, null], [['3º', 5]]);
        $this->actingAs($admin, 'admin')->get("/avaliacoes/{$semLink->codigo}/bi")
            ->assertOk()->assertDontSee('Gabarito comentado')->assertSee('Gabarito da avaliação');
    }

    public function test_lista_de_alunos_traz_os_acertos_dentro_do_esperado(): void
    {
        // Questões 1–5 valem desde o 1º período; 6–10 só a partir do 4º. Aluno do 2º período: esperadas = 5 (1–5).
        $avaliacao = $this->avaliacao([1, 4], [['2º', 8], ['5º', 8]]);

        $resposta = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi")->assertOk();

        $resposta->assertSee('Acertos dentro do esperado');
        // 2º: 8 acertos (as 5 esperadas + 3 de períodos à frente) → 5 de 5. 5º: tudo é esperado → 8 de 10.
        $linhas = $resposta->viewData('rankingCompleto');
        $porPeriodo = collect($linhas)->keyBy('periodo');
        $this->assertSame(['acertos' => 5, 'total' => 5], $porPeriodo['2º']['esperadas']);
        $this->assertSame(['acertos' => 8, 'total' => 10], $porPeriodo['5º']['esperadas']);
        $resposta->assertSee('de 5</span>', false);
    }

    public function test_sem_a_meta_a_coluna_nao_aparece(): void
    {
        $avaliacao = $this->avaliacao([null, null], [['2º', 8], ['5º', 3]]);

        $resposta = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi")->assertOk();

        $resposta->assertDontSee('Acertos dentro do esperado');
        $this->assertNull($resposta->viewData('rankingCompleto')[0]['esperadas']);
    }

    public function test_mais_linhas_da_lista_tambem_trazem_a_coluna(): void
    {
        $avaliacao = $this->avaliacao([1, 4], [['2º', 8], ['5º', 8]]);

        $json = $this->actingAs($this->admin(), 'admin')->getJson("/avaliacoes/{$avaliacao->codigo}/bi/alunos/linhas?inicio=0&quantidade=10")->assertOk()->json();

        $this->assertStringContainsString('de 5</span>', $json['html']);
        $this->assertStringContainsString('de 10</span>', $json['html']);
    }

    public function test_desempenho_por_area_sem_o_grafico_de_teia(): void
    {
        $avaliacao = $this->avaliacao([null, null], [['3º', 5], ['3º', 7]]);

        $resposta = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi")->assertOk();

        $resposta->assertSee('Desempenho por área');
        $resposta->assertDontSee('id="grafico-area"', false);
        $resposta->assertDontSee("getElementById('grafico-area')", false);
        // A lista de barras continua com as duas áreas.
        $resposta->assertSee('Clínica');
        $resposta->assertSee('Cirurgia');
    }

    public function test_alternativas_tem_filtro_por_area(): void
    {
        $avaliacao = $this->avaliacao([null, null], [['3º', 5], ['3º', 7]]);

        $resposta = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi")->assertOk();

        $resposta->assertSee('id="filtro-area-alternativas"', false);
        $resposta->assertSee('<option value="Clínica">Clínica</option>', false);
        $resposta->assertSee('<option value="Cirurgia">Cirurgia</option>', false);
        $resposta->assertSee('data-area="Cirurgia"', false);
        $resposta->assertSee('function filtrarAlternativasPorArea', false);
    }

    public function test_analise_demografica_fica_no_fim_do_dashboard(): void
    {
        $categoria = Categoria::create(['nome' => 'Diagnósticos']);
        $avaliacao = $this->avaliacao([null, null], array_map(fn ($i) => ['3º', 5, $i % 2 ? 'PROUNI' : 'Vestibular'], range(1, 24)));
        $avaliacao->update(['categoria_id' => $categoria->id]);

        $html = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi")->assertOk()->getContent();

        $demografica = strpos($html, 'Análise demográfica');
        $this->assertNotFalse($demografica);
        foreach (['Análise de alternativas por questão', 'Desempenho por área', 'Desempenho por tema'] as $antes) {
            $posicao = strpos($html, $antes);
            $this->assertNotFalse($posicao, $antes);
            $this->assertLessThan($demografica, $posicao, "{$antes} deve vir antes da análise demográfica");
        }
        // Nada de seção depois dela (só scripts): o último <h2> da página é o da demografia ou das suas sub-seções.
        preg_match_all('#<h2[^>]*>(.*?)</h2>#s', $html, $h);
        $this->assertSame('Análise demográfica', trim(strip_tags(end($h[1]))));
    }

    public function test_sem_a_evolucao_da_media_na_categoria(): void
    {
        $categoria = Categoria::create(['nome' => 'Diagnósticos']);
        $a = $this->avaliacao([null, null], [['3º', 5], ['3º', 7]]);
        $a->update(['categoria_id' => $categoria->id]);
        $b = $this->avaliacao([null, null], [['3º', 6], ['3º', 8]]);
        $b->update(['categoria_id' => $categoria->id, 'data_avaliacao' => '2026-09-10']);

        $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$b->codigo}/bi")
            ->assertOk()
            ->assertDontSee('Evolução da média na categoria')
            ->assertDontSee('id="grafico-evolucao"', false);
    }

    public function test_forma_de_ingresso_vem_da_planilha_de_alunos(): void
    {
        $admin = $this->admin();
        $csv = "RA,Nome,Per. Letivo,Curso,Período,Forma de Ingresso\n"
            ."2026001,Ana Silva,2026/1,Medicina,5,PROUNI\n"
            ."2026002,Bia Souza,2026/1,Medicina,5,Vestibular\n";
        $this->actingAs($admin, 'admin')->post('/alunos/importar', ['arquivo' => UploadedFile::fake()->createWithContent('alunos.csv', $csv)]);

        $this->assertSame('PROUNI', Aluno::where('ra', '2026001')->value('forma_ingresso'));
        $this->assertSame('Vestibular', Aluno::where('ra', '2026002')->value('forma_ingresso'));

        // Reimportar sem a coluna não apaga o que já estava salvo.
        $semColuna = "RA,Nome,Per. Letivo,Curso,Período\n2026001,Ana Silva,2026/1,Medicina,5\n";
        $this->actingAs($admin, 'admin')->post('/alunos/importar', ['arquivo' => UploadedFile::fake()->createWithContent('alunos.csv', $semColuna)]);
        $this->assertSame('PROUNI', Aluno::where('ra', '2026001')->value('forma_ingresso'));
    }

    public function test_coluna_ingresso_sozinha_nao_e_lida_como_forma_de_ingresso(): void
    {
        // "Ingresso" costuma ser a DATA de ingresso — não pode virar "forma de ingresso".
        $csv = "RA,Nome,Per. Letivo,Curso,Período,Ingresso\n2026001,Ana Silva,2026/1,Medicina,5,15/02/2022\n";
        $this->actingAs($this->admin(), 'admin')->post('/alunos/importar', ['arquivo' => UploadedFile::fake()->createWithContent('alunos.csv', $csv)]);

        $this->assertNull(Aluno::where('ra', '2026001')->value('forma_ingresso'));
    }

    public function test_dashboard_compara_o_desempenho_por_forma_de_ingresso(): void
    {
        // 12 alunos por forma de ingresso (mínimo de 10 por grupo): PROUNI acerta mais que Vestibular.
        $alunos = [
            ...array_map(fn () => ['3º', 9, 'PROUNI'], range(1, 12)),
            ...array_map(fn () => ['3º', 5, 'Vestibular'], range(1, 12)),
        ];
        $avaliacao = $this->avaliacao([null, null], $alunos);

        $resposta = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi")->assertOk();

        $resposta->assertSee('Forma de ingresso');
        $resposta->assertSee('id="grafico-forma-ingresso"', false);
        $resposta->assertSee('Equidade: desempenho por recorte');
        $equidade = $resposta->viewData('equidade');
        $this->assertSame('Forma de ingresso', $equidade['forma_ingresso']['rotulo']);
        $grupos = collect($equidade['forma_ingresso']['grupos'])->keyBy('valor');
        $this->assertSame(90.0, $grupos['PROUNI']['media']);
        $this->assertSame(50.0, $grupos['Vestibular']['media']);
        $this->assertSame(['PROUNI' => 12, 'Vestibular' => 12], $resposta->viewData('perfilDemografico')['forma_ingresso']);
    }
}
