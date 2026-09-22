<?php

namespace App\Services;

use App\Support\Psicometria;

/**
 * Explicação de cada visual do painel BI: o que aquele gráfico mede e como
 * lê-lo (texto fixo, igual para toda avaliação) mais a LEITURA do resultado
 * que está na tela agora — equivalente administrativo de
 * App\Services\Portal\ExplicacaoVisualService, que faz o mesmo no boletim.
 *
 * Nenhum cálculo novo acontece aqui: o serviço só redige por cima dos
 * agregados que o BiController já montou. E a redação tem limiares de
 * honestidade (ver VARIACAO_RELEVANTE): afirmar padrão em cima de uma
 * diferença de 2 pontos entre duas turmas é pior do que não dizer nada,
 * porque vira decisão pedagógica tomada sobre ruído.
 */
class ExplicacaoBiService
{
    /** Diferença mínima (em pontos percentuais) para o texto afirmar que dois grupos diferem. */
    private const VARIACAO_RELEVANTE = 10.0;

    /**
     * @param  array<string, mixed>  $ctx  os mesmos dados que o BiController manda pra view
     * @return array<string, array{generico: string, leitura: ?string, tom: ?string}>
     */
    public function gerar(array $ctx): array
    {
        $psicometria = $ctx['psicometria'] ?? null;

        return [
            // Um cartão de KPI é um visual: cada número tem leitura própria.
            'kpi_media' => $this->kpiMedia($psicometria),
            'kpi_mediana' => $this->kpiMediana($psicometria),
            'kpi_desvio' => $this->kpiDesvio($psicometria),
            'kpi_kr20' => $this->kpiKr20($psicometria),

            'mapa_itens' => $this->mapaItens($psicometria),
            // A leitura sai daqui vazia de propósito: ela fala da questão
            // selecionada, que muda a cada clique, então quem a escreve é o JS
            // do painel (ver escreverExplicacaoCci() em bi.blade.php).
            'curva_caracteristica' => $this->entrada(
                'A curva mostra o percentual de acerto da questão selecionada em cada quinto de desempenho geral: '
                .'à esquerda os respondentes que foram pior na prova inteira, à direita os que foram melhor. '
                .'Uma questão saudável tem curva SUBINDO — quem sabe mais acerta mais. Curva plana significa que a '
                .'questão não distingue ninguém; curva DESCENDO é o pior caso, porque quem domina o conteúdo está '
                .'errando justamente ela, o que quase sempre é gabarito errado ou enunciado ambíguo.',
                null,
            ),
            'histograma' => $this->histograma($ctx['bi'] ?? []),
            'radar_disciplina' => $this->porCampo(
                $ctx['bi']['radar'] ?? [],
                'Média de acerto em cada disciplina da matriz curricular, numa teia. Cada ponta é uma disciplina; '
                .'quanto mais longe do centro, melhor o desempenho. O formato importa mais que o tamanho: uma teia '
                .'redonda quer dizer domínio parelho, uma teia "amassada" de um lado mostra onde o curso está devendo.',
                'disciplina',
            ),
            'desempenho_area' => $this->porCampo(
                $ctx['mediaPorArea'] ?? [],
                'Média de acerto por grande área do conhecimento. Serve para responder onde a turma vai bem e onde vai '
                .'mal num nível mais amplo que o de disciplina — é o recorte que costuma virar decisão de reforço e '
                .'de carga horária.',
                'área',
            ),
            'desempenho_bloom' => $this->porCampo(
                $ctx['mediaPorBloom'] ?? [],
                'Média de acerto por nível da taxonomia de Bloom (lembrar, entender, aplicar, analisar, avaliar, criar). '
                .'O esperado é a barra cair conforme o nível cognitivo sobe: acertar mais no "lembrar" que no "analisar". '
                .'Quando não cai, ou as questões não estão classificadas no nível que realmente exigem, ou a turma decora '
                .'sem aplicar.',
                'nível de Bloom',
            ),
            'desempenho_miller' => $this->porCampo(
                $ctx['mediaPorMiller'] ?? [],
                'Média de acerto por nível da pirâmide de Miller (sabe, sabe como, mostra como, faz). Mede o quanto a '
                .'avaliação alcança a prática, não só o conhecimento teórico. Prova escrita raramente chega ao "faz" — '
                .'se esse nível aparecer aqui com poucas questões, leia com cuidado.',
                'nível de Miller',
            ),
            'desempenho_tema' => $this->desempenhoTema($ctx['desempenhoPorTema'] ?? []),
            'ranking_completo' => $this->ranking($ctx['ranking'] ?? []),
            'distribuicao_turma' => $this->distribuicaoTurma($ctx['distribuicaoTurma'] ?? []),
            'curva_dificuldade' => $this->curvaDificuldade($ctx['curvaDificuldade'] ?? []),
            'dispersao_tri' => $this->dispersaoTri($ctx['dispersaoTri'] ?? []),
            'heatmap_habilidade_turma' => $this->heatmap($ctx['heatmap'] ?? []),
            'perfil_sexo' => $this->perfilComposicao($ctx['perfilDemografico']['sexo'] ?? [], 'sexo'),
            'perfil_cor_raca' => $this->perfilComposicao($ctx['perfilDemografico']['cor_raca'] ?? [], 'cor/raça'),
            'perfil_uf' => $this->perfilUf($ctx['perfilDemografico']['uf'] ?? []),
            'analise_alternativas' => $this->analiseAlternativas($ctx['analiseAlternativas'] ?? []),
            'correlacao_metricas' => $this->correlacaoMetricas($ctx['correlacaoMetricas'] ?? []),
            'evolucao_categoria' => $this->evolucaoCategoria($ctx['evolucaoCategoria'] ?? []),
            'alinhamento_referencias' => $this->alinhamento($ctx['alinhamento'] ?? [], $ctx['psicometria']['media'] ?? null),
            'equidade_demografica' => $this->equidade($ctx['equidade'] ?? []),
            'comparacao_avaliacoes' => $this->comparacaoAvaliacoes($ctx['comparacao'] ?? null),
        ];
    }

    /** @param array<string, mixed>|null $psicometria */
    private function kpiMedia(?array $psicometria): array
    {
        $generico = 'O percentual médio de acerto de todos os respondentes. Sozinha, a média engana: ela é puxada por '
            .'notas extremas, então sempre compare com a mediana logo ao lado. Não existe média "certa" — o que se '
            .'espera depende do tipo de prova. Numa avaliação diagnóstica, média baixa é esperada e informativa; numa '
            .'prova de aprovação, média baixa é sinal de conteúdo não aprendido ou de prova mal calibrada.';

        if ($psicometria === null) {
            return $this->entrada($generico, null);
        }

        $media = $psicometria['media'];
        $questoes = $psicometria['questoes'] ?? null;
        $respondentes = $psicometria['respondentes'] ?? null;
        $leitura = 'A turma acertou em média '.$this->num($media).'%'
            .($questoes === null ? '' : ' das '.$questoes.' questões válidas')
            .($respondentes === null ? '' : ' ('.$respondentes.' respondentes)').'. ';

        [$complemento, $tom] = match (true) {
            $media < 40 => ['É um resultado baixo: vale conferir se o conteúdo cobrado foi de fato trabalhado antes da prova.', 'ruim'],
            $media < 60 => ['Fica na faixa intermediária — normal em avaliação diagnóstica, preocupante em prova de aprovação.', 'atencao'],
            $media > 85 => ['É um resultado alto: a prova pode ter sido fácil demais para distinguir os respondentes entre si.', 'atencao'],
            default => ['É um patamar saudável para uma prova que precisa diferenciar quem domina o conteúdo de quem não domina.', 'bom'],
        };

        return $this->entrada($generico, $leitura.$complemento, $tom);
    }

    /** @param array<string, mixed>|null $psicometria */
    private function kpiMediana(?array $psicometria): array
    {
        $generico = 'O valor que divide a turma ao meio: metade ficou acima, metade abaixo. Diferente da média, a '
            .'mediana não se mexe por causa de poucas notas extremas. A comparação entre as duas é que informa: se a '
            .'média está bem ABAIXO da mediana, existe um grupo de notas muito baixas puxando o resultado; se está bem '
            .'ACIMA, poucos respondentes muito bons estão levantando a média do grupo.';

        if ($psicometria === null) {
            return $this->entrada($generico, null);
        }

        $distancia = $psicometria['media'] - $psicometria['mediana'];

        if (abs($distancia) < 5) {
            return $this->entrada(
                $generico,
                'Mediana de '.$this->num($psicometria['mediana']).'%, praticamente colada na média ('
                .$this->num($psicometria['media']).'%): a distribuição é equilibrada e a média representa bem a turma.',
                'bom',
            );
        }

        return $this->entrada(
            $generico,
            $distancia < 0
                ? 'A mediana ('.$this->num($psicometria['mediana']).'%) está '.$this->num(abs($distancia)).' pontos ACIMA da média: há um grupo de notas muito baixas puxando a média para baixo. Olhe o fim do ranking para achar quem precisa de acompanhamento.'
                : 'A mediana ('.$this->num($psicometria['mediana']).'%) está '.$this->num($distancia).' pontos ABAIXO da média: poucos respondentes com notas altas estão levantando a média, que portanto não representa o respondente típico.',
            'atencao',
        );
    }

    /** @param array<string, mixed>|null $psicometria */
    private function kpiDesvio(?array $psicometria): array
    {
        $generico = 'O quanto as notas se espalham em torno da média, em pontos percentuais. Desvio pequeno significa '
            .'turma homogênea — todo mundo perto da média. Desvio grande significa turma dividida, e aí a média deixa '
            .'de descrever alguém de verdade. Atenção: desvio muito baixo numa prova longa costuma indicar que ela não '
            .'está conseguindo diferenciar os respondentes, o que também aparece num KR-20 baixo.';

        if ($psicometria === null) {
            return $this->entrada($generico, null);
        }

        $desvio = $psicometria['desvio'];
        $media = $psicometria['media'];

        [$complemento, $tom] = match (true) {
            $desvio < 8 => ['A turma está muito homogênea: quase todo mundo tirou perto de '.$this->num($media).'%. Confira o KR-20 — prova que não espalha notas costuma não discriminar.', 'atencao'],
            $desvio > 20 => ['A turma está bastante dividida: a média de '.$this->num($media).'% não descreve o respondente típico, e vale olhar a distribuição de acertos para ver se há dois grupos distintos.', 'atencao'],
            default => ['É uma dispersão saudável: a prova separou os respondentes sem partir a turma em dois extremos.', 'bom'],
        };

        return $this->entrada($generico, 'Desvio-padrão de '.$this->num($desvio).' pontos percentuais. '.$complemento, $tom);
    }

    /** @param array<string, mixed>|null $psicometria */
    private function kpiKr20(?array $psicometria): array
    {
        $generico = 'A confiabilidade da prova, de 0 a 1: o quanto as questões estão medindo a mesma coisa. Se as '
            .'questões concordam entre si, quem sabe acerta em várias e quem não sabe erra em várias — e aí a nota '
            .'reflete conhecimento, não sorte. A referência usual: acima de 0,80 é adequado para decisões sobre o '
            .'aluno; entre 0,70 e 0,80 dá para usar com cautela; abaixo de 0,70 boa parte do resultado é acaso. '
            .'Prova curta tende a ter KR-20 menor, então leia junto com o número de questões.';

        if ($psicometria === null) {
            return $this->entrada($generico, null);
        }

        if ($psicometria['kr20'] === null) {
            return $this->entrada(
                $generico,
                'Não dá para calcular: as notas praticamente não variam entre os respondentes, e sem variação o coeficiente é indefinido.',
                'atencao',
            );
        }

        $kr = $psicometria['kr20'];
        $valor = $this->num($kr, 2);

        [$leitura, $tom] = match (true) {
            $kr >= 0.80 => ["KR-20 de {$valor}: a prova tem consistência interna adequada e pode embasar decisões sobre o aluno.", 'bom'],
            $kr >= 0.70 => ["KR-20 de {$valor}: aceitável, mas dá para melhorar. Veja no mapa de itens quais questões não estão discriminando — trocá-las é o caminho mais curto para subir esse número.", 'atencao'],
            default => ["KR-20 de {$valor}: baixo. Boa parte do resultado desta prova é acaso, então evite usá-la sozinha para aprovar, reprovar ou classificar aluno. O mapa de itens mostra por onde começar a correção.", 'ruim'],
        };

        return $this->entrada($generico, $leitura, $tom);
    }

    /** @param array<string, mixed>|null $psicometria */
    private function mapaItens(?array $psicometria): array
    {
        $generico = 'Cada ponto é uma questão. O eixo horizontal é quanto a turma acertou (dificuldade observada) e o '
            .'vertical é a discriminação: o quanto aquela questão separa quem foi bem de quem foi mal na prova inteira. '
            .'Leia de baixo para cima — a faixa vermelha embaixo é onde mora o problema. Discriminação negativa é o sinal '
            .'mais grave: quem acertou o resto da prova errou justamente aquela, o que quase sempre é gabarito errado ou '
            .'enunciado ambíguo. Questão muito fácil (perto de 100%) também não discrimina, mas costuma estar ali de '
            .'propósito, para abrir a prova.';

        if ($psicometria === null || empty($psicometria['itens'])) {
            return $this->entrada($generico, null);
        }

        $itens = collect($psicometria['itens']);
        $revisar = $itens->where('faixa', Psicometria::FAIXA_REVISAR);
        $negativas = $itens->filter(fn ($i) => $i['discriminacao'] !== null && $i['discriminacao'] < 0);

        if ($revisar->isEmpty()) {
            return $this->entrada($generico, 'Nenhuma questão caiu na faixa de revisão — todos os itens desta prova separam quem sabe de quem não sabe.', 'bom');
        }

        $partes = [$revisar->count() === 1
            ? 'De '.$itens->count().' questões, 1 está na faixa de revisão.'
            : 'De '.$itens->count().' questões, '.$revisar->count().' estão na faixa de revisão.'];

        if ($negativas->isNotEmpty()) {
            $piores = $negativas->sortBy('discriminacao')->take(3)->map(fn ($i) => 'Q'.$i['numero'])->implode(', ');
            $partes[] = $negativas->count() === 1
                ? 'A '.$piores.' tem discriminação negativa: confira o gabarito dela antes de qualquer outra coisa.'
                : $negativas->count().' delas têm discriminação negativa ('.$piores.'): confira o gabarito dessas antes de qualquer outra coisa.';
        }

        if (($psicometria['simulacao']['ganho'] ?? 0) > 0) {
            $partes[] = 'Removendo os itens fracos, a confiabilidade subiria para '.$this->num($psicometria['simulacao']['kr20'], 2).'.';
        }

        // Discriminação negativa é erro de gabarito até prova em contrário —
        // é a única situação aqui que pede ação imediata.
        return $this->entrada($generico, implode(' ', $partes), $negativas->isNotEmpty() ? 'ruim' : 'atencao');
    }

    /** @param array<string, mixed> $bi */
    private function histograma(array $bi): array
    {
        $generico = 'Quantos respondentes caíram em cada faixa de 10% de acerto. É o formato da distribuição que '
            .'interessa: um pico único no meio é o esperado; dois picos separados sugerem duas turmas dentro da mesma '
            .'prova (quem estudou e quem não estudou); um amontoado à esquerda indica prova difícil demais ou conteúdo '
            .'não ensinado.';

        $histograma = $bi['histograma'] ?? [];
        if (empty($histograma) || array_sum($histograma) === 0) {
            return $this->entrada($generico, null);
        }

        $total = array_sum($histograma);
        $maior = max($histograma);
        $faixa = (int) array_search($maior, $histograma, true);
        $abaixoDaMetade = array_sum(array_slice($histograma, 0, 5));

        $leitura = 'A maior concentração está na faixa de '.($faixa * 10).'–'.($faixa * 10 + 9).'%, com '.$maior.' respondente(s). ';
        $maioriaAbaixo = $abaixoDaMetade / $total >= 0.5;
        $leitura .= $maioriaAbaixo
            ? $this->num($abaixoDaMetade / $total * 100).'% da turma ficou abaixo de 50% de acerto — vale checar se o conteúdo foi coberto antes da prova.'
            : $this->num((1 - $abaixoDaMetade / $total) * 100).'% da turma ficou em 50% ou mais de acerto.';

        return $this->entrada($generico, $leitura, $maioriaAbaixo ? 'atencao' : 'bom');
    }

    /** @param array<string, float> $valores */
    private function porCampo(array $valores, string $generico, string $substantivo): array
    {
        if (count($valores) < 2) {
            return $this->entrada($generico, null);
        }

        $melhor = array_search(max($valores), $valores, true);
        $pior = array_search(min($valores), $valores, true);
        $diferenca = max($valores) - min($valores);

        $leitura = "O melhor desempenho é em {$melhor} (".$this->num(max($valores)).'%) e o pior em '."{$pior} (".$this->num(min($valores)).'%). ';
        $desequilibrado = $diferenca >= self::VARIACAO_RELEVANTE;
        $leitura .= $desequilibrado
            ? 'São '.$this->num($diferenca).' pontos de diferença — uma distância grande o suficiente para tratar '."{$pior} como prioridade."
            : 'A diferença de '.$this->num($diferenca).' pontos é pequena: o desempenho está parelho entre as opções de '."{$substantivo}.";

        return $this->entrada($generico, $leitura, $desequilibrado ? 'atencao' : 'bom');
    }

    /** @param array<int, array<string, mixed>> $temas */
    private function desempenhoTema(array $temas): array
    {
        $generico = 'Desempenho por tema, que é o recorte mais fino que a plataforma tem — abaixo de área e de '
            .'disciplina. Serve para transformar "foram mal em Clínica Médica" em algo acionável: normalmente são dois '
            .'ou três temas puxando a área inteira para baixo, e é neles que a revisão rende. Olhe também quantas '
            .'questões cada tema tem: um tema com uma questão só não sustenta conclusão.';

        if (count($temas) < 2) {
            return $this->entrada($generico, null);
        }

        // A lista já vem ordenada por percentual ascendente (ver RelatorioAdminService).
        $pior = $temas[0];
        $melhor = $temas[count($temas) - 1];

        $leitura = 'O tema mais crítico é '.$pior['tema'].' ('.$this->num($pior['percentual']).'% em '.$pior['totalQuestoes'].' questão(ões))';
        $leitura .= $pior['area'] ? ', da área de '.$pior['area'].'.' : '.';
        $leitura .= ' No outro extremo, '.$melhor['tema'].' com '.$this->num($melhor['percentual']).'%.';

        return $this->entrada($generico, $leitura, $pior['percentual'] < 50 ? 'atencao' : 'bom');
    }

    /** @param array<int, array<string, mixed>> $ranking */
    private function ranking(array $ranking): array
    {
        $generico = 'A lista completa de respondentes, do maior para o menor percentual de acerto. Use para localizar '
            .'casos individuais — quem ficou muito abaixo do resto e pode precisar de acompanhamento — e não para ler o '
            .'desempenho da turma, que os gráficos de distribuição contam melhor.';

        if (count($ranking) < 3) {
            return $this->entrada($generico, null);
        }

        $percentuais = array_values(array_filter(array_column($ranking, 'percentual'), fn ($p) => $p !== null));
        if (count($percentuais) < 3) {
            return $this->entrada($generico, null);
        }

        $abaixoDe40 = count(array_filter($percentuais, fn ($p) => $p < 40));

        $leitura = count($ranking).' respondente(s), do primeiro com '.$this->num(max($percentuais)).'% ao último com '.$this->num(min($percentuais)).'%.';
        if ($abaixoDe40 > 0) {
            $leitura .= ' '.$abaixoDe40.' ficou(aram) abaixo de 40% de acerto e merece(m) acompanhamento individual.';
        }

        return $this->entrada($generico, $leitura, $abaixoDe40 > 0 ? 'atencao' : 'bom');
    }

    /** @param array<int, array<string, mixed>> $turmas */
    private function distribuicaoTurma(array $turmas): array
    {
        $generico = 'Média de acerto de cada turma. Antes de concluir que uma turma é melhor que a outra, olhe quantos '
            .'respondentes cada uma tem: uma turma de 8 alunos oscila muito de prova para prova, e uma diferença pequena '
            .'entre turmas grandes diz mais que uma diferença grande entre turmas pequenas.';

        if (count($turmas) < 2) {
            return $this->entrada($generico, null);
        }

        $primeira = $turmas[0];
        $ultima = $turmas[count($turmas) - 1];
        $diferenca = $primeira['media'] - $ultima['media'];

        $leitura = $primeira['turma'].' lidera com '.$this->num($primeira['media']).'% ('.$primeira['respondentes'].' respondentes) e '
            .$ultima['turma'].' fecha com '.$this->num($ultima['media']).'% ('.$ultima['respondentes'].'). ';
        $relevante = $diferenca >= self::VARIACAO_RELEVANTE;
        $leitura .= $relevante
            ? 'São '.$this->num($diferenca).' pontos entre elas — diferença grande o bastante para investigar o que mudou entre as turmas.'
            : 'A diferença de '.$this->num($diferenca).' pontos é pequena e pode ser só variação normal entre turmas.';

        return $this->entrada($generico, $leitura, $relevante ? 'atencao' : 'bom');
    }

    /** @param array<string, array<string, mixed>> $curva */
    private function curvaDificuldade(array $curva): array
    {
        $generico = 'Compara a dificuldade que o professor previu ao cadastrar cada questão com o acerto que realmente '
            .'aconteceu. O esperado é uma escada descendente: as questões marcadas como fáceis com acerto alto, as '
            .'difíceis com acerto baixo. Quando a escada quebra — "difícil" com mais acerto que "fácil" — a classificação '
            .'das questões precisa ser revista, não a turma.';

        if (count($curva) < 2) {
            return $this->entrada($generico, null);
        }

        $observados = array_map(fn ($linha) => $linha['observado'], $curva);
        $ordenado = $observados;
        arsort($ordenado);

        $leitura = collect($curva)
            ->map(fn ($linha, $chave) => $linha['esperado'].': '.$this->num($linha['observado']).'%')
            ->implode(' · ');

        $coerente = array_keys($observados) === array_keys($ordenado);

        return $this->entrada(
            $generico,
            $leitura.'. '.($coerente
                ? 'A ordem bate com o esperado: quanto mais difícil a questão foi classificada, menor o acerto.'
                : 'A ordem não bate com o esperado — há nível marcado como mais difícil com acerto maior que um nível mais fácil, sinal de que a classificação das questões precisa de revisão.'),
            $coerente ? 'bom' : 'atencao',
        );
    }

    /** @param array<int, array<string, mixed>> $pontos */
    private function dispersaoTri(array $pontos): array
    {
        $generico = 'Cruza a dificuldade TRI cadastrada em cada questão (escala contínua, importada junto com o '
            .'gabarito) com o acerto observado na turma. O esperado é uma nuvem descendente: quanto maior o valor de '
            .'TRI, menor o acerto. Pontos fora dessa tendência são questões cuja dificuldade real não corresponde à '
            .'estimada — normalmente porque o conteúdo foi (ou não foi) trabalhado com esta turma específica.';

        if (count($pontos) < 3) {
            return $this->entrada($generico, null);
        }

        $tris = array_column($pontos, 'dificuldade_tri');
        $taxas = array_column($pontos, 'taxa_acerto');
        $correlacao = $this->pearson($tris, $taxas);

        if ($correlacao === null) {
            return $this->entrada($generico, count($pontos).' questões com dificuldade TRI cadastrada.');
        }

        $leitura = count($pontos).' questões com TRI cadastrado. ';
        $leitura .= match (true) {
            $correlacao <= -0.4 => 'A relação é a esperada (correlação de '.$this->num($correlacao, 2).'): questões com TRI mais alto tiveram menos acerto.',
            $correlacao >= 0.4 => 'A relação está invertida (correlação de '.$this->num($correlacao, 2).'): questões marcadas como mais difíceis tiveram MAIS acerto — vale conferir se os valores de TRI foram importados na escala certa.',
            default => 'Quase não há relação entre o TRI cadastrado e o acerto real (correlação de '.$this->num($correlacao, 2).'), ou seja, o TRI desta prova não está prevendo o desempenho desta turma.',
        };

        return $this->entrada($generico, $leitura);
    }

    /** @param array<string, array<string, float>> $heatmap */
    private function heatmap(array $heatmap): array
    {
        $generico = 'Cruza habilidade (linhas) com turma (colunas): quanto mais escura a célula, maior o acerto. '
            .'Leia por linha para achar habilidade que vai mal em TODAS as turmas — isso é problema de currículo, não de '
            .'turma. Leia por coluna para achar a turma que destoa numa habilidade específica — isso costuma ser '
            .'diferença de quem ministrou ou de quando o conteúdo foi dado.';

        if (count($heatmap) < 2) {
            return $this->entrada($generico, null);
        }

        $medias = [];
        foreach ($heatmap as $habilidade => $porTurma) {
            $valores = array_filter($porTurma, fn ($v) => $v !== null);
            if ($valores !== []) {
                $medias[$habilidade] = array_sum($valores) / count($valores);
            }
        }

        if (count($medias) < 2) {
            return $this->entrada($generico, null);
        }

        $pior = array_search(min($medias), $medias, true);

        return $this->entrada(
            $generico,
            'A habilidade com menor acerto médio entre as turmas é "'.$pior.'" ('.$this->num(min($medias)).'%). '
            .'Se ela estiver baixa em todas as colunas, o ponto de atenção é o currículo, não uma turma específica.',
            min($medias) < 50 ? 'atencao' : 'bom',
        );
    }

    /**
     * Composição por sexo ou cor/raça. Deliberadamente SEM tom: a distribuição
     * demográfica de quem fez a prova não é "boa" nem "ruim" — quem responde
     * por bom ou ruim é o bloco de equidade, que cruza esses mesmos recortes
     * com desempenho.
     *
     * @param  array<string, int>  $dados
     */
    private function perfilComposicao(array $dados, string $rotulo): array
    {
        $generico = 'Como os respondentes desta avaliação se distribuem por '.$rotulo.'. Este gráfico descreve a '
            .'COMPOSIÇÃO do grupo, não o desempenho dele — um grupo ser maior que o outro não é bom nem ruim. '
            .'Ele serve para duas coisas: conferir se quem fez a prova representa o curso (se um grupo some aqui mas '
            .'existe no curso, o resultado da avaliação não fala por ele) e dar contexto ao bloco de equidade, que '
            .'mostra como cada um destes mesmos grupos se saiu.';

        $dados = array_filter($dados);
        $total = array_sum($dados);

        if ($total === 0) {
            return $this->entrada($generico, null);
        }

        $maior = array_search(max($dados), $dados, true);
        $fatia = max($dados) / $total * 100;

        $leitura = 'O grupo mais numeroso é "'.$maior.'", com '.$this->num($fatia).'% dos '.$total.' respondentes que têm '.$rotulo.' cadastrado(a)';
        $leitura .= count($dados) === 1
            ? '. É o único grupo com dados preenchidos, então não há comparação possível aqui.'
            : ', entre '.count($dados).' grupos.';

        return $this->entrada($generico, $leitura);
    }

    /** @param array<string, int> $ufs */
    private function perfilUf(array $ufs): array
    {
        $generico = 'De quais estados vêm os respondentes: quanto mais escuro no mapa, mais gente daquela UF. Como os '
            .'outros recortes demográficos, descreve a composição do grupo e não o desempenho. É útil principalmente '
            .'para cursos que recebem alunos de fora da região — concentração muito alta numa UF só significa que '
            .'qualquer leitura "por origem" vale pouco, porque não há com quem comparar.';

        $ufs = array_filter($ufs);
        if ($ufs === []) {
            return $this->entrada($generico, null);
        }

        $total = array_sum($ufs);
        $maiorUf = array_search(max($ufs), $ufs, true);
        $fatia = max($ufs) / $total * 100;

        $leitura = count($ufs) === 1
            ? 'Todos os '.$total.' respondentes com UF cadastrada vêm de '.$maiorUf.'.'
            : $this->num($fatia).'% dos respondentes vêm de '.$maiorUf.', num total de '.count($ufs).' UFs.';

        return $this->entrada($generico, $leitura);
    }

    /** @param array<int, array<string, mixed>> $questoes */
    private function analiseAlternativas(array $questoes): array
    {
        $generico = 'Como as respostas se espalharam entre as alternativas de cada questão. O que procurar é o '
            .'distrator que atraiu mais gente que o próprio gabarito: isso quase nunca é desatenção coletiva, e sim '
            .'enunciado ambíguo, alternativa defensável ou um erro conceitual compartilhado que vale ensinar de novo. '
            .'Alternativa que ninguém marcou também informa: ela não está funcionando como distrator e pode ser trocada.';

        if ($questoes === []) {
            return $this->entrada($generico, null);
        }

        $comDistrator = collect($questoes)->filter(
            fn ($q) => collect($q['alternativas'])->contains(fn ($a) => $a['ehDistrator'] ?? false)
        );

        if ($comDistrator->isEmpty()) {
            return $this->entrada($generico, 'Em nenhuma questão um distrator atraiu mais respostas que o gabarito.', 'bom');
        }

        $exemplos = $comDistrator->take(3)->map(fn ($q) => 'Q'.$q['numero'])->implode(', ');

        return $this->entrada(
            $generico,
            $comDistrator->count().' questão(ões) tem um distrator mais marcado que o gabarito ('.$exemplos.
            ($comDistrator->count() > 3 ? '…' : '').'). Comece a revisão do enunciado por elas.',
            'atencao',
        );
    }

    /** @param array<int, array<string, mixed>> $metricas */
    private function correlacaoMetricas(array $metricas): array
    {
        $generico = 'Correlação entre o percentual de acerto na prova objetiva e cada métrica nomeada importada junto '
            .'(nota de redação, nota final, etc.). Vai de -1 a 1: perto de 1 as duas medidas andam juntas, perto de 0 '
            .'são independentes, negativo significa que andam em sentidos opostos. Correlação alta indica que as duas '
            .'estão medindo a mesma coisa; correlação baixa não é defeito — pode ser exatamente o esperado quando a '
            .'métrica avalia outra competência.';

        $validas = array_filter($metricas, fn ($m) => $m['correlacao'] !== null);
        if ($validas === []) {
            return $this->entrada($generico, null);
        }

        $forte = collect($validas)->sortByDesc(fn ($m) => abs($m['correlacao']))->first();

        return $this->entrada(
            $generico,
            'A relação mais forte é com "'.$forte['nome_metrica'].'" (r = '.$this->num($forte['correlacao'], 2).', em '.$forte['n'].' respondentes): '
            .(abs($forte['correlacao']) >= 0.6
                ? 'as duas medidas andam bastante juntas, então elas capturam em boa parte a mesma competência.'
                : 'a relação é fraca, ou seja, essa métrica está medindo algo que a prova objetiva não mede.'),
        );
    }

    /** @param array<int, array<string, mixed>> $pontos */
    private function evolucaoCategoria(array $pontos): array
    {
        $generico = 'Média da turma em cada avaliação desta mesma categoria, na ordem em que aconteceram. Mostra se o '
            .'conjunto está evoluindo ao longo do tempo. Cuidado com uma armadilha: se as provas tiveram dificuldades '
            .'diferentes, a linha mistura "a turma melhorou" com "a prova ficou mais fácil" — por isso vale olhar junto '
            .'com o KR-20 e a dificuldade média de cada avaliação.';

        if (count($pontos) < 2) {
            return $this->entrada($generico, null);
        }

        $primeiro = $pontos[0];
        $ultimo = $pontos[count($pontos) - 1];
        $delta = round($ultimo['media'] - $primeiro['media'], 1);

        $leitura = 'De "'.$primeiro['nome'].'" ('.$this->num($primeiro['media']).'%) a "'.$ultimo['nome'].'" ('.$this->num($ultimo['media']).'%), ';
        $leitura .= match (true) {
            $delta >= self::VARIACAO_RELEVANTE => 'a média subiu '.$this->num($delta).' pontos.',
            $delta <= -self::VARIACAO_RELEVANTE => 'a média caiu '.$this->num(abs($delta)).' pontos.',
            default => 'a média ficou praticamente estável ('.($delta >= 0 ? '+' : '−').$this->num(abs($delta)).' ponto(s)).',
        };

        return $this->entrada($generico, $leitura);
    }

    /** @param array<string, array<string, mixed>> $alinhamento */
    private function alinhamento(array $alinhamento, ?float $mediaGeral): array
    {
        $generico = 'O que a prova cobriu em termos de DCN, PPC, Portaria INEP e matriz da prova, e como foi o '
            .'desempenho em cada eixo. Leia as duas colunas juntas: o percentual diz como a turma foi, e a contagem de '
            .'questões diz o quanto aquele número é confiável. Um eixo com 2 questões e 90% não sustenta conclusão '
            .'nenhuma; um com 30 questões e 55% é um problema real de currículo. A marca na barra é a média geral da '
            .'prova — o que interessa é o eixo que ficou bem abaixo dela.';

        if ($alinhamento === []) {
            return $this->entrada($generico, null);
        }

        $todos = collect($alinhamento)->flatMap(fn ($bloco) => $bloco['itens']);
        if ($todos->count() < 2) {
            return $this->entrada($generico, null);
        }

        $pior = $todos->sortBy('percentual')->first();
        $leitura = 'O eixo mais fraco é "'.$pior['valor'].'" ('.$this->num($pior['percentual']).'% em '.$pior['totalQuestoes'].' questão(ões))';

        if ($mediaGeral !== null) {
            $distancia = $mediaGeral - $pior['percentual'];
            $leitura .= $distancia >= self::VARIACAO_RELEVANTE
                ? ', '.$this->num($distancia).' pontos abaixo da média da prova — é o candidato natural a revisão curricular.'
                : ', mas ele está dentro da variação normal em torno da média da prova ('.$this->num($mediaGeral).'%).';
        } else {
            $leitura .= '.';
        }

        $abaixoDaMedia = $mediaGeral !== null && ($mediaGeral - $pior['percentual']) >= self::VARIACAO_RELEVANTE;

        if ($pior['totalQuestoes'] <= 2) {
            $leitura .= ' Atenção: com tão poucas questões, esse percentual é instável.';
        }

        return $this->entrada($generico, $leitura, $abaixoDaMedia ? 'atencao' : 'bom');
    }

    /** @param array<string, array<string, mixed>> $equidade */
    private function equidade(array $equidade): array
    {
        $generico = 'Desempenho médio por recorte demográfico. Isto é monitoramento institucional, não avaliação de '
            .'indivíduo: a pergunta que ele responde é se a instituição está entregando o mesmo resultado para grupos '
            .'diferentes. Grupos com menos de 10 respondentes são omitidos de propósito, porque numa célula pequena a '
            .'"média do grupo" identifica a pessoa. Diferença pequena entre grupos é normal; o que pede investigação é '
            .'uma diferença grande que se repete em várias avaliações.';

        if ($equidade === []) {
            return $this->entrada($generico, null);
        }

        $maiorDiferenca = null;
        foreach ($equidade as $recorte) {
            $medias = array_column($recorte['grupos'], 'media');
            if (count($medias) < 2) {
                continue;
            }
            $amplitude = max($medias) - min($medias);
            if ($maiorDiferenca === null || $amplitude > $maiorDiferenca['amplitude']) {
                $maiorDiferenca = [
                    'rotulo' => $recorte['rotulo'],
                    'amplitude' => $amplitude,
                    'melhor' => collect($recorte['grupos'])->firstWhere('media', max($medias)),
                    'pior' => collect($recorte['grupos'])->firstWhere('media', min($medias)),
                ];
            }
        }

        if ($maiorDiferenca === null) {
            return $this->entrada($generico, null);
        }

        $leitura = 'A maior diferença aparece em '.mb_strtolower($maiorDiferenca['rotulo']).': '
            .$this->num($maiorDiferenca['amplitude']).' pontos entre "'.$maiorDiferenca['melhor']['valor'].'" ('
            .$this->num($maiorDiferenca['melhor']['media']).'%) e "'.$maiorDiferenca['pior']['valor'].'" ('
            .$this->num($maiorDiferenca['pior']['media']).'%). ';

        $relevante = $maiorDiferenca['amplitude'] >= self::VARIACAO_RELEVANTE;
        $leitura .= $relevante
            ? 'É uma distância relevante: vale acompanhar se ela se repete nas próximas avaliações antes de concluir qualquer coisa.'
            : 'É uma distância pequena, dentro do que se espera por variação normal entre grupos.';

        return $this->entrada($generico, $leitura, $relevante ? 'atencao' : 'bom');
    }

    /**
     * @param  array{avaliacoes: array<int, array{codigo: int, nome: string, data: ?string, resumo: ?array, mediaPorArea: array<string, float>}>, areas: array<int, string>}|null  $comparacao
     */
    private function comparacaoAvaliacoes(?array $comparacao): array
    {
        $generico = 'Compara a média geral e o desempenho por área desta avaliação com a(s) outra(s) escolhida(s) ao '
            .'lado — cada avaliação mantém a mesma cor em todo gráfico deste painel. Serve para saber se a turma foi '
            .'melhor ou pior nesta prova do que em outra, e em quais áreas a diferença está concentrada. Avaliações '
            .'diferentes podem ter dificuldades diferentes, então uma diferença pequena não significa muita coisa — '
            .'o que pede atenção é uma diferença grande.';

        if ($comparacao === null) {
            return $this->entrada($generico, null);
        }

        $base = $comparacao['avaliacoes'][0] ?? null;
        $outras = array_slice($comparacao['avaliacoes'], 1);

        if ($base === null || $outras === []) {
            return $this->entrada($generico, null);
        }

        if ($base['resumo'] === null) {
            return $this->entrada(
                $generico,
                'Esta avaliação ainda não tem respondentes suficientes para comparar a média — o desempenho por área abaixo já pode ser lido.',
                'atencao',
            );
        }

        $comparaveis = array_values(array_filter($outras, fn ($o) => $o['resumo'] !== null));

        if ($comparaveis === []) {
            return $this->entrada(
                $generico,
                'Nenhuma das avaliações escolhidas tem respondentes suficientes para comparar a média — só dá para comparar o desempenho por área.',
                'atencao',
            );
        }

        $diferencas = array_map(fn ($o) => [
            'nome' => $o['nome'],
            'delta' => round($base['resumo']['media'] - $o['resumo']['media'], 1),
        ], $comparaveis);

        usort($diferencas, fn ($a, $b) => abs($b['delta']) <=> abs($a['delta']));
        $maior = $diferencas[0];

        $leitura = 'Média desta avaliação: '.$this->num($base['resumo']['media']).'%. Contra "'.$maior['nome'].'": ';

        if (abs($maior['delta']) < self::VARIACAO_RELEVANTE) {
            $leitura .= 'desempenho parelho (diferença de '.$this->num(abs($maior['delta'])).' ponto(s)).';
            $tom = 'bom';
        } elseif ($maior['delta'] > 0) {
            $leitura .= $this->num($maior['delta']).' pontos ACIMA.';
            $tom = 'bom';
        } else {
            $leitura .= $this->num(abs($maior['delta'])).' pontos ABAIXO — vale olhar o desempenho por área para achar onde a diferença está concentrada.';
            $tom = 'ruim';
        }

        return $this->entrada($generico, $leitura, $tom);
    }

    /**
     * Correlação de Pearson entre duas listas de mesmo tamanho.
     *
     * @param  array<int, float>  $x
     * @param  array<int, float>  $y
     */
    private function pearson(array $x, array $y): ?float
    {
        $n = count($x);
        if ($n < 2 || $n !== count($y)) {
            return null;
        }

        $mediaX = array_sum($x) / $n;
        $mediaY = array_sum($y) / $n;

        $numerador = 0.0;
        $varX = 0.0;
        $varY = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $mediaX;
            $dy = $y[$i] - $mediaY;
            $numerador += $dx * $dy;
            $varX += $dx ** 2;
            $varY += $dy ** 2;
        }

        if ($varX <= 0.0 || $varY <= 0.0) {
            return null;
        }

        return round($numerador / sqrt($varX * $varY), 4);
    }

    /**
     * $tom é o que responde "isso é bom ou ruim?" sem a pessoa precisar
     * interpretar o número: bom | atencao | ruim, ou null quando o dado é
     * puramente descritivo (um perfil demográfico não é bom nem ruim).
     *
     * @return array{generico: string, leitura: ?string, tom: ?string}
     */
    private function entrada(string $generico, ?string $leitura, ?string $tom = null): array
    {
        return ['generico' => $generico, 'leitura' => $leitura, 'tom' => $leitura === null ? null : $tom];
    }

    private function num(float $valor, int $casas = 1): string
    {
        return number_format($valor, $casas, ',', '.');
    }
}
