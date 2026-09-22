<?php

namespace Tests\Feature;

use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\QuestaoReferencia;
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

    public function test_analise_alternativas_identifica_o_distrator_e_ordena_por_percentual_de_acerto(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A', 'area' => 'Clínica Médica', 'tema' => 'Herpes Simples']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'C']);

        // Questão 1: A (gabarito) tem 2 votos, B tem 3 — bate o próprio
        // gabarito em popularidade, então B é o distrator.
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '3', 'questao_numero' => 1, 'resposta' => 'B']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '4', 'questao_numero' => 1, 'resposta' => 'B']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '5', 'questao_numero' => 1, 'resposta' => 'B']);

        // Questão 2: gabarito é C, mas ninguém acertou (B é a mais marcada, também distrator).
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 2, 'resposta' => 'B']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'questao_numero' => 2, 'resposta' => 'B']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '3', 'questao_numero' => 2, 'resposta' => '']);

        $resultado = (new RelatorioAdminService)->analiseAlternativas($avaliacao);

        // Ordenado por % de acerto ascendente: questão 2 (0%) antes da questão 1 (66.7%).
        $this->assertSame(2, $resultado[0]['numero']);
        $this->assertSame(1, $resultado[1]['numero']);

        $q1 = $resultado[1];
        $this->assertSame('A', $q1['gabarito']);
        $this->assertSame('Clínica Médica', $q1['area']);
        $this->assertSame('Herpes Simples', $q1['tema']);
        $this->assertEquals(40.0, $q1['percentualAcerto']);
        $alternativaB = collect($q1['alternativas'])->firstWhere('letra', 'B');
        $this->assertTrue($alternativaB['ehDistrator']);
        $alternativaA = collect($q1['alternativas'])->firstWhere('letra', 'A');
        $this->assertTrue($alternativaA['ehGabarito']);
        $this->assertFalse($alternativaA['ehDistrator']);

        $q2 = $resultado[0];
        $this->assertSame('C', $q2['gabarito']);
        $this->assertNull($q2['area']);
        $this->assertEquals(0.0, $q2['percentualAcerto']);
        $alternativaB2 = collect($q2['alternativas'])->firstWhere('letra', 'B');
        $this->assertTrue($alternativaB2['ehDistrator']);
    }

    public function test_analise_alternativas_nao_marca_distrator_quando_gabarito_e_a_mais_marcada(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);

        // A maioria acertou (A com 3 votos) — B tem 2, mas nunca chega a
        // "roubar" a resposta certa de verdade, então não deve ser
        // destacado como distrator.
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '3', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '4', 'questao_numero' => 1, 'resposta' => 'B']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '5', 'questao_numero' => 1, 'resposta' => 'B']);

        $resultado = (new RelatorioAdminService)->analiseAlternativas($avaliacao);

        $alternativas = collect($resultado[0]['alternativas'])->keyBy('letra');
        $this->assertFalse($alternativas['B']['ehDistrator']);
        $this->assertTrue($alternativas['A']['ehGabarito']);
    }

    public function test_analise_alternativas_nunca_marca_blank_ou_hifen_como_distrator(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);

        // "BLANK"/"-" (sentinelas reais da importação — ver Resposta::
        // SENTINELAS_SEM_RESPOSTA) são maioria aqui, mais que qualquer
        // alternativa errada de verdade — mas nenhuma das duas pode ganhar
        // o rótulo de distrator, só B pode (única alternativa errada real).
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'questao_numero' => 1, 'resposta' => 'B']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '8', 'questao_numero' => 1, 'resposta' => 'B']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '3', 'questao_numero' => 1, 'resposta' => 'BLANK']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '4', 'questao_numero' => 1, 'resposta' => 'BLANK']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '5', 'questao_numero' => 1, 'resposta' => '-']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '6', 'questao_numero' => 1, 'resposta' => '-']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '7', 'questao_numero' => 1, 'resposta' => '-']);

        $resultado = (new RelatorioAdminService)->analiseAlternativas($avaliacao);

        $alternativas = collect($resultado[0]['alternativas'])->keyBy('letra');
        $this->assertFalse($alternativas['BLANK']['ehDistrator']);
        $this->assertFalse($alternativas['-']['ehDistrator']);
        $this->assertTrue($alternativas['B']['ehDistrator']);
    }

    public function test_analise_alternativas_sem_respostas_retorna_lista_vazia(): void
    {
        $avaliacao = Avaliacao::create([]);

        $resultado = (new RelatorioAdminService)->analiseAlternativas($avaliacao);

        $this->assertSame([], $resultado);
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
        $q1SemFiltro = collect($semFiltro)->firstWhere('numero', 1);
        $this->assertSame(2, $q1SemFiltro['totalRespostas']);

        $comFiltro = $service->analiseAlternativas($avaliacao, '', new FiltroDemografico(turma: 'Turma A'));
        $q1ComFiltro = collect($comFiltro)->firstWhere('numero', 1);
        $this->assertSame(1, $q1ComFiltro['totalRespostas']);
        $this->assertEquals(100.0, $q1ComFiltro['percentualAcerto']);
    }

    public function test_media_por_area_agrega_por_campo_area_da_questao(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A', 'area' => 'Pediatria']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'B', 'area' => 'Pediatria']);

        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 2, 'resposta' => 'X']);

        $resultado = (new RelatorioAdminService)->mediaPorArea($avaliacao);

        $this->assertEquals(['Pediatria' => 50.0], $resultado);
    }

    public function test_desempenho_por_tema_agrupa_area_e_tema_e_ordena_por_percentual(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A', 'area' => 'Pediatria', 'tema' => 'COVID']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'B', 'area' => 'Pediatria', 'tema' => 'Bradicardia']);

        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'X']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 2, 'resposta' => 'B']);

        $resultado = (new RelatorioAdminService)->desempenhoPorTema($avaliacao);

        $this->assertSame('COVID', $resultado[0]['tema']);
        $this->assertEquals(0.0, $resultado[0]['percentual']);
        $this->assertSame('Bradicardia', $resultado[1]['tema']);
        $this->assertEquals(100.0, $resultado[1]['percentual']);
        $this->assertSame('Pediatria', $resultado[1]['area']);
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

    public function test_desempenho_por_referencia_agrupa_por_tipo_e_conta_a_cobertura(): void
    {
        $avaliacao = Avaliacao::create([]);
        $q1 = Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        $q2 = Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'A']);

        // As duas questões cobrem a mesma competência da DCN; só a primeira
        // tem PPC.
        QuestaoReferencia::create(['questao_id' => $q1->id, 'tipo' => 'dcn', 'valor' => 'Atenção à saúde']);
        QuestaoReferencia::create(['questao_id' => $q2->id, 'tipo' => 'dcn', 'valor' => 'Atenção à saúde']);
        QuestaoReferencia::create(['questao_id' => $q1->id, 'tipo' => 'ppc', 'valor' => 'PPC-01']);

        // Q1: 1 acerto em 2; Q2: 0 em 2 → DCN = 1 acerto em 4 respostas = 25%.
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'questao_numero' => 1, 'resposta' => 'B']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 2, 'resposta' => 'B']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'questao_numero' => 2, 'resposta' => 'B']);

        $resultado = (new RelatorioAdminService)->desempenhoPorReferencia($avaliacao);

        $this->assertArrayHasKey('dcn', $resultado);
        $this->assertArrayHasKey('ppc', $resultado);
        $this->assertStringContainsString('DCN', $resultado['dcn']['rotulo']);

        $dcn = $resultado['dcn']['itens'][0];
        $this->assertSame('Atenção à saúde', $dcn['valor']);
        $this->assertSame(2, $dcn['totalQuestoes'], 'cobertura: duas questões batem nessa competência');
        $this->assertSame(25.0, $dcn['percentual']);

        $ppc = $resultado['ppc']['itens'][0];
        $this->assertSame(1, $ppc['totalQuestoes']);
        $this->assertSame(50.0, $ppc['percentual']);
    }

    public function test_desempenho_por_referencia_ignora_questao_com_pontuacao_distribuida(): void
    {
        $avaliacao = Avaliacao::create([]);
        $q1 = Questao::create([
            'avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A',
            'anulada_modo' => 'distribuir_pontuacao',
        ]);
        QuestaoReferencia::create(['questao_id' => $q1->id, 'tipo' => 'dcn', 'valor' => 'Atenção à saúde']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);

        $this->assertSame([], (new RelatorioAdminService)->desempenhoPorReferencia($avaliacao));
    }

    public function test_equidade_suprime_grupo_pequeno_demais_e_informa_quantos(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);

        // 10 alunos de um sexo (acertam) e 10 de outro (erram) — os dois grupos
        // passam do piso. Um terceiro grupo com 2 pessoas deve ser suprimido.
        $criar = function (string $ra, string $sexo, string $resposta) use ($avaliacao) {
            Aluno::create(['ra' => $ra, 'cpf' => null, 'data_nascimento' => '2000-01-01', 'nome' => 'A'.$ra, 'sexo' => $sexo]);
            Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => $ra, 'questao_numero' => 1, 'resposta' => $resposta]);
        };

        for ($i = 1; $i <= 10; $i++) {
            $criar('F'.$i, 'Feminino', 'A');
        }
        for ($i = 1; $i <= 10; $i++) {
            $criar('M'.$i, 'Masculino', 'B');
        }
        for ($i = 1; $i <= 2; $i++) {
            $criar('X'.$i, 'Não informado', 'A');
        }

        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $resultado = (new RelatorioAdminService)->equidadeDemografica($avaliacao);

        $this->assertArrayHasKey('sexo', $resultado);
        $this->assertCount(2, $resultado['sexo']['grupos'], 'o grupo de 2 pessoas não pode aparecer');
        $this->assertSame(1, $resultado['sexo']['suprimidos']);

        // Ordenado da maior média para a menor.
        $this->assertSame('Feminino', $resultado['sexo']['grupos'][0]['valor']);
        $this->assertSame(100.0, $resultado['sexo']['grupos'][0]['media']);
        $this->assertSame(10, $resultado['sexo']['grupos'][0]['respondentes']);
        $this->assertSame(0.0, $resultado['sexo']['grupos'][1]['media']);
    }

    public function test_equidade_nao_devolve_recorte_com_um_unico_grupo(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);

        for ($i = 1; $i <= 12; $i++) {
            Aluno::create(['ra' => (string) $i, 'cpf' => null, 'data_nascimento' => '2000-01-01', 'nome' => 'A'.$i, 'sexo' => 'Feminino']);
            Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => (string) $i, 'questao_numero' => 1, 'resposta' => 'A']);
        }

        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        // Sem um segundo grupo não há comparação possível — o recorte inteiro sai.
        $this->assertArrayNotHasKey('sexo', (new RelatorioAdminService)->equidadeDemografica($avaliacao));
    }
}
