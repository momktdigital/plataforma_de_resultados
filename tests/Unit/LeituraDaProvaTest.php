<?php

namespace Tests\Unit;

use App\Support\LeituraDaProva;
use PHPUnit\Framework\TestCase;

class LeituraDaProvaTest extends TestCase
{
    /** @return array<string, mixed> */
    private function dados(array $sobrescrever = []): array
    {
        return $sobrescrever + [
            'percentual' => 55.0,
            'minimo' => 80.0,
            'comMeta' => true,
            'periodoAluno' => 3,
            'areas' => [
                ['area' => 'Cardiologia', 'percentual' => 30.0, 'meta' => 80.0],
                ['area' => 'Pneumologia', 'percentual' => 90.0, 'meta' => 60.0],
            ],
            'bloom' => [
                'Lembrar' => ['percentual' => 85.0, 'total' => 10],
                'Análise' => ['percentual' => 40.0, 'total' => 8],
            ],
            'adiante' => ['total' => 4, 'acertos' => 1],
            'temTrilha' => true,
        ];
    }

    private function texto(array $leitura): string
    {
        return ($leitura['resumo'] ?? '').' '.implode(' ', $leitura['pontos']);
    }

    public function test_leitura_completa_de_uma_prova_abaixo_do_esperado(): void
    {
        $leitura = LeituraDaProva::gerar($this->dados());

        $this->assertStringContainsString('Seu resultado nesta prova ficou abaixo do esperado: você acertou 55% e, para o 3º período, o esperado era 80%', $leitura['resumo']);
        $texto = implode("\n", $leitura['pontos']);
        $this->assertStringContainsString('Seu melhor rendimento foi em Pneumologia: você acertou 90%', $texto);
        $this->assertStringContainsString('Áreas que merecem mais atenção nos estudos: Cardiologia (você acertou 30% e o esperado era 80%)', $texto);
        $this->assertStringContainsString('As perguntas que pedem para relacionar dados de um caso e separar o que é relevante foram as que tiveram menor índice de acerto', $texto);
        $this->assertStringContainsString('Pratique com casos', $texto);
        $this->assertStringContainsString('Seu melhor rendimento foi nas perguntas que pedem para lembrar conceitos, definições e fatos', $texto);
        $this->assertStringContainsString('(4) tratava de conteúdos de períodos posteriores ao seu, e você acertou 1 delas', $texto);
        $this->assertStringContainsString('consulte a trilha de estudo logo abaixo', $texto);
    }

    public function test_texto_nao_tem_jargao_nem_comparacao_com_a_turma(): void
    {
        $todo = $this->texto(LeituraDaProva::gerar($this->dados()));

        foreach (['Bloom', 'TRI', 'percentil', 'pontos', 'turma', 'taxonomia', 'Análise'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $todo, $proibido);
        }
    }

    public function test_registro_nem_tecnico_nem_coloquial(): void
    {
        $cenarios = [
            $this->dados(),
            $this->dados(['percentual' => 95.0]),
            $this->dados(['percentual' => 72.0]),
            $this->dados(['comMeta' => false, 'minimo' => 60.0, 'percentual' => 30.0]),
            $this->dados(['bloom' => ['Lembrar' => ['percentual' => 95.0, 'total' => 10], 'Aplicar' => ['percentual' => 75.0, 'total' => 10]]]),
        ];

        foreach ($cenarios as $cenario) {
            $texto = $this->texto(LeituraDaProva::gerar($cenario));
            foreach (['trabalho', 'dá para', 'dá pra', ' pra ', 'bônus', 'faltou pouco', 'vai melhor', 'mandou bem', 'tranquil'] as $informal) {
                $this->assertStringNotContainsString($informal, $texto, $informal);
            }
        }
    }

    public function test_abertura_muda_de_tom_conforme_a_distancia_do_esperado(): void
    {
        $abertura = fn (float $p) => LeituraDaProva::gerar($this->dados(['percentual' => $p, 'areas' => [], 'bloom' => [], 'adiante' => ['total' => 0, 'acertos' => 0]]))['resumo'];

        $this->assertStringStartsWith('Seu resultado nesta prova foi muito bom', $abertura(95.0));
        $this->assertStringStartsWith('Seu resultado nesta prova foi bom', $abertura(80.0));
        $this->assertStringStartsWith('Seu resultado nesta prova ficou próximo do esperado', $abertura(72.0));
        $this->assertStringStartsWith('Seu resultado nesta prova ficou abaixo do esperado', $abertura(40.0));
    }

    public function test_sem_meta_a_referencia_e_o_padrao_da_prova(): void
    {
        $resumo = LeituraDaProva::gerar($this->dados(['comMeta' => false, 'minimo' => 60.0, 'percentual' => 70.0]))['resumo'];

        $this->assertStringContainsString('acima da referência de 60%', $resumo);
        $this->assertStringNotContainsString('período', $resumo);
    }

    public function test_sem_nota_visivel_nao_ha_abertura_com_numero(): void
    {
        $leitura = LeituraDaProva::gerar($this->dados(['percentual' => null]));

        $this->assertNull($leitura['resumo']);
        $this->assertNotEmpty($leitura['pontos']);
    }

    public function test_todas_as_areas_dentro_do_esperado(): void
    {
        $leitura = LeituraDaProva::gerar($this->dados([
            'percentual' => 90.0,
            'areas' => [['area' => 'Cardiologia', 'percentual' => 85.0, 'meta' => 80.0], ['area' => 'Pneumologia', 'percentual' => 70.0, 'meta' => 60.0]],
            'bloom' => [],
            'adiante' => ['total' => 0, 'acertos' => 0],
        ]));

        $texto = implode("\n", $leitura['pontos']);
        $this->assertStringContainsString('Em todas as áreas você ficou dentro do esperado', $texto);
        $this->assertStringNotContainsString('trilha de estudo', $texto);
    }

    public function test_tipo_de_pergunta_so_aparece_com_dado_suficiente_e_nome_conhecido(): void
    {
        $semBloom = fn (array $bloom) => ! str_contains(implode(' ', LeituraDaProva::gerar($this->dados(['bloom' => $bloom]))['pontos']), 'tipo de pergunta')
            && ! str_contains(implode(' ', LeituraDaProva::gerar($this->dados(['bloom' => $bloom]))['pontos']), 'pedem para');

        // Poucas questões no pior nível, diferença pequena, um nível só e nome desconhecido: nada a dizer.
        $this->assertTrue($semBloom(['Lembrar' => ['percentual' => 90.0, 'total' => 10], 'Aplicar' => ['percentual' => 0.0, 'total' => 2]]));
        $this->assertTrue($semBloom(['Lembrar' => ['percentual' => 52.0, 'total' => 10], 'Aplicar' => ['percentual' => 48.0, 'total' => 10]]));
        $this->assertTrue($semBloom(['Aplicar' => ['percentual' => 20.0, 'total' => 10]]));
        $this->assertTrue($semBloom(['Lembrar' => ['percentual' => 90.0, 'total' => 10], 'Nível Z' => ['percentual' => 30.0, 'total' => 10]]));
    }

    public function test_bom_em_todos_os_tipos_de_pergunta(): void
    {
        $texto = implode(' ', LeituraDaProva::gerar($this->dados(['bloom' => [
            'Lembrar' => ['percentual' => 95.0, 'total' => 10],
            'Aplicar' => ['percentual' => 75.0, 'total' => 10],
        ]]))['pontos']);

        $this->assertStringContainsString('Seu rendimento foi bom em todos os tipos de pergunta', $texto);
    }
}
