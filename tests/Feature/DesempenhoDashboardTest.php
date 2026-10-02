<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\CursoDoResultadoService;
use App\Services\PsicometriaService;
use App\Services\ResumoResultadoService;
use App\Support\CacheDeAnalise;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Desempenho do Dashboard: o escore e a presença de cada respondente vêm de `resultado_resumos` (gravados em
 * recalcular()), a lista nominal é paginada e os agregados pesados ficam em cache invalidado pelos dados.
 */
class DesempenhoDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'adm', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_ADMIN]);
    }

    private function recalcular(Avaliacao $avaliacao): void
    {
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);
    }

    /**
     * Avaliação de 4 questões (gabarito A): Q3 anulada com `dar_ponto`, Q4 com `distribuir_pontuacao`.
     *
     * @param  array<string, array<int, ?string>>  $respostasPorRa  RA => [resposta da Q1, Q2, Q3, Q4]
     */
    private function avaliacaoComAnuladas(array $respostasPorRa): Avaliacao
    {
        $avaliacao = Avaliacao::create(['nome' => 'Com anuladas']);
        foreach ([1 => null, 2 => null, 3 => 'dar_ponto', 4 => 'distribuir_pontuacao'] as $numero => $modo) {
            Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => $numero, 'gabarito' => 'A', 'anulada_modo' => $modo]);
        }
        foreach ($respostasPorRa as $ra => $respostas) {
            foreach ($respostas as $i => $resposta) {
                Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => (string) $ra, 'periodo' => '', 'questao_numero' => $i + 1, 'resposta' => $resposta]);
            }
        }
        $this->recalcular($avaliacao);

        return $avaliacao;
    }

    private function resumo(Avaliacao $avaliacao, string $ra): object
    {
        return DB::table('resultado_resumos')->where('avaliacao_codigo', $avaliacao->codigo)->where('aluno_chave', $ra)->firstOrFail();
    }

    // ------------------------------------------------------------ colunas do resumo

    public function test_resumo_grava_ausente_e_o_escore_dos_itens_da_analise(): void
    {
        $avaliacao = $this->avaliacaoComAnuladas([
            // acertou Q1 e Q2, errou Q3 (credita pela anulação) e respondeu a Q4 (distribuída: sai do cálculo)
            '1' => ['A', 'A', 'B', 'A'],
            // errou Q1, acertou Q2
            '2' => ['B', 'A', 'B', 'B'],
            // faltou: prova inteira em branco
            '3' => ['', null, '', null],
        ]);

        $r1 = $this->resumo($avaliacao, '1');
        $this->assertSame(3, (int) $r1->acertos, 'Q1 + Q2 + o ponto de graça da Q3');
        $this->assertSame(2, (int) $r1->acertos_itens, 'a análise psicométrica ignora a questão anulada');
        $this->assertSame(2, (int) $r1->itens_considerados, 'só Q1 e Q2 são itens da análise');
        $this->assertFalse((bool) $r1->ausente);

        $r2 = $this->resumo($avaliacao, '2');
        $this->assertSame(2, (int) $r2->acertos);
        $this->assertSame(1, (int) $r2->acertos_itens);
        $this->assertFalse((bool) $r2->ausente);

        $r3 = $this->resumo($avaliacao, '3');
        $this->assertTrue((bool) $r3->ausente, 'nenhuma resposta de verdade ⇒ ausente');
        $this->assertSame(1, (int) $r3->acertos, 'ausente "acerta" a questão dar_ponto, e mesmo assim é ausente');
        $this->assertSame(0, (int) $r3->acertos_itens);
    }

    public function test_sentinelas_de_resposta_em_branco_contam_como_ausente(): void
    {
        $avaliacao = $this->avaliacaoComAnuladas(['9' => ['BLANK', '-', null, '']]);

        $this->assertTrue((bool) $this->resumo($avaliacao, '9')->ausente);
    }

    public function test_quem_so_respondeu_questao_de_distribuicao_nao_ganha_resumo_mas_o_resultado_nao_some(): void
    {
        // O aluno 7 só tem resposta gravada para a Q4 (saiu da prova): como antes, não entra no resumo.
        $avaliacao = $this->avaliacaoComAnuladas(['8' => ['A', 'A', 'A', 'A']]);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '7', 'periodo' => '', 'questao_numero' => 4, 'resposta' => 'A']);
        $this->recalcular($avaliacao);

        $this->assertSame(['8'], DB::table('resultado_resumos')->where('avaliacao_codigo', $avaliacao->codigo)->pluck('aluno_chave')->all());
    }

    public function test_ausente_considera_todas_as_respostas_do_aluno_inclusive_de_questao_anulada(): void
    {
        // Respondeu SÓ a questão de distribuição de pontuação (e a Q1 em branco): não é ausente — ele compareceu.
        $avaliacao = $this->avaliacaoComAnuladas(['5' => ['', null, null, 'B'], '6' => ['A', 'A', 'A', 'A']]);

        $this->assertFalse((bool) $this->resumo($avaliacao, '5')->ausente);
    }

    // ------------------------------------------------------------ Dashboard lê dos resumos

    public function test_presenca_e_escores_da_analise_vem_dos_resumos(): void
    {
        $avaliacao = Avaliacao::create(['nome' => 'Prova']);
        foreach ([1, 2, 3] as $n) {
            Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => $n, 'gabarito' => 'A']);
        }
        // 12 respondentes: 10 presentes (acertos 0..3 variados) e 2 ausentes.
        for ($i = 1; $i <= 12; $i++) {
            foreach ([1, 2, 3] as $n) {
                $resposta = $i > 10 ? '' : ($n <= ($i % 4) ? 'A' : 'B');
                Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => (string) (100 + $i), 'periodo' => '', 'questao_numero' => $n, 'resposta' => $resposta]);
            }
        }
        $this->recalcular($avaliacao);

        $psicometria = app(PsicometriaService::class);

        $this->assertSame(['total' => 12, 'presentes' => 10, 'ausentes' => 2, 'percentual' => 83.3], $psicometria->presenca($avaliacao));
        $analise = $psicometria->analisar($avaliacao);
        $this->assertSame(12, $analise['respondentes']);
        $this->assertSame(10, $analise['semAusentes']['respondentes']);
    }

    public function test_ponto_bisserial_confere_com_a_formula_calculada_a_mao(): void
    {
        // Regressão: o ponto-bisserial saía inflado (≈ 1 em todos os itens) porque as respostas ERRADAS não
        // entravam na média de quem errou (NOT (NULL OR FALSE) = NULL em SQL). Aqui o valor é conferido contra
        // a fórmula aplicada direto sobre a matriz de acertos.
        $avaliacao = Avaliacao::create(['nome' => 'Pbis']);
        foreach ([1, 2, 3, 4] as $n) {
            Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => $n, 'gabarito' => 'A']);
        }
        // 12 respondentes; matriz[i][q] = acertou? — escores variados, com a Q1 correlacionada de forma imperfeita.
        $matriz = [
            [1, 1, 1, 1], [1, 1, 1, 0], [1, 1, 0, 1], [1, 0, 1, 1], [0, 1, 1, 1], [1, 1, 0, 0],
            [0, 1, 1, 0], [1, 0, 0, 1], [0, 0, 1, 1], [0, 1, 0, 0], [0, 0, 1, 0], [0, 0, 0, 0],
        ];
        foreach ($matriz as $i => $linha) {
            foreach ($linha as $q => $acertou) {
                Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => 'R'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'periodo' => '', 'questao_numero' => $q + 1, 'resposta' => $acertou ? 'A' : 'B']);
            }
        }
        $this->recalcular($avaliacao);

        $escores = array_map('array_sum', $matriz);
        $n = count($escores);
        $media = array_sum($escores) / $n;
        $desvio = sqrt(array_sum(array_map(fn ($e) => ($e - $media) ** 2, $escores)) / $n);

        $analise = app(PsicometriaService::class)->analisar($avaliacao);
        foreach ($analise['itens'] as $item) {
            $q = $item['numero'] - 1;
            $acertaram = array_values(array_filter(array_keys($matriz), fn ($i) => $matriz[$i][$q] === 1));
            $erraram = array_values(array_filter(array_keys($matriz), fn ($i) => $matriz[$i][$q] === 0));
            $p = count($acertaram) / $n;
            $m1 = array_sum(array_map(fn ($i) => $escores[$i], $acertaram)) / count($acertaram);
            $m0 = array_sum(array_map(fn ($i) => $escores[$i], $erraram)) / count($erraram);
            $esperado = round(($m1 - $m0) / $desvio * sqrt($p * (1 - $p)), 4);

            $this->assertSame($esperado, $item['pontoBisserial'], "ponto-bisserial da Q{$item['numero']}");
            $this->assertLessThan(1.0, $item['pontoBisserial'], 'itens imperfeitos não podem ter correlação 1');
        }
    }

    // ------------------------------------------------------------ lista nominal paginada

    /** @return array{0: Avaliacao, 1: Admin} */
    private function avaliacaoComRespondentes(int $quantos, int $ausentes = 0): array
    {
        $avaliacao = Avaliacao::create(['nome' => 'Grande']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'A']);

        $linhas = [];
        for ($i = 1; $i <= $quantos; $i++) {
            $ausente = $i > $quantos - $ausentes;
            foreach ([1, 2] as $n) {
                $linhas[] = [
                    'avaliacao_codigo' => $avaliacao->codigo, 'ra' => 'A'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'periodo' => '',
                    'questao_numero' => $n, 'resposta' => $ausente ? '' : ($i % 3 === 0 && $n === 2 ? 'B' : 'A'),
                ];
            }
        }
        Resposta::insert($linhas);
        $this->recalcular($avaliacao);

        return [$avaliacao, $this->admin()];
    }

    public function test_dashboard_traz_so_a_primeira_pagina_da_lista_e_o_total(): void
    {
        [$avaliacao, $admin] = $this->avaliacaoComRespondentes(130);

        $resposta = $this->actingAs($admin, 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi");

        $resposta->assertOk();
        $resposta->assertSee('Alunos da avaliação (130 respondente(s))');
        $resposta->assertViewHas('rankingCompleto', fn ($lista) => count($lista) === 100);
        $resposta->assertViewHas('rankingTotal', 130);
        $resposta->assertSee('Mostrar mais (30 restantes)');
    }

    public function test_lista_pequena_nao_mostra_o_botao_de_carregar_mais(): void
    {
        [$avaliacao, $admin] = $this->avaliacaoComRespondentes(20);

        $this->actingAs($admin, 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi")
            ->assertOk()
            ->assertSee('Alunos da avaliação (20 respondente(s))')
            ->assertDontSee('Mostrar mais');
    }

    public function test_endpoint_devolve_as_proximas_linhas_com_posicao_continua_e_ausentes_no_fim(): void
    {
        [$avaliacao, $admin] = $this->avaliacaoComRespondentes(130, ausentes: 5);

        $primeira = $this->actingAs($admin, 'admin')->getJson("/avaliacoes/{$avaliacao->codigo}/bi/alunos/linhas?inicio=100&quantidade=20");
        $primeira->assertOk();
        $primeira->assertJsonPath('proximo', 120);
        $html = $primeira->json('html');
        $this->assertSame(20, substr_count($html, '<tr'));
        $this->assertStringContainsString('>101<', $html, 'a posição continua de onde a página anterior parou');

        $ultima = $this->actingAs($admin, 'admin')->getJson("/avaliacoes/{$avaliacao->codigo}/bi/alunos/linhas?inicio=120&quantidade=20");
        $ultima->assertJsonPath('proximo', null);
        $html = $ultima->json('html');
        $this->assertSame(10, substr_count($html, '<tr'));
        $this->assertSame(5, substr_count($html, 'Ausente</span>'), 'os 5 ausentes ficam no fim da lista');
    }

    public function test_ordem_da_lista_e_estavel_nos_empates(): void
    {
        [$avaliacao] = $this->avaliacaoComRespondentes(30);

        $servico = app(\App\Services\RelatorioAdminService::class);
        $inteira = array_column($servico->rankingCompleto($avaliacao), 'ra');
        $paginas = array_merge(
            array_column($servico->rankingCompleto($avaliacao, '', 10, 0), 'ra'),
            array_column($servico->rankingCompleto($avaliacao, '', 10, 10), 'ra'),
            array_column($servico->rankingCompleto($avaliacao, '', 10, 20), 'ra'),
        );

        $this->assertSame($inteira, $paginas, 'paginar não repete nem pula ninguém, mesmo com muitos empates');
        $this->assertCount(30, array_unique($inteira));
    }

    public function test_endpoint_respeita_acesso_do_coordenador_e_exige_login(): void
    {
        [$avaliacao] = $this->avaliacaoComRespondentes(5);
        $coordenador = Admin::create(['username' => 'coord', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_COORDENADOR]);
        $coordenador->sincronizarCursos(['DIREITO']);

        // A avaliação não tem alunos do curso dele: 404, como no resto do BI.
        $this->actingAs($coordenador, 'admin')->getJson("/avaliacoes/{$avaliacao->codigo}/bi/alunos/linhas")->assertNotFound();
        $this->app['auth']->guard('admin')->logout();
        $this->getJson("/avaliacoes/{$avaliacao->codigo}/bi/alunos/linhas")->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------ cache por impressão digital

    public function test_cache_guarda_e_so_recalcula_quando_os_dados_mudam(): void
    {
        $avaliacao = Avaliacao::create(['nome' => 'Cache']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A', 'area' => 'Clínica']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'periodo' => '', 'questao_numero' => 1, 'resposta' => 'A']);
        $this->recalcular($avaliacao);

        $chamadas = 0;
        $calcular = function () use (&$chamadas) {
            $chamadas++;

            return ['linhas' => [['area' => 'Clínica', 'total' => 3]]];
        };
        $ler = fn () => CacheDeAnalise::lembrar('teste', $avaliacao->codigo, ['p' => ''], $calcular);

        $this->assertSame(['linhas' => [['area' => 'Clínica', 'total' => 3]]], $ler());
        $ler();
        $this->assertSame(1, $chamadas, 'a segunda leitura sai do cache (e volta idêntica depois de serializar)');

        // Outro parâmetro = outra entrada.
        CacheDeAnalise::lembrar('teste', $avaliacao->codigo, ['p' => '2026/1'], $calcular);
        $this->assertSame(2, $chamadas);

        // Resultado novo (recalcular reinsere o resumo) invalida.
        $this->recalcular($avaliacao);
        $ler();
        $this->assertSame(3, $chamadas);

        // Metadado de questão editado pelo Eloquent também.
        $ler();
        $this->assertSame(3, $chamadas);
        Questao::where('avaliacao_codigo', $avaliacao->codigo)->firstOrFail()->update(['area' => 'Cirurgia']);
        $ler();
        $this->assertSame(4, $chamadas);

        // Mudança de curso do resultado (matrícula importada) avisa por invalidar().
        $ler();
        $this->assertSame(4, $chamadas);
        (new CursoDoResultadoService)->atualizarAvaliacao($avaliacao->codigo);
        $ler();
        $this->assertSame(5, $chamadas);
    }

    public function test_cache_de_uma_avaliacao_nao_vaza_para_outra(): void
    {
        $a = Avaliacao::create(['nome' => 'A']);
        $b = Avaliacao::create(['nome' => 'B']);

        $this->assertSame('da A', CacheDeAnalise::lembrar('x', $a->codigo, [], fn () => 'da A'));
        $this->assertSame('da B', CacheDeAnalise::lembrar('x', $b->codigo, [], fn () => 'da B'));
    }

    public function test_agregados_do_dashboard_nao_mudam_depois_de_cacheados_e_refletem_novos_dados(): void
    {
        $avaliacao = Avaliacao::create(['nome' => 'Dados']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A', 'area' => 'Clínica']);
        for ($i = 1; $i <= 12; $i++) {
            Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => (string) $i, 'periodo' => '', 'questao_numero' => 1, 'resposta' => $i <= 6 ? 'A' : 'B']);
        }
        $this->recalcular($avaliacao);
        $admin = $this->admin();

        $primeira = $this->actingAs($admin, 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi");
        $primeira->assertOk();
        $segunda = $this->actingAs($admin, 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi");
        $this->assertSame($primeira->viewData('mediaPorArea'), $segunda->viewData('mediaPorArea'));
        $this->assertSame(50.0, $segunda->viewData('mediaPorArea')['Clínica']);

        // Mais 4 acertos entram (como num import) e o recálculo roda: o cache não pode servir o valor antigo.
        for ($i = 13; $i <= 16; $i++) {
            Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => (string) $i, 'periodo' => '', 'questao_numero' => 1, 'resposta' => 'A']);
        }
        $this->recalcular($avaliacao);

        $terceira = $this->actingAs($admin, 'admin')->get("/avaliacoes/{$avaliacao->codigo}/bi");
        $this->assertSame(62.5, $terceira->viewData('mediaPorArea')['Clínica']);
    }
}
