<?php

namespace App\Support;

/**
 * "Como você foi nesta prova": a leitura em texto, na linguagem do estudante, mostrada no topo das análises do detalhe
 * de uma avaliação do boletim. Junta o resultado geral contra o esperado para o período dele, as áreas de melhor e pior
 * rendimento, o tipo de pergunta de menor acerto (Bloom, sem falar "Bloom") e as questões de períodos à frente.
 *
 * Texto puro, sem cálculo novo e sem comparação com a turma. Registro claro e cordial, nem técnico nem coloquial (nada de
 * "deu trabalho", "dá para", "bônus", "pra"). Nada de jargão: não aparecem "Bloom", "TRI", "percentil",
 * "pontos percentuais" nem o nome técnico de um nível — só o que a pergunta pede ("lembrar conceitos", "usar o conteúdo
 * numa situação prática"...). Um nível que não reconhecemos (BloomExplicado::para() devolve null) simplesmente não é citado.
 */
final class LeituraDaProva
{
    /** Quanto acima/abaixo do esperado (em % de acerto) muda o tom da frase de abertura. */
    private const MARGEM = 10.0;

    /** Um tipo de pergunta só vale como "o mais difícil" com questões suficientes e diferença que não seja ruído. */
    private const MINIMO_QUESTOES_BLOOM = 3;

    private const DIFERENCA_BLOOM = 8.0;

    private const MAXIMO_AREAS_A_REFORCAR = 2;

    /**
     * `percentual` null = o aluno não pode ver a nota geral (a abertura é omitida); `areas`/`bloom` vazios = o aluno não
     * pode ver ou não há dado.
     *
     * @param  array{percentual: ?float, minimo: float, comMeta: bool, periodoAluno: ?int, areas: array<int, array{area: string, percentual: float, meta: float}>, bloom: array<string, array{percentual: float, total: int}>, adiante: array{total: int, acertos: int}, temTrilha: bool}  $dados
     * @return array{resumo: ?string, pontos: array<int, string>}
     */
    public static function gerar(array $dados): array
    {
        $areas = $dados['areas'];
        $aReforcar = array_values(array_filter($areas, fn ($a) => $a['percentual'] < $a['meta']));
        usort($aReforcar, fn ($a, $b) => ($a['percentual'] - $a['meta']) <=> ($b['percentual'] - $b['meta']));

        $pontos = [];

        $pontos = [...$pontos, ...self::frasesDeAreas($areas, $aReforcar)];

        $bloom = self::fraseDeTipoDePergunta($dados['bloom']);
        if ($bloom !== null) {
            $pontos[] = $bloom;
        }

        $adiante = $dados['adiante'];
        if ($adiante['total'] > 0) {
            $pontos[] = "Parte das perguntas ({$adiante['total']}) tratava de conteúdos de períodos posteriores ao seu, e você acertou {$adiante['acertos']} delas. "
                .'Esses conteúdos ainda não são exigidos neste momento do curso: os erros nelas não indicam lacuna, e os acertos são um ganho adicional.';
        }

        $resumo = $dados['percentual'] === null ? null : self::abertura($dados);

        $precisaDeCaminho = $aReforcar !== [] || ($dados['percentual'] !== null && $dados['percentual'] < $dados['minimo']);
        if ($dados['temTrilha'] && $precisaDeCaminho) {
            $pontos[] = 'Para saber por onde iniciar os estudos, consulte a trilha de estudo logo abaixo.';
        }

        return ['resumo' => $resumo, 'pontos' => $pontos];
    }

    /** @param  array<string, mixed>  $d */
    private static function abertura(array $d): string
    {
        $p = $d['percentual'];
        $min = $d['minimo'];

        if ($d['comMeta'] && $d['periodoAluno'] !== null) {
            $periodo = PeriodoCurso::rotulo($d['periodoAluno']);

            return match (true) {
                $p >= $min + self::MARGEM => "Seu resultado nesta prova foi muito bom: você acertou {$p}%, acima do esperado para quem está no {$periodo}.",
                $p >= $min => "Seu resultado nesta prova foi bom: você acertou {$p}%, dentro do esperado para quem está no {$periodo}.",
                $p >= $min - self::MARGEM => "Seu resultado nesta prova ficou próximo do esperado: você acertou {$p}% e, para o {$periodo}, o esperado era {$min}%.",
                default => "Seu resultado nesta prova ficou abaixo do esperado: você acertou {$p}% e, para o {$periodo}, o esperado era {$min}%. Com estudo direcionado, é possível recuperar — veja abaixo por onde começar.",
            };
        }

        return match (true) {
            $p >= $min + self::MARGEM => "Seu resultado nesta prova foi muito bom: você acertou {$p}%, bem acima da referência de {$min}%.",
            $p >= $min => "Seu resultado nesta prova foi bom: você acertou {$p}%, acima da referência de {$min}%.",
            $p >= $min - self::MARGEM => "Seu resultado nesta prova ficou próximo da referência de {$min}%: você acertou {$p}%.",
            default => "Seu resultado nesta prova ficou abaixo da referência de {$min}%: você acertou {$p}%. Com estudo direcionado, é possível recuperar — veja abaixo por onde começar.",
        };
    }

    /**
     * @param  array<int, array{area: string, percentual: float, meta: float}>  $areas
     * @param  array<int, array{area: string, percentual: float, meta: float}>  $aReforcar  já do mais distante da meta para o mais próximo
     * @return array<int, string>
     */
    private static function frasesDeAreas(array $areas, array $aReforcar): array
    {
        if ($areas === []) {
            return [];
        }

        $frases = [];

        // Ponto forte: só com mais de uma área (com uma só, "a melhor" é a única) e só se de fato chegou na meta.
        if (count($areas) >= 2) {
            $melhor = $areas[0];
            foreach ($areas as $area) {
                if ($area['percentual'] > $melhor['percentual']) {
                    $melhor = $area;
                }
            }
            if ($melhor['percentual'] >= $melhor['meta']) {
                $frases[] = "Seu melhor rendimento foi em {$melhor['area']}: você acertou {$melhor['percentual']}% das perguntas dessa área.";
            }
        }

        if ($aReforcar === []) {
            $frases[] = 'Em todas as áreas você ficou dentro do esperado.';

            return $frases;
        }

        $itens = array_map(
            fn ($a) => "{$a['area']} (você acertou {$a['percentual']}% e o esperado era {$a['meta']}%)",
            array_slice($aReforcar, 0, self::MAXIMO_AREAS_A_REFORCAR)
        );
        $frases[] = 'Áreas que merecem mais atenção nos estudos: '.implode(' e ', $itens).'.';

        return $frases;
    }

    /**
     * Traduz o tipo de pergunta de menor acerto (e o de maior) em "perguntas que pedem para...". Sem jargão.
     *
     * @param  array<string, array{percentual: float, total: int}>  $bloom
     */
    private static function fraseDeTipoDePergunta(array $bloom): ?string
    {
        $niveis = array_filter($bloom, fn ($n) => $n['total'] >= self::MINIMO_QUESTOES_BLOOM);
        if (count($niveis) < 2) {
            return null;
        }

        $percentuais = array_map(fn ($n) => $n['percentual'], $niveis);
        $pior = (string) array_search(min($percentuais), $percentuais, true);
        $melhor = (string) array_search(max($percentuais), $percentuais, true);

        if (max($percentuais) - min($percentuais) < self::DIFERENCA_BLOOM) {
            return null;
        }

        if (min($percentuais) >= 70.0) {
            return 'Seu rendimento foi bom em todos os tipos de pergunta, dos que pedem para lembrar aos que pedem para aplicar o conteúdo.';
        }

        $doPior = BloomExplicado::para($pior);
        if ($doPior === null) {
            return null;
        }

        $frase = "As perguntas que pedem para {$doPior['pede']} foram as que tiveram menor índice de acerto. {$doPior['dica']}";

        $doMelhor = BloomExplicado::para($melhor);
        if ($doMelhor !== null && $melhor !== $pior && $doMelhor['pede'] !== $doPior['pede']) {
            $frase .= " Seu melhor rendimento foi nas perguntas que pedem para {$doMelhor['pede']}.";
        }

        return $frase;
    }
}
