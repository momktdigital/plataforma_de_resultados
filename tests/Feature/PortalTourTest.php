<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\VerificacaoEmail;
use App\Models\Resposta;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tour guiado do portal do aluno e rodapé: "Refazer tour da página" nas telas com tour, e o atalho para a área
 * administrativa só na tela de login do aluno.
 */
class PortalTourTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    private function entrar(): Aluno
    {
        $aluno = Aluno::create(['ra' => '2026001', 'cpf' => '12345678909', 'data_nascimento' => '2000-03-15', 'nome' => 'Fulano de Tal']);
        $this->followingRedirects()->post('/portal/consultar', ['cpf' => '123.456.789-09', 'data_nascimento' => '15/03/2000']);

        return $aluno;
    }

    /** @return array{chave: string, passos: array<int, array<string, string>>} */
    private function dadosDoTour(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" id="portal-tour-dados">(.*?)</script>#s', $html, $m), 'a página não tem o tour');
        $dados = json_decode($m[1], true);
        $this->assertIsArray($dados);

        return $dados;
    }

    public function test_tela_de_login_do_aluno_nao_tem_tour_mas_tem_o_acesso_administrativo(): void
    {
        $response = $this->get('/portal')->assertOk();

        $response->assertSee('Área administrativa');
        $response->assertDontSee('Refazer tour da página');
        $response->assertDontSee('portal-tour-dados', false);
        $response->assertDontSee('portal-tour.js', false);
    }

    public function test_acesso_administrativo_nao_aparece_nas_demais_telas_do_aluno(): void
    {
        $aluno = $this->entrar();
        $avaliacao = Avaliacao::create(['nome' => 'Prova']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => $aluno->ra, 'periodo' => '', 'questao_numero' => 1, 'resposta' => 'A']);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        foreach ([
            route('portal.resultados'),
            route('portal.resultados.avaliacao', ['avaliacao' => $avaliacao->codigo, 'periodo' => '']),
        ] as $url) {
            $response = $this->get($url)->assertOk();
            $response->assertDontSee('Área administrativa');
            $response->assertSee('Refazer tour da página');
        }
    }

    public function test_cada_tela_tem_o_seu_tour_com_alvos_existentes_na_pagina(): void
    {
        $aluno = $this->entrar();
        $avaliacao = Avaliacao::create(['nome' => 'Prova']);
        foreach ([1 => 'A', 2 => 'B'] as $n => $resposta) {
            Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => $n, 'gabarito' => 'A', 'area' => 'Cardiologia', 'tema' => 'T'.$n]);
            Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => $aluno->ra, 'periodo' => '', 'questao_numero' => $n, 'resposta' => $resposta]);
        }
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $resultados = $this->get(route('portal.resultados'))->getContent();
        $detalhe = $this->get(route('portal.resultados.avaliacao', ['avaliacao' => $avaliacao->codigo, 'periodo' => '']))->getContent();

        $this->assertSame('resultados', $this->dadosDoTour($resultados)['chave']);
        $this->assertSame('avaliacao', $this->dadosDoTour($detalhe)['chave']);

        // Cada alvo marcado com data-tour/id que existe fixo na tela está de fato no HTML.
        foreach ([[$resultados, ['data-tour="cabecalho"', 'data-tour="filtros"', 'id="resultados-lista"', 'id="portal-conta-botao"']],
            [$detalhe, ['data-tour="voltar"', 'btn-pdf-avaliacao', 'data-tour="nota"', 'data-tour="respostas"', 'data-tour="areas"', 'data-tour="trilha"']]] as [$html, $alvos]) {
            foreach ($alvos as $alvo) {
                $this->assertStringContainsString($alvo, $html, $alvo);
            }
        }
    }

    public function test_tela_de_verificacao_do_codigo_nao_tem_o_atalho_administrativo(): void
    {
        $aluno = Aluno::create(['ra' => '2026001', 'cpf' => '12345678909', 'data_nascimento' => '2000-03-15', 'nome' => 'Fulano', 'email' => 'a@b.c']);
        VerificacaoEmail::create([
            'cpf' => $aluno->cpf,
            'codigo' => VerificacaoEmail::hashDoCodigo($aluno->cpf, '123456'),
            'expira_em' => now()->addMinutes(10),
        ]);
        $this->withSession(['portal_pre_auth' => ['aluno_id' => $aluno->id, 'cpf' => $aluno->cpf, 'ate' => now()->addMinutes(15)->timestamp]]);

        $this->post('/portal/verificar', ['codigo' => '000000'])
            ->assertOk()
            ->assertSee('Código incorreto')
            ->assertDontSee('Área administrativa');
    }

    public function test_textos_do_tour_nao_usam_linguagem_coloquial(): void
    {
        $aluno = $this->entrar();
        $avaliacao = Avaliacao::create(['nome' => 'Prova']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => $aluno->ra, 'periodo' => '', 'questao_numero' => 1, 'resposta' => 'A']);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        foreach ([
            $this->get(route('portal.resultados'))->getContent(),
            $this->get(route('portal.resultados.avaliacao', ['avaliacao' => $avaliacao->codigo, 'periodo' => '']))->getContent(),
        ] as $html) {
            $texto = json_encode($this->dadosDoTour($html), JSON_UNESCAPED_UNICODE);

            foreach (['pra ', 'dá pra', 'dá para', 'bônus', 'tranquilo'] as $informal) {
                $this->assertStringNotContainsString($informal, $texto, $informal);
            }
        }
    }

}
