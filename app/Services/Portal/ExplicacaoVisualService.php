<?php

namespace App\Services\Portal;

/**
 * Texto de apoio pra cada visual do boletim — o aluno não é analista de
 * dados, então cada gráfico ganha um botão "o que isso significa" com duas
 * partes: uma explicação GENÉRICA (o que aquele tipo de gráfico mede, fixa
 * pra todo mundo) e uma leitura PESSOAL (o que o resultado especificamente
 * desse aluno aponta). Só monta texto em cima de dados já calculados por
 * RelatorioAlunoService/AnaliseConsolidadaService — nenhum cálculo novo.
 */
class ExplicacaoVisualService
{
    /**
     * @param  array<string, mixed>  $analise  $no['analise'] de PortalController::anexarAnaliseNaArvore()
     * @return array<string, array{generico: string, pessoal: ?string}>
     */
    public function gerar(array $analise): array
    {
        return [
            'evolucaoHistorica' => $this->evolucaoHistorica($analise['evolucaoHistorica']),
            'comparativoTurma' => $this->comparativoTurma($analise['comparativoTurma']),
            'curvaDificuldade' => $this->curvaDificuldade($analise['curvaDificuldade']),
            'dispersaoTri' => $this->dispersaoTri($analise['dispersaoTri']),
            'coberturaHabilidade' => $this->coberturaHabilidade($analise['coberturaHabilidade']),
            'bloom' => $this->nivelCognitivo($analise['bloom'], 'bloom'),
            'miller' => $this->nivelCognitivo($analise['miller'], 'miller'),
            'divergentes' => $this->divergentes($analise['divergentes']),
        ];
    }

    /** @param  array<int, array{nome: string, data: string, percentual: float}>  $pontos
     * @return array{generico: string, pessoal: ?string} */
    private function evolucaoHistorica(array $pontos): array
    {
        $generico = 'Mostra seu percentual de acerto em cada avaliação desta categoria, na ordem em que aconteceram — dá pra ver se você está melhorando, piorando ou estável ao longo do tempo.';

        if (count($pontos) < 2) {
            return ['generico' => $generico, 'pessoal' => null];
        }

        $primeiro = $pontos[0];
        $ultimo = $pontos[count($pontos) - 1];
        $delta = round($ultimo['percentual'] - $primeiro['percentual'], 1);

        $pessoal = match (true) {
            $delta > 0 => "Do primeiro para o último resultado nesta categoria, você foi de {$primeiro['percentual']}% para {$ultimo['percentual']}% (+{$delta} pontos) — tendência de melhora.",
            $delta < 0 => "Do primeiro para o último resultado nesta categoria, você foi de {$primeiro['percentual']}% para {$ultimo['percentual']}% ({$delta} pontos) — vale atenção.",
            default => "Seu percentual se manteve em {$ultimo['percentual']}% entre o primeiro e o último resultado nesta categoria.",
        };

        return ['generico' => $generico, 'pessoal' => $pessoal];
    }

    /** @param  array{turma: string, suaMedia: float, mediaTurma: float, avaliacoesComparadas: int}|null  $comparativo
     * @return array{generico: string, pessoal: ?string} */
    private function comparativoTurma(?array $comparativo): array
    {
        $generico = 'Compara sua média nesta categoria com a média de todos os colegas da sua turma que fizeram as mesmas avaliações.';

        if ($comparativo === null) {
            return ['generico' => $generico, 'pessoal' => null];
        }

        $diferenca = round($comparativo['suaMedia'] - $comparativo['mediaTurma'], 1);
        $pessoal = $diferenca >= 0
            ? "Sua média ({$comparativo['suaMedia']}%) está {$diferenca} pontos acima da média da turma {$comparativo['turma']} ({$comparativo['mediaTurma']}%)."
            : "Sua média ({$comparativo['suaMedia']}%) está ".abs($diferenca)." pontos abaixo da média da turma {$comparativo['turma']} ({$comparativo['mediaTurma']}%).";

        return ['generico' => $generico, 'pessoal' => $pessoal];
    }

    /** @param  array<string, array{label: string, percentual: float, respostas: int}>  $curva
     * @return array{generico: string, pessoal: ?string} */
    private function curvaDificuldade(array $curva): array
    {
        $generico = 'Cada questão é classificada pelo professor por dificuldade esperada (fácil, média, difícil). O gráfico mostra seu percentual de acerto real em cada nível — o esperado é acertar mais questões fáceis do que difíceis.';

        $facil = $curva['facil']['percentual'] ?? null;
        $dificil = $curva['dificil']['percentual'] ?? null;

        if ($facil === null || $dificil === null) {
            return ['generico' => $generico, 'pessoal' => null];
        }

        $pessoal = $facil >= $dificil
            ? "Você acertou {$facil}% das questões fáceis e {$dificil}% das difíceis — dentro do esperado."
            : "Você acertou {$facil}% das questões fáceis e {$dificil}% das difíceis — isso foge do padrão comum (normalmente se acerta mais fáceis que difíceis) e pode indicar desatenção nas fáceis, mesmo com domínio do conteúdo mais avançado.";

        return ['generico' => $generico, 'pessoal' => $pessoal];
    }

    /** @param  array<int, array{dificuldade_tri: float, acertou: bool}>  $pontos
     * @return array{generico: string, pessoal: ?string} */
    private function dispersaoTri(array $pontos): array
    {
        $generico = 'Cada ponto é uma questão que você respondeu. A posição no eixo horizontal mostra a dificuldade estatística dela (Teoria de Resposta ao Item, calculada a partir de todos que já a responderam — não só sua turma). Verde é acerto, vermelho é erro; o esperado é ver mais verde à esquerda (fácil) e mais vermelho à direita (difícil).';

        $acertos = array_values(array_filter($pontos, fn ($p) => $p['acertou']));
        $erros = array_values(array_filter($pontos, fn ($p) => ! $p['acertou']));

        if (empty($acertos) || empty($erros)) {
            return ['generico' => $generico, 'pessoal' => null];
        }

        $mediaAcertos = round(array_sum(array_column($acertos, 'dificuldade_tri')) / count($acertos), 2);
        $mediaErros = round(array_sum(array_column($erros, 'dificuldade_tri')) / count($erros), 2);
        $diferenca = round($mediaErros - $mediaAcertos, 2);

        // "Como esperado" só quando a diferença entre as médias é grande o
        // bastante pra sustentar a afirmação — uma diferença de poucos
        // centésimos na escala TRI (tipicamente -2 a 2) não é um padrão,
        // é ruído. Abaixo do limiar, é mais honesto dizer que a diferença
        // não é conclusiva do que forçar uma leitura "como esperado".
        $limiarRelevante = 0.15;

        $fraseMedia = match (true) {
            $diferenca >= $limiarRelevante => "Suas questões corretas tiveram dificuldade estatística média de {$mediaAcertos}, contra {$mediaErros} nas erradas — como esperado, você errou mais nas questões estatisticamente mais difíceis.",
            $diferenca <= -$limiarRelevante => "Suas questões corretas tiveram dificuldade estatística média de {$mediaAcertos}, contra {$mediaErros} nas erradas — um padrão pouco comum (você errou mais nas mais fáceis), vale olhar com atenção quais questões você errou.",
            default => "Suas questões corretas tiveram dificuldade estatística média de {$mediaAcertos} e as erradas {$mediaErros} — uma diferença pequena, que sozinha não é suficiente pra afirmar um padrão claro de erro por dificuldade.",
        };

        // Mesmo quando a média bate com o esperado, erros isolados em
        // questões mais fáceis que a própria média de acerto do aluno são
        // um sinal à parte (lacuna pontual, não "prova difícil") — o
        // benchmark é a média de acerto DELE, não um valor fixo, porque a
        // escala TRI varia de avaliação pra avaliação.
        $errosAbaixoDaMediaDeAcerto = array_values(array_filter($erros, fn ($p) => $p['dificuldade_tri'] < $mediaAcertos));

        if (! empty($errosAbaixoDaMediaDeAcerto)) {
            $qtd = count($errosAbaixoDaMediaDeAcerto);
            $fraseMedia .= " Atenção: {$qtd} questão(ões) errada(s) tinham dificuldade abaixo da sua própria média de acerto ({$mediaAcertos}) — ou seja, mais fáceis do que o padrão que você costuma acertar, o que vale revisar por tema específico.";
        }

        return ['generico' => $generico, 'pessoal' => $fraseMedia];
    }

    /** @param  array<string, float>  $habilidades  já ordenado do pior pro melhor
     * @return array{generico: string, pessoal: ?string} */
    private function coberturaHabilidade(array $habilidades): array
    {
        $generico = 'Mostra seu percentual de acerto em cada habilidade avaliada nesta categoria, da menor para a maior — as barras mais curtas no topo indicam onde vale mais a pena reforçar o estudo.';

        if (empty($habilidades)) {
            return ['generico' => $generico, 'pessoal' => null];
        }

        if (count($habilidades) === 1) {
            $unica = array_key_first($habilidades);

            return ['generico' => $generico, 'pessoal' => "Sua única habilidade avaliada nesta categoria foi \"{$unica}\", com {$habilidades[$unica]}% de acerto."];
        }

        $piorValor = min($habilidades);
        $melhorValor = max($habilidades);

        // array_key_first/array_key_last citavam só UMA habilidade mesmo
        // quando várias empatavam no mesmo valor (ex.: várias a 0%) — o
        // aluno lia "sua pior é X" quando na verdade eram X, Y e Z todas
        // zeradas. Lista todas as que empatam no pior valor (a lista de
        // melhores raramente empata em 100%, então mantém só uma citada).
        $piores = array_keys(array_filter($habilidades, fn ($v) => $v === $piorValor));
        $melhor = array_search($melhorValor, $habilidades, true);

        if ($piorValor === $melhorValor) {
            return ['generico' => $generico, 'pessoal' => 'Todas as suas '.count($habilidades)." habilidades avaliadas nesta categoria tiveram o mesmo aproveitamento: {$piorValor}%."];
        }

        if (count($piores) === 1) {
            $pessoal = "Sua habilidade com menor aproveitamento é \"{$piores[0]}\" ({$piorValor}%); a de maior domínio é \"{$melhor}\" ({$melhorValor}%).";
        } else {
            $nomes = array_map(fn ($h) => "\"{$h}\"", array_slice($piores, 0, 3));
            $listaPiores = implode(', ', $nomes).(count($piores) > 3 ? ' e outras' : '');
            $pessoal = 'Você teve o menor aproveitamento ('.$piorValor.'%) em '.count($piores)." habilidades desta categoria: {$listaPiores}. A de maior domínio é \"{$melhor}\" ({$melhorValor}%).";
        }

        return ['generico' => $generico, 'pessoal' => $pessoal];
    }

    /** @param  array<string, float>  $niveis
     * @return array{generico: string, pessoal: ?string} */
    private function nivelCognitivo(array $niveis, string $tipo): array
    {
        $generico = $tipo === 'bloom'
            ? 'A Taxonomia de Bloom classifica as questões pelo tipo de raciocínio exigido, do mais simples (lembrar um fato) ao mais complexo (criar algo novo a partir do conhecimento). O gráfico mostra seu percentual de acerto em cada nível.'
            : 'A Pirâmide de Miller classifica o nível de competência clínica avaliado, do conhecimento teórico ("sabe") até a aplicação prática ("faz"). O gráfico mostra seu percentual de acerto em cada nível.';

        if (empty($niveis)) {
            return ['generico' => $generico, 'pessoal' => null];
        }

        $piorValor = min($niveis);
        $melhorValor = max($niveis);
        $pior = array_search($piorValor, $niveis, true);
        $melhor = array_search($melhorValor, $niveis, true);

        $pessoal = $pior === $melhor
            ? "Seu desempenho foi de {$piorValor}% no único nível avaliado (\"{$pior}\")."
            : "Seu desempenho é menor no nível \"{$pior}\" ({$piorValor}%) e maior no nível \"{$melhor}\" ({$melhorValor}%).";

        return ['generico' => $generico, 'pessoal' => $pessoal];
    }

    /** @param  array<int, array{area: string, percentualAluno: float, percentualTurma: float, diferenca: float}>  $lista
     * @return array{generico: string, pessoal: ?string} */
    private function divergentes(array $lista): array
    {
        $generico = 'Compara seu percentual de acerto em cada área com o percentual médio de acerto da turma na mesma área — só entram áreas onde você fica pelo menos 10 pontos abaixo da turma (uma diferença menor que essa é ruído, não divergência real), ordenadas pela maior diferença primeiro.';

        if (empty($lista)) {
            return ['generico' => $generico, 'pessoal' => null];
        }

        $top = $lista[0];
        $pessoal = "A área onde você mais fica atrás da turma é \"{$top['area']}\": você acertou {$top['percentualAluno']}%, contra {$top['percentualTurma']}% de média da turma — {$top['diferenca']} pontos de diferença.";

        return ['generico' => $generico, 'pessoal' => $pessoal];
    }
}
