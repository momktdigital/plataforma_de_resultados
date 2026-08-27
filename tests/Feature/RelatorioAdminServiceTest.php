<?php

namespace Tests\Feature;

use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\RelatorioAdminService;
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
}
