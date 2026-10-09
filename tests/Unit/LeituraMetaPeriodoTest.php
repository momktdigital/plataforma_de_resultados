<?php

namespace Tests\Unit;

use App\Support\BloomExplicado;
use App\Support\LeituraMetaPeriodo;
use PHPUnit\Framework\TestCase;

class LeituraMetaPeriodoTest extends TestCase
{
    /** @return array<string, mixed> */
    private function meta(array $sobrescrever = []): array
    {
        return $sobrescrever + [
            'avaliacoes' => [
                ['codigo' => 1, 'nome' => 'Simulado 1', 'percentual' => 55.0, 'minimo' => 80.0, 'comMeta' => true, 'periodoAluno' => 3],
            ],
            'comMeta' => true,
            'areas' => [
                ['area' => 'Cardiologia', 'percentual' => 30.0, 'esperado' => 80.0],
                ['area' => 'Pneumologia', 'percentual' => 80.0, 'esperado' => 80.0],
            ],
            'adiante' => ['total' => 4, 'acertos' => 1],
        ];
    }

    public function test_sem_agregado_nao_ha_texto(): void
    {
        $this->assertSame([], LeituraMetaPeriodo::gerar(null));
        $this->assertSame([], LeituraMetaPeriodo::gerar($this->meta(['avaliacoes' => []])));
    }

    public function test_abaixo_do_minimo_aponta_as_areas_e_as_questoes_a_frente(): void
    {
        $texto = implode(' ', LeituraMetaPeriodo::gerar($this->meta()));

        $this->assertStringContainsString('Você acertou 55% — abaixo do mínimo esperado (80%, para o 3º período)', $texto);
        $this->assertStringContainsString('Cardiologia (acertou 30%, esperado 80%)', $texto);
        $this->assertStringNotContainsString('Pneumologia', $texto);
        $this->assertStringContainsString('4 questão(ões) eram de períodos à frente do seu e você acertou 1', $texto);
        $this->assertStringNotContainsString('pontos', $texto);
        $this->assertStringNotContainsString('turma', $texto);
    }

    public function test_no_minimo_ou_acima_e_considerado_dentro(): void
    {
        $texto = implode(' ', LeituraMetaPeriodo::gerar($this->meta([
            'avaliacoes' => [['codigo' => 1, 'nome' => 'Simulado 1', 'percentual' => 80.0, 'minimo' => 80.0, 'comMeta' => true, 'periodoAluno' => 3]],
            'areas' => [['area' => 'Cardiologia', 'percentual' => 85.0, 'esperado' => 80.0]],
            'adiante' => ['total' => 0, 'acertos' => 0],
        ])));

        $this->assertStringContainsString('no mínimo esperado ou acima dele (80%', $texto);
        $this->assertStringContainsString('Em todas as áreas você ficou dentro do esperado', $texto);
        $this->assertStringNotContainsString('à frente', $texto);
    }

    public function test_varias_avaliacoes_citam_o_nome_de_cada_uma(): void
    {
        $frases = LeituraMetaPeriodo::gerar($this->meta([
            'avaliacoes' => [
                ['codigo' => 1, 'nome' => 'Simulado 1', 'percentual' => 70.0, 'minimo' => 60.0, 'comMeta' => false, 'periodoAluno' => null],
                ['codigo' => 2, 'nome' => 'Simulado 2', 'percentual' => 50.0, 'minimo' => 60.0, 'comMeta' => false, 'periodoAluno' => null],
            ],
            'comMeta' => false,
        ]));

        $this->assertCount(2, $frases);
        $this->assertStringContainsString('Em Simulado 1, você acertou 70% — no mínimo esperado ou acima dele (60%, mínimo padrão', $frases[0]);
        $this->assertStringContainsString('Em Simulado 2, você acertou 50% — abaixo do mínimo esperado (60%', $frases[1]);
    }

    public function test_bloom_explicado_reconhece_o_nivel_pela_raiz_sem_acento_nem_caixa(): void
    {
        foreach (['Aplicação', 'aplicar', '3 - APLICAR'] as $nivel) {
            $this->assertStringContainsString('situação prática', BloomExplicado::para($nivel)['pede'], $nivel);
        }
        $this->assertStringContainsString('relacionar dados de um caso', BloomExplicado::para('Análise')['pede']);
        $this->assertStringContainsString('lembrar', BloomExplicado::para('Conhecimento')['pede']);
        $this->assertStringContainsString('julgar', BloomExplicado::para('Avaliar')['pede']);
        $this->assertStringContainsString('propor', BloomExplicado::para('Criar')['pede']);
        $this->assertNull(BloomExplicado::para('Nível Z'));
    }
}
