<?php

namespace Tests\Feature;

use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\Resposta;
use App\Models\ResultadoMetrica;
use App\Services\RelatorioAdminService;
use App\Services\ResumoResultadoService;
use App\Support\FiltroDemografico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelatorioAdminServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_analise_alternativas_identifica_a_mais_marcada_e_mantem_ordem_fixa_de_linhas(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'C']);

        // Questão 1: A é a mais marcada e também é o gabarito.
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '3', 'questao_numero' => 1, 'resposta' => 'B']);

        // Questão 2: B é a mais marcada, mas o gabarito é C (turma majoritariamente errou).
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 2, 'resposta' => 'B']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'questao_numero' => 2, 'resposta' => 'B']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '3', 'questao_numero' => 2, 'resposta' => '']);

        $resultado = (new RelatorioAdminService)->analiseAlternativas($avaliacao);

        $this->assertSame(['A', 'B', '—'], $resultado['alternativas']);

        // Ordem das chaves em 'contagens' não importa (é um mapa alternativa => total,
        // sempre lido por chave na view) — só a ordem de 'alternativas', testada acima.
        $q1 = collect($resultado['questoes'])->firstWhere('numero', 1);
        $this->assertSame('A', $q1['gabarito']);
        $this->assertSame('A', $q1['maisMarcada']);
        $this->assertEquals(['A' => 2, 'B' => 1], $q1['contagens']);

        $q2 = collect($resultado['questoes'])->firstWhere('numero', 2);
        $this->assertSame('C', $q2['gabarito']);
        $this->assertSame('B', $q2['maisMarcada']);
        $this->assertEquals(['B' => 2, '—' => 1], $q2['contagens']);
    }

    public function test_analise_alternativas_sem_respostas_retorna_estrutura_vazia(): void
    {
        $avaliacao = Avaliacao::create([]);

        $resultado = (new RelatorioAdminService)->analiseAlternativas($avaliacao);

        $this->assertSame(['alternativas' => [], 'questoes' => []], $resultado);
    }

    public function test_analise_alternativas_respeita_filtro_de_turma(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);

        Aluno::create(['ra' => '1', 'nome' => 'Fulano', 'turma' => 'Turma A']);
        Aluno::create(['ra' => '2', 'nome' => 'Ciclano', 'turma' => 'Turma B']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'questao_numero' => 1, 'resposta' => 'B']);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $service = new RelatorioAdminService;

        $semFiltro = $service->analiseAlternativas($avaliacao);
        $q1SemFiltro = collect($semFiltro['questoes'])->firstWhere('numero', 1);
        $this->assertEquals(['A' => 1, 'B' => 1], $q1SemFiltro['contagens']);

        $comFiltro = $service->analiseAlternativas($avaliacao, '', new FiltroDemografico(turma: 'Turma A'));
        $q1ComFiltro = collect($comFiltro['questoes'])->firstWhere('numero', 1);
        $this->assertEquals(['A' => 1], $q1ComFiltro['contagens']);
    }

    public function test_correlacao_metricas_casa_pela_aluno_chave_mesmo_sem_cpf_na_linha_importada(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);

        // Aluno cadastrado com CPF, mas a linha importada (resposta/resumo/métrica)
        // só tem RA — aluno_chave vira o RA, não o CPF (mesma armadilha de
        // RelatorioAlunoServiceTest::rankingPercentil).
        Aluno::create(['ra' => '1', 'cpf' => '12345678909', 'nome' => 'Fulano']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'questao_numero' => 1, 'resposta' => 'B']);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        ResultadoMetrica::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'periodo' => '', 'nome_metrica' => 'Redação', 'valor' => '9']);
        ResultadoMetrica::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'periodo' => '', 'nome_metrica' => 'Redação', 'valor' => '3']);

        $resultado = (new RelatorioAdminService)->correlacaoMetricas($avaliacao);

        $this->assertSame(2, $resultado[0]['n']);
    }
}
