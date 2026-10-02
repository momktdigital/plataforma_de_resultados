<?php

namespace Tests\Unit;

use App\Services\CoordenadorAlunosService;
use PHPUnit\Framework\TestCase;

/**
 * Regra de situação do aluno (pura, sem banco): o que faz o coordenador olhar para ele.
 */
class CoordenadorAlunosClassificacaoTest extends TestCase
{
    /** @param array<string, mixed> $sobrescrever */
    private function metricas(array $sobrescrever = []): array
    {
        return [...[
            'inscritos' => 4, 'presentes' => 4, 'faltas' => 0, 'presenca' => 100.0, 'media' => 70.0,
            'abaixo' => 0, 'ultima' => null, 'tendencia' => null,
        ], ...$sobrescrever];
    }

    public function test_sem_nenhuma_avaliacao_e_sem_resultado(): void
    {
        [$situacao] = CoordenadorAlunosService::classificar($this->metricas(['inscritos' => 0, 'presentes' => 0, 'media' => null]));

        $this->assertSame('sem_resultado', $situacao);
    }

    public function test_faltou_em_tudo_e_ausente(): void
    {
        [$situacao, $motivos] = CoordenadorAlunosService::classificar($this->metricas(['presentes' => 0, 'faltas' => 4, 'media' => null]));

        $this->assertSame('ausente', $situacao);
        $this->assertStringContainsString('todas as 4', $motivos[0]);
    }

    public function test_media_abaixo_de_60_e_atencao_e_exatos_60_nao(): void
    {
        $this->assertSame('atencao', CoordenadorAlunosService::classificar($this->metricas(['media' => 59.9]))[0]);
        $this->assertSame('regular', CoordenadorAlunosService::classificar($this->metricas(['media' => 60.0]))[0]);
    }

    public function test_duas_faltas_ou_mais_e_atencao_uma_nao(): void
    {
        $this->assertSame('regular', CoordenadorAlunosService::classificar($this->metricas(['faltas' => 1, 'presentes' => 3]))[0]);

        [$situacao, $motivos] = CoordenadorAlunosService::classificar($this->metricas(['faltas' => 2, 'presentes' => 2]));
        $this->assertSame('atencao', $situacao);
        $this->assertSame('2 faltas em 4 avaliações.', $motivos[0]);
    }

    public function test_queda_de_20_pontos_e_atencao_mesmo_com_media_boa(): void
    {
        [$situacao, $motivos] = CoordenadorAlunosService::classificar($this->metricas(['media' => 75.0, 'tendencia' => ['delta' => -20.0, 'de' => 95.0, 'para' => 75.0]]));

        $this->assertSame('atencao', $situacao);
        $this->assertSame('Queda de 20 pontos na última avaliação.', $motivos[0]);

        // Variação pequena é ruído de uma prova para outra, não alerta.
        $this->assertSame('regular', CoordenadorAlunosService::classificar($this->metricas(['media' => 75.0, 'tendencia' => ['delta' => -19.9, 'de' => 95.0, 'para' => 75.1]]))[0]);
    }

    public function test_destaque_exige_media_alta_e_nenhuma_falta(): void
    {
        $this->assertSame('destaque', CoordenadorAlunosService::classificar($this->metricas(['media' => 80.0]))[0]);
        $this->assertSame('regular', CoordenadorAlunosService::classificar($this->metricas(['media' => 79.9]))[0]);
        $this->assertSame('regular', CoordenadorAlunosService::classificar($this->metricas(['media' => 95.0, 'faltas' => 1, 'presentes' => 3]))[0]);
    }

    public function test_motivos_se_acumulam(): void
    {
        [$situacao, $motivos] = CoordenadorAlunosService::classificar($this->metricas([
            'media' => 40.0, 'faltas' => 2, 'presentes' => 2, 'tendencia' => ['delta' => -20.0, 'de' => 60.0, 'para' => 40.0],
        ]));

        $this->assertSame('atencao', $situacao);
        $this->assertCount(3, $motivos);
    }
}
