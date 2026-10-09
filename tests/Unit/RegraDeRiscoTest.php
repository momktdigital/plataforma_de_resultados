<?php

namespace Tests\Unit;

use App\Services\CoordenadorAlunosService;
use App\Support\RegraDeRisco;
use PHPUnit\Framework\TestCase;

/**
 * A regra de "estudante em risco" (pura, sem banco): critérios, operador e a classificação do coordenador com ela.
 */
class RegraDeRiscoTest extends TestCase
{
    /** @param array<string, mixed> $sobrescrever */
    private function metricas(array $sobrescrever = []): array
    {
        return [...[
            'inscritos' => 4, 'presentes' => 4, 'faltas' => 0, 'presenca' => 100.0, 'media' => 70.0,
            'abaixo' => 0, 'ultima' => null, 'tendencia' => null,
        ], ...$sobrescrever];
    }

    public function test_padrao_reproduz_o_que_o_painel_fazia_antes(): void
    {
        $regra = RegraDeRisco::padrao();

        $this->assertSame(60.0, $regra->acerto);
        $this->assertSame(2, $regra->faltas);
        $this->assertSame('ou', $regra->operador);
        $this->assertSame('média de acerto abaixo de 60% ou 2 faltas ou mais', $regra->descricao());
    }

    public function test_descricao_de_cada_combinacao(): void
    {
        $this->assertSame('média de acerto abaixo de 62,5%', (new RegraDeRisco(62.5, null))->descricao());
        $this->assertSame('1 falta', (new RegraDeRisco(null, 1))->descricao());
        $this->assertSame('3 faltas ou mais', (new RegraDeRisco(null, 3))->descricao());
        $this->assertSame('média de acerto abaixo de 50% e 2 faltas ou mais', (new RegraDeRisco(50.0, 2, 'e'))->descricao());
        $this->assertSame('nenhum critério definido', (new RegraDeRisco(null, null))->descricao());
        $this->assertFalse((new RegraDeRisco(null, null))->algumCriterio());
    }

    public function test_combinar_com_ou_e_com_e(): void
    {
        $ou = new RegraDeRisco(60.0, 2, 'ou');
        $e = new RegraDeRisco(60.0, 2, 'e');

        $this->assertTrue($ou->combinar([true, false]));
        $this->assertFalse($ou->combinar([false, false]));
        $this->assertFalse($e->combinar([true, false]));
        $this->assertTrue($e->combinar([true, true]));
        $this->assertTrue($e->combinar([true]));          // só um critério ligado: vale ele
        $this->assertFalse($ou->combinar([]));            // sem critério ligado, ninguém está em risco
    }

    public function test_limite_da_avaliacao_sobrepoe_o_padrao(): void
    {
        $regra = new RegraDeRisco(60.0, 2);

        $this->assertSame(60.0, $regra->limiteDaAvaliacao(null));   // sem limite próprio: o padrão
        $this->assertSame(45.0, $regra->limiteDaAvaliacao(45.0));
        $this->assertNull($regra->limiteDaAvaliacao(0.0));          // 0 = a prova sai do critério de acerto
        $this->assertSame(45.0, (new RegraDeRisco(null, 2))->limiteDaAvaliacao(45.0)); // pode ligar o acerto só nela
        $this->assertNull((new RegraDeRisco(null, 2))->limiteDaAvaliacao(null));
    }

    public function test_classificar_com_so_o_criterio_de_acerto_ignora_as_faltas(): void
    {
        $regra = new RegraDeRisco(60.0, null);

        $this->assertSame('regular', CoordenadorAlunosService::classificar($this->metricas(['faltas' => 3, 'presentes' => 1, 'media' => 70.0]), $regra)[0]);
        $this->assertSame('atencao', CoordenadorAlunosService::classificar($this->metricas(['media' => 50.0]), $regra)[0]);
    }

    public function test_classificar_com_so_o_criterio_de_faltas_ignora_a_media(): void
    {
        $regra = new RegraDeRisco(null, 3);

        $this->assertSame('regular', CoordenadorAlunosService::classificar($this->metricas(['media' => 10.0]), $regra)[0]);
        $this->assertSame('regular', CoordenadorAlunosService::classificar($this->metricas(['faltas' => 2, 'presentes' => 2, 'media' => 70.0]), $regra)[0]);

        [$situacao, $motivos] = CoordenadorAlunosService::classificar($this->metricas(['faltas' => 3, 'presentes' => 1, 'media' => 70.0]), $regra);
        $this->assertSame('atencao', $situacao);
        $this->assertSame('3 faltas em 4 avaliações.', $motivos[0]);
    }

    public function test_classificar_com_o_operador_e_pede_os_dois(): void
    {
        $regra = new RegraDeRisco(60.0, 2, 'e');

        $soAcerto = CoordenadorAlunosService::classificar($this->metricas(['media' => 50.0]), $regra);
        $soFalta = CoordenadorAlunosService::classificar($this->metricas(['faltas' => 2, 'presentes' => 2, 'media' => 70.0]), $regra);
        $osDois = CoordenadorAlunosService::classificar($this->metricas(['faltas' => 2, 'presentes' => 2, 'media' => 50.0]), $regra);

        $this->assertSame('regular', $soAcerto[0]);
        $this->assertSame('regular', $soFalta[0]);
        $this->assertSame('atencao', $osDois[0]);
        $this->assertCount(2, $osDois[1]);
    }

    public function test_a_queda_continua_sendo_um_sinal_a_parte_mesmo_com_o_operador_e(): void
    {
        $regra = new RegraDeRisco(60.0, 2, 'e');

        [$situacao, $motivos] = CoordenadorAlunosService::classificar($this->metricas(['media' => 75.0, 'tendencia' => ['delta' => -25.0, 'de' => 100.0, 'para' => 75.0]]), $regra);

        $this->assertSame('atencao', $situacao);
        $this->assertStringContainsString('Queda de 25', $motivos[0]);
    }

    public function test_classificar_usa_os_numeros_da_regra_por_avaliacao_quando_existem(): void
    {
        $regra = new RegraDeRisco(60.0, 2, 'ou');

        // média geral 55, mas as provas que contam para o acerto (limite próprio 50) deram média 52: não está abaixo
        $this->assertSame('regular', CoordenadorAlunosService::classificar($this->metricas([
            'media' => 55.0, 'acertoAtivo' => true, 'acertoAbaixo' => false, 'mediaRisco' => 52.0, 'limiteMedio' => 50.0, 'faltasRisco' => 0,
        ]), $regra)[0]);

        [$situacao, $motivos] = CoordenadorAlunosService::classificar($this->metricas([
            'media' => 55.0, 'acertoAtivo' => true, 'acertoAbaixo' => true, 'mediaRisco' => 48.0, 'limiteMedio' => 50.0, 'faltasRisco' => 0,
        ]), $regra);
        $this->assertSame('atencao', $situacao);
        $this->assertSame('Média de 48% (abaixo de 50%).', $motivos[0]);

        // falta dispensada em uma avaliação: 2 faltas no total, mas só 1 conta
        $this->assertSame('regular', CoordenadorAlunosService::classificar($this->metricas(['faltas' => 2, 'presentes' => 2, 'faltasRisco' => 1]), $regra)[0]);
    }
}
