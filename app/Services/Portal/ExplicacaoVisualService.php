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

        $pessoal = $mediaErros > $mediaAcertos
            ? "Suas questões corretas tiveram dificuldade estatística média de {$mediaAcertos}, contra {$mediaErros} nas erradas — como esperado, você errou mais nas questões estatisticamente mais difíceis."
            : "Suas questões corretas tiveram dificuldade estatística média de {$mediaAcertos}, contra {$mediaErros} nas erradas — um padrão pouco comum, vale olhar com atenção quais questões você errou.";

        return ['generico' => $generico, 'pessoal' => $pessoal];
    }

    /** @param  array<string, float>  $habilidades  já ordenado do pior pro melhor
     * @return array{generico: string, pessoal: ?string} */
    private function coberturaHabilidade(array $habilidades): array
    {
        $generico = 'Mostra seu percentual de acerto em cada habilidade avaliada nesta categoria, da menor para a maior — as barras mais curtas no topo indicam onde vale mais a pena reforçar o estudo.';

        if (empty($habilidades)) {
            return ['generico' => $generico, 'pessoal' => null];
        }

        $pior = array_key_first($habilidades);
        $melhor = array_key_last($habilidades);

        $pessoal = $pior === $melhor
            ? "Sua única habilidade avaliada nesta categoria foi \"{$pior}\", com {$habilidades[$pior]}% de acerto."
            : "Sua habilidade com menor aproveitamento é \"{$pior}\" ({$habilidades[$pior]}%); a de maior domínio é \"{$melhor}\" ({$habilidades[$melhor]}%).";

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

    /** @param  array<int, array{area: string, tema: string, ocorrencias: int, taxaAcertoTurmaMedia: float}>  $lista
     * @return array{generico: string, pessoal: ?string} */
    private function divergentes(array $lista): array
    {
        $generico = 'Lista temas em que você errou questões que a maioria da turma acertou — isso costuma indicar uma lacuna específica de conteúdo, não só uma prova difícil pra todo mundo.';

        if (empty($lista)) {
            return ['generico' => $generico, 'pessoal' => null];
        }

        $top = $lista[0];
        $pessoal = "O tema onde isso mais aconteceu foi \"{$top['tema']}\" (área {$top['area']}), em {$top['ocorrencias']} questão(ões) — enquanto {$top['taxaAcertoTurmaMedia']}% da turma acertou, você errou.";

        return ['generico' => $generico, 'pessoal' => $pessoal];
    }
}
