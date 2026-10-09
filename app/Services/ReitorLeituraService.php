<?php

namespace App\Services;

use App\Support\Psicometria;

/**
 * Os textos do painel da reitoria: o que cada quadro mede ("Sobre este quadro", fixo) e a LEITURA do que está na
 * tela agora ("Ver leitura", gerada dos números, com o tom: bom / atenção / ruim) — mais os pontos de atenção da
 * visão geral. Só texto: nenhum cálculo novo, tudo vem do que o ReitorDashboardService & cia já calcularam.
 *
 * Os limites usados para dar o "tom" são deliberadamente simples e explicados na leitura — o objetivo é dizer, em
 * uma frase, se o número é um bom sinal ou um problema, nunca esconder a conta.
 */
class ReitorLeituraService
{
    private static function pct(float|int|null $v): string
    {
        return CoordenadorDashboardService::pct($v);
    }

    private static function pp(float $v): string
    {
        return ($v > 0 ? '+' : ($v < 0 ? '−' : '')).self::pct(abs($v)).' pp';
    }

    /** Ícone do ponto de atenção => quadro de origem [rota, âncora, rótulo do link]. */
    private const ORIGEM_DOS_PONTOS = [
        'ph-user-minus' => ['reitor.visao', 'secao-participacao', 'Ver participação por curso'],
        'ph-user-check' => ['reitor.visao', 'secao-participacao', 'Ver participação por curso'],
        'ph-prohibit' => ['reitor.visao', 'secao-participacao', 'Ver participação por curso'],
        'ph-warning-circle' => ['reitor.visao', 'secao-quadrante', 'Ver o mapa participação × proficiência'],
        'ph-calendar-x' => ['reitor.trajetoria', 'secao-cobertura', 'Ver a cobertura da aplicação'],
        'ph-target' => ['reitor.visao', 'secao-proficiencia', 'Ver proficiência por curso'],
        'ph-trend-up' => ['reitor.evolucao', 'secao-evolucao-inst', 'Ver a evolução entre semestres'],
        'ph-trend-down' => ['reitor.evolucao', 'secao-evolucao-inst', 'Ver a evolução entre semestres'],
        'ph-minus' => ['reitor.evolucao', 'secao-evolucao-inst', 'Ver a evolução entre semestres'],
    ];

    /** @param array<int, string> $nomes */
    private static function lista(array $nomes, int $limite = 4): string
    {
        $mostrados = array_slice($nomes, 0, $limite);
        $resto = count($nomes) - count($mostrados);
        $texto = match (count($mostrados)) {
            0 => '',
            1 => $mostrados[0],
            default => implode(', ', array_slice($mostrados, 0, -1)).' e '.end($mostrados),
        };

        return $texto.($resto > 0 ? " (+{$resto})" : '');
    }

    // ------------------------------------------------------------------------------------------------------------
    // "Sobre este quadro" — o que cada visual mede (texto fixo; {corte} e {meta} são trocados na hora)
    // ------------------------------------------------------------------------------------------------------------

    /** @return array<string, string> */
    public function sobre(float $corte, float $meta): array
    {
        $c = self::pct($corte);
        $m = self::pct($meta);

        $textos = [
            'participacao' => 'Compara, em cada curso, quantos estudantes eram previstos (matrículas ativas no período letivo, nos períodos do curso em que a avaliação foi aplicada) com quantos fizeram a prova. Ausente é quem tinha matrícula e não fez (ou entregou em branco). "Dif. da média" mostra a diferença para a participação do conjunto; "distância da meta" e "alunos a mais" dizem o quanto falta para a meta de {meta}%. Períodos do curso com alunos ativos mas sem nenhuma aplicação ficam à parte ("ativos sem aplicação") e não entram na conta.',
            'meta' => 'A meta de participação é um parâmetro institucional ({meta}%), definido pela administração em Configurações. "Alunos a mais" é o menor número de participantes adicionais para o curso chegar à meta com os mesmos previstos.',
            'proficiencia' => 'Estudantes proficientes são os que acertaram {corte}% ou mais da prova. É um critério interno da instituição, escolhido para acompanhamento — não foi validado contra a proficiência medida em exames externos (ENADE, ENAMED) e não deve ser lido como equivalente a ela. A linha tracejada é o percentual do conjunto da visão. Só entram quem fez a prova.',
            'patamares' => 'Mostra quanto da turma passa de cada patamar de acerto, a partir do critério de proficiência. A queda de um patamar para o seguinte mede o quanto o resultado depende de um corte específico: uma queda brusca indica muitos estudantes "encostados" no corte.',
            'media' => 'Média e mediana do percentual de acerto de quem fez a prova. A mediana é o valor do meio: metade dos estudantes fica abaixo dela. Quando a média é bem diferente da mediana, poucos resultados muito altos ou muito baixos estão puxando a média.',
            'faixas' => 'Distribui os estudantes de cada curso em cinco faixas de acerto, de 100%: a soma de cada barra é toda a turma. Mostra o formato do desempenho que a média esconde — dois cursos de mesma média podem ter perfis opostos.',
            'dispersao' => 'Para cada curso: a caixa vai do 1º ao 3º quartil (metade central dos estudantes), a linha fina vai do 10º ao 90º percentil, e o losango é a mediana. Caixa larga = turma heterogênea; caixa estreita = turma homogênea.',
            'quadrante' => 'Cruza participação (horizontal) com proficiência (vertical). O tamanho da bolha é o número de previstos. As linhas marcam a meta de participação e a proficiência geral do conjunto: cursos abaixo das duas são o quadrante de atenção. Proficiência com baixa participação pode estar medindo só quem se dispôs a fazer a prova.',
            'trajetoria_proficiencia' => 'Percentual de proficientes em cada período do curso (1º, 2º...). É uma FOTO do semestre — estudantes de períodos diferentes são pessoas diferentes —, não o acompanhamento da mesma turma ao longo do curso. Períodos com menos de {minimo} participantes não aparecem na linha.',
            'trajetoria_acerto' => 'Média de acerto em cada período do curso. Leitura transversal (cada período é uma turma diferente): uma linha que sobe sugere que quem está mais adiantado sabe mais; uma linha plana, que o curso não está produzindo ganho visível entre períodos. Períodos com menos de {minimo} participantes não aparecem.',
            'mapa_periodo' => 'Mapa de calor da média de acerto por curso e período do curso. Células vazias são períodos sem aplicação ou com menos de {minimo} participantes; passe o mouse (ou veja a tabela) para ver o número de participantes de cada célula.',
            'crescimento' => 'Quanto o curso "cresce" ao longo dos períodos: a inclinação da reta (em pontos percentuais por período) ajustada às médias dos períodos avaliados, ponderada pelo nº de participantes, e a diferença entre o último e o primeiro período avaliado. É uma leitura transversal: indica a tendência da sequência de turmas, não o ganho individual de um estudante.',
            'cobertura' => 'Mostra, por curso, em quais períodos a avaliação foi aplicada e em quais há alunos ativos sem aplicação. Serve para separar "o aluno faltou" de "a prova não chegou àquela turma".',
            'bloom_distingue' => 'Compara o percentual de acerto dos estudantes proficientes (≥ {corte}%) e dos não proficientes em cada nível da taxonomia de Bloom. Onde a distância é maior, a habilidade cognitiva mais separa quem foi bem de quem não foi.',
            'bloom_mapa' => 'Percentual de acerto de cada curso em cada nível da taxonomia de Bloom (das questões que têm o nível cadastrado). Células com menos de {minimo_respostas} respostas aparecem como "—".',
            'areas_ranking' => 'Percentual de acerto por área de conhecimento (campo "área" das questões) no conjunto da visão, da mais fraca para a mais forte, com a distância entre proficientes e não proficientes em cada área.',
            'areas_mapa' => 'Percentual de acerto de cada curso nas áreas com mais respostas. Células com menos de {minimo_respostas} respostas aparecem como "—".',
            'evolucao_institucional' => 'Participação, média e proficiência da instituição em cada período letivo, sempre na mesma categoria de avaliação. Cada ponto é uma foto do semestre: os estudantes de um semestre e de outro são, em parte, pessoas diferentes.',
            'itens_mapa' => 'Cada ponto é um item (questão de uma avaliação): acerto (%) no eixo horizontal e discriminação (D, diferença de acerto entre os 27% de melhor e de pior desempenho) no vertical. D abaixo de {d_minimo} não separa quem sabe de quem não sabe; acerto abaixo de {acerto_baixo}% é muito baixo. O diagnóstico combina os dois: baixo acerto E baixa discriminação aponta a QUESTÃO; baixo acerto com boa discriminação aponta uma LACUNA DE FORMAÇÃO; discriminação negativa com uma alternativa errada mais marcada que o gabarito aponta possível ERRO DE GABARITO.',
            'itens_lista' => 'Os itens com diagnóstico, do mais urgente (gabarito suspeito) ao menos. Em avaliações com estudantes de vários cursos, o acerto por curso mostra se o problema é da questão (baixo em todos) ou de um curso. O nome da avaliação abre o Dashboard dela (visão do coordenador). Itens com menos de {minimo_itens} respostas não são diagnosticados.',
            'itens_areas' => 'Percentual de itens com diagnóstico em cada área (campo "área" da questão). Uma área com muitos itens a revisar pede revisão das questões ou reforço do conteúdo, conforme o diagnóstico predominante.',
            'itens_avaliacoes' => 'Para cada avaliação do recorte: cursos com respondentes, confiabilidade (KR-20, de 0 a 1: acima de 0,70 é aceitável para a prova como um todo) e quantos itens têm diagnóstico.',
            'risco_cursos' => 'Estudantes em risco, só em agregado (os nomes ficam com o coordenador do curso). "Em risco" é o estudante que se enquadra na regra de risco da instituição (definida pela administração em Configurações; cada avaliação pode ter a sua): média de acerto abaixo de um limite e/ou número de faltas (prova inteira em branco), combinados por "ou" ou "e". É a mesma regra da lista de alunos em atenção do coordenador. "Por faltas" e "por acerto" mostram cada critério separado: os dois podem coexistir na mesma pessoa, e o "em risco" a conta uma vez. Grupos com menos de {minimo_risco} estudantes não mostram percentual (identificaria gente). O estudante é contado pelo cadastro; sem vínculo com o cadastro, chaves diferentes (CPF e RA) contam como pessoas diferentes, o que subestima as faltas.',
            'risco_grafico' => 'Compara os cursos nos dois critérios da regra de risco: o percentual de estudantes que atingem o limite de faltas e o dos que ficam com média de acerto abaixo do limite. Os dois podem coexistir na mesma pessoa; o "em risco" da tabela conta cada pessoa uma vez. Um critério desligado na regra não aparece.',
            'risco_mapa' => 'Percentual de estudantes em risco por curso e período do curso. Células vazias: menos de {minimo_risco} estudantes. Mostra em que ponto da trajetória o risco se concentra.',
            'evolucao_cursos' => 'Variação de cada curso entre o período letivo anterior e o selecionado, em pontos percentuais. Verde: melhorou; vermelho: piorou. Variações pequenas (menos de 3 pp) ficam em cinza: numa turma de poucas dezenas de estudantes, isso é ruído.',
        ];

        return array_map(fn ($t) => strtr($t, [
            '{corte}' => $c,
            '{meta}' => $m,
            '{minimo}' => (string) ReitorDashboardService::MINIMO_PERIODO,
            '{minimo_respostas}' => (string) ReitorCompetenciasService::MINIMO_RESPOSTAS,
            '{minimo_risco}' => (string) ReitorRiscoService::MINIMO_PESSOAS,
            '{d_minimo}' => number_format(Psicometria::D_MINIMO_ACEITAVEL, 2, ',', ''),
            '{acerto_baixo}' => (string) (int) ReitorItensService::ACERTO_MUITO_BAIXO,
            '{minimo_itens}' => (string) ReitorItensService::MINIMO_RESPOSTAS,
        ]), $textos);
    }

    // ------------------------------------------------------------------------------------------------------------
    // "Ver leitura" — o que os números de agora dizem
    // ------------------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $est  saída de ReitorDashboardService::estatisticas()
     * @return array<string, array{texto: string, tom: ?string}>
     */
    public function leituras(array $ctx, array $est, ?array $competencias = null, ?array $evolucao = null): array
    {
        $cursos = array_values($est['cursos']);
        $total = $est['total'];
        if ($cursos === []) {
            return [];
        }

        $corte = $ctx['corte'];
        $meta = $ctx['meta'];
        $c = self::pct($corte);
        $nome = fn ($x) => $x['nome'];
        $porValor = fn (string $campo, bool $desc = false) => collect($cursos)->filter(fn ($x) => $x[$campo] !== null)->sortBy($campo, SORT_REGULAR, $desc)->values();

        $leituras = [];

        // Participação e meta
        $abaixoMeta = collect($cursos)->filter(fn ($x) => $x['participacao'] !== null && $x['participacao'] < $meta)->sortBy('participacao')->values();
        $menorPart = $porValor('participacao')->first();
        $tom = $total['participacao'] === null ? null : ($total['participacao'] >= $meta ? 'bom' : ($total['participacao'] >= $meta - 5 ? 'atencao' : 'ruim'));
        $leituras['participacao'] = ['tom' => $tom, 'texto' => $total['participacao'] === null ? 'Sem previstos para calcular a participação.'
            : 'Participação de '.self::pct($total['participacao'])."% ({$total['fizeram']} de {$total['previstos']} previstos), {$this->pontosDaMeta($total['distanciaMeta'])} da meta de ".self::pct($meta).'%. '
            .(count($cursos) - $abaixoMeta->count()).' de '.count($cursos).' cursos já estão na meta'
            .($total['alunosAMais'] > 0 ? "; para todos chegarem lá faltam {$total['alunosAMais']} estudantes" : '')
            .($menorPart !== null && $menorPart['participacao'] < $meta ? ". A menor participação é a de {$menorPart['nome']} (".self::pct($menorPart['participacao']).'%).' : '.')];
        $leituras['meta'] = $leituras['participacao'];

        // Proficiência
        $porProf = $porValor('proficienciaPct', true);
        $acimaMedia = collect($cursos)->filter(fn ($x) => $x['proficienciaPct'] !== null && $total['proficienciaPct'] !== null && $x['proficienciaPct'] >= $total['proficienciaPct'])->count();
        $tomProf = $total['proficienciaPct'] === null ? null : ($total['proficienciaPct'] >= 50 ? 'bom' : ($total['proficienciaPct'] >= 25 ? 'atencao' : 'ruim'));
        $leituras['proficiencia'] = ['tom' => $tomProf, 'texto' => $total['proficienciaPct'] === null ? 'Sem resultados para calcular a proficiência.'
            : self::pct($total['proficienciaPct'])."% dos estudantes ({$total['proficientes']} de {$total['n']}) atingiram o critério de ≥ {$c}% de acerto. "
            .($porProf->count() >= 2 ? "A maior proporção é a de {$porProf->first()['nome']} (".self::pct($porProf->first()['proficienciaPct'])."%) e a menor a de {$porProf->last()['nome']} (".self::pct($porProf->last()['proficienciaPct']).'%); ' : '')
            ."$acimaMedia de ".count($cursos).' cursos estão acima do conjunto. (Referência do tom: ≥ 50% bom, 25–49% atenção, abaixo disso precisa de ação.)'];

        // Patamares
        $pat = $total['patamares'];
        $patamares = array_keys($pat);
        $primeiro = $patamares[0];
        $ultimo = $patamares[count($patamares) - 1];
        $leituras['patamares'] = ['tom' => null, 'texto' => $pat[$primeiro] === null ? 'Sem dados.'
            : 'No conjunto, '.self::pct($pat[$primeiro])."% passam de {$primeiro}% de acerto, ".self::pct($pat[$patamares[2]]).'% passam de '.$patamares[2].'% e '.self::pct($pat[$ultimo])."% passam de {$ultimo}%. "
            .($pat[$primeiro] > 0 && $pat[$patamares[2]] / $pat[$primeiro] < 0.5 ? 'A queda é forte: boa parte de quem é proficiente está perto do corte, sem folga.' : 'A queda entre patamares é gradual: quem passa do corte tende a ter alguma folga.')];

        // Média e mediana
        $tomMedia = $total['media'] === null ? null : ($total['media'] >= $corte ? 'bom' : ($total['media'] >= $corte - 10 ? 'atencao' : 'ruim'));
        $dif = $total['media'] !== null && $total['mediana'] !== null ? round($total['media'] - $total['mediana'], 1) : null;
        $leituras['media'] = ['tom' => $tomMedia, 'texto' => $total['media'] === null ? 'Sem dados.'
            : 'Média de '.self::pct($total['media']).'% e mediana de '.self::pct($total['mediana']).'% no conjunto'
            .($dif !== null && abs($dif) >= 2 ? ($dif > 0 ? ': a média acima da mediana indica que poucos resultados altos puxam a média.' : ': a mediana acima da média indica que poucos resultados muito baixos puxam a média.') : ': média e mediana próximas, sem distorção por casos extremos.')
            .' Cursos com média acima do corte de '.$c.'%: '.collect($cursos)->filter(fn ($x) => $x['media'] !== null && $x['media'] >= $corte)->count().' de '.count($cursos).'.'];

        // Faixas
        $baixa = collect($cursos)->filter(fn ($x) => ($x['faixas'][0]['pct'] ?? null) !== null)->sortByDesc(fn ($x) => $x['faixas'][0]['pct'])->first();
        $alta = collect($cursos)->filter(fn ($x) => ($x['faixas'][4]['pct'] ?? null) !== null)->sortByDesc(fn ($x) => $x['faixas'][4]['pct'])->first();
        $leituras['faixas'] = ['tom' => null, 'texto' => $baixa === null ? 'Sem dados.'
            : "No conjunto, {$this->pctFaixa($total, 0)}% estão na faixa mais baixa ({$total['faixas'][0]['rotulo']}) e {$this->pctFaixa($total, 4)}% na mais alta ({$total['faixas'][4]['rotulo']}). "
            ."A maior concentração na faixa mais baixa é a de {$baixa['nome']} (".self::pct($baixa['faixas'][0]['pct']).'%)'
            .($alta !== null ? " e a maior na faixa mais alta, a de {$alta['nome']} (".self::pct($alta['faixas'][4]['pct']).'%).' : '.')];

        // Dispersão
        $comIqr = collect($cursos)->filter(fn ($x) => $x['q1'] !== null && $x['n'] >= 10)->map(fn ($x) => [...$x, 'iqr' => round($x['q3'] - $x['q1'], 1)])->sortBy('iqr')->values();
        $leituras['dispersao'] = ['tom' => null, 'texto' => $comIqr->count() < 2 ? 'Poucos cursos com resultados suficientes para comparar a dispersão.'
            : "O curso mais homogêneo é {$comIqr->first()['nome']} (metade central dos estudantes numa faixa de ".self::pct($comIqr->first()['iqr']).' pp) e o mais heterogêneo, '.$comIqr->last()['nome'].' ('.self::pct($comIqr->last()['iqr']).' pp). Turmas heterogêneas pedem estratégias diferentes de turmas homogêneas com a mesma média.'];

        // Quadrante participação × proficiência
        $criticos = collect($cursos)->filter(fn ($x) => $x['participacao'] !== null && $x['proficienciaPct'] !== null && $x['participacao'] < $meta && $total['proficienciaPct'] !== null && $x['proficienciaPct'] < $total['proficienciaPct'])->map($nome)->all();
        $leituras['quadrante'] = ['tom' => $criticos === [] ? 'bom' : 'atencao', 'texto' => $criticos === []
            ? 'Nenhum curso está, ao mesmo tempo, abaixo da meta de participação e abaixo da proficiência geral do conjunto.'
            : count($criticos).' curso(s) estão abaixo da meta de participação E abaixo da proficiência geral do conjunto: '.self::lista($criticos).'. Nesses, o resultado tem dois problemas somados: parte da turma não fez a prova e quem fez foi pior que a média.'];

        // Trajetória
        $leituras += $this->leiturasDeTrajetoria($cursos, $total, $corte);

        // Competências
        if ($competencias !== null) {
            $leituras += $this->leiturasDeCompetencias($competencias);
        }

        // Evolução
        if ($evolucao !== null && count($evolucao['semestres']) >= 2) {
            $leituras += $this->leiturasDeEvolucao($evolucao, $ctx['avaliacao']['periodoLetivo'] ?? '');
        }

        return $leituras;
    }

    private function pontosDaMeta(?float $distancia): string
    {
        if ($distancia === null) {
            return '';
        }

        return $distancia >= 0 ? self::pct(abs($distancia)).' pp acima' : self::pct(abs($distancia)).' pp abaixo';
    }

    /** @param array<string, mixed> $est */
    private function pctFaixa(array $est, int $i): string
    {
        return self::pct($est['faixas'][$i]['pct'] ?? null);
    }

    /**
     * @param  array<int, array<string, mixed>>  $cursos
     * @param  array<string, mixed>  $total
     * @return array<string, array{texto: string, tom: ?string}>
     */
    private function leiturasDeTrajetoria(array $cursos, array $total, float $corte): array
    {
        $leituras = [];
        $periodos = array_values(array_filter($total['periodos'], fn ($p) => $p['n'] >= ReitorDashboardService::MINIMO_PERIODO));

        if (count($periodos) >= 2) {
            $melhor = collect($periodos)->sortByDesc('proficienciaPct')->first();
            $pior = collect($periodos)->sortBy('proficienciaPct')->first();
            $leituras['trajetoria_proficiencia'] = ['tom' => null, 'texto' => 'No conjunto, a proficiência vai de '.self::pct($pior['proficienciaPct'])."% no {$pior['rotulo']} período a ".self::pct($melhor['proficienciaPct'])."% no {$melhor['rotulo']}. "
                .($melhor['ordinal'] > $pior['ordinal'] ? 'Os períodos mais adiantados têm mais proficientes, como se espera.' : 'Os períodos iniciais estão melhor que os finais — vale investigar a trajetória.')];

            $primeiro = $periodos[0];
            $ultimo = $periodos[count($periodos) - 1];
            $delta = round($ultimo['media'] - $primeiro['media'], 1);
            $leituras['trajetoria_acerto'] = ['tom' => $delta >= 5 ? 'bom' : ($delta >= 0 ? 'atencao' : 'ruim'), 'texto' => 'A média passa de '.self::pct($primeiro['media'])."% no {$primeiro['rotulo']} período para ".self::pct($ultimo['media'])."% no {$ultimo['rotulo']} (".self::pp($delta).'). '
                .($delta >= 5 ? 'Há ganho visível ao longo do curso.' : ($delta >= 0 ? 'O ganho ao longo do curso é pequeno.' : 'Não há ganho visível: os períodos finais não vão melhor que os iniciais.'))];
        }

        $comPeriodos = collect($cursos)->filter(fn ($c) => count(array_filter($c['periodos'], fn ($p) => $p['n'] >= ReitorDashboardService::MINIMO_PERIODO)) >= 2);
        $leituras['mapa_periodo'] = ['tom' => null, 'texto' => $comPeriodos->isEmpty() ? 'Poucos períodos com resultados suficientes para o mapa.' : $this->leituraDoMapa($comPeriodos->all())];

        $crescimentos = $this->crescimentos($cursos);
        if (count($crescimentos) >= 2) {
            $melhor = $crescimentos[0];
            $pior = $crescimentos[count($crescimentos) - 1];
            $leituras['crescimento'] = ['tom' => $pior['inclinacao'] < 0 ? 'atencao' : null, 'texto' => "{$melhor['nome']} é o curso que mais cresce (".self::pp($melhor['inclinacao']).' por período) e '.$pior['nome'].' o que menos cresce ('.self::pp($pior['inclinacao']).' por período).'
                .($pior['inclinacao'] < 0 ? ' Em '.$pior['nome'].' a média cai ao longo dos períodos.' : '')];
        }

        $comLacuna = collect($cursos)->filter(fn ($c) => $c['ativosSemAplicacao'] !== []);
        $leituras['cobertura'] = ['tom' => $comLacuna->isEmpty() ? 'bom' : 'atencao', 'texto' => $comLacuna->isEmpty()
            ? 'Todos os períodos com alunos ativos tiveram aplicação.'
            : $comLacuna->count().' curso(s) têm períodos com alunos ativos sem aplicação: '.self::lista($comLacuna->map(fn ($c) => "{$c['nome']} ({$c['ativosSemAplicacaoRotulo']})")->values()->all(), 5).'.'];

        return $leituras;
    }

    /** @param array<int, array<string, mixed>> $cursos */
    private function leituraDoMapa(array $cursos): string
    {
        $melhor = $pior = null;
        foreach ($cursos as $curso) {
            foreach ($curso['periodos'] as $p) {
                if ($p['n'] < ReitorDashboardService::MINIMO_PERIODO || $p['media'] === null) {
                    continue;
                }
                if ($melhor === null || $p['media'] > $melhor['media']) {
                    $melhor = ['nome' => $curso['nome'], 'rotulo' => $p['rotulo'], 'media' => $p['media']];
                }
                if ($pior === null || $p['media'] < $pior['media']) {
                    $pior = ['nome' => $curso['nome'], 'rotulo' => $p['rotulo'], 'media' => $p['media']];
                }
            }
        }

        return $melhor === null ? 'Sem dados.' : "A maior média do mapa é a de {$melhor['nome']} no {$melhor['rotulo']} período (".self::pct($melhor['media'])."%) e a menor a de {$pior['nome']} no {$pior['rotulo']} (".self::pct($pior['media']).'%).';
    }

    /**
     * Inclinação (pp por período, mínimos quadrados ponderados pelos participantes) e diferença último − primeiro
     * período avaliado, por curso com ao menos dois períodos com participantes suficientes. Do que mais cresce ao
     * que menos cresce.
     *
     * @param  array<int, array<string, mixed>>  $cursos
     * @return array<int, array{chave: string, nome: string, inclinacao: float, delta: float, de: string, ate: string}>
     */
    public function crescimentos(array $cursos): array
    {
        $resultado = [];
        foreach ($cursos as $curso) {
            $pontos = array_values(array_filter($curso['periodos'], fn ($p) => $p['n'] >= ReitorDashboardService::MINIMO_PERIODO && $p['media'] !== null));
            if (count($pontos) < 2) {
                continue;
            }

            $w = array_sum(array_column($pontos, 'n'));
            $mx = array_sum(array_map(fn ($p) => $p['n'] * $p['ordinal'], $pontos)) / $w;
            $my = array_sum(array_map(fn ($p) => $p['n'] * $p['media'], $pontos)) / $w;
            $sxx = array_sum(array_map(fn ($p) => $p['n'] * ($p['ordinal'] - $mx) ** 2, $pontos));
            $sxy = array_sum(array_map(fn ($p) => $p['n'] * ($p['ordinal'] - $mx) * ($p['media'] - $my), $pontos));
            if ($sxx <= 0) {
                continue;
            }

            $primeiro = $pontos[0];
            $ultimo = $pontos[count($pontos) - 1];
            $resultado[] = [
                'chave' => $curso['chave'],
                'nome' => $curso['nome'],
                'inclinacao' => round($sxy / $sxx, 1),
                'delta' => round($ultimo['media'] - $primeiro['media'], 1),
                'de' => $primeiro['rotulo'],
                'ate' => $ultimo['rotulo'],
            ];
        }

        usort($resultado, fn ($a, $b) => $b['inclinacao'] <=> $a['inclinacao']);

        return $resultado;
    }

    /**
     * @param  array<string, mixed>  $competencias  saída de ReitorCompetenciasService::gerar()
     * @return array<string, array{texto: string, tom: ?string}>
     */
    private function leiturasDeCompetencias(array $competencias): array
    {
        $leituras = [];
        $bloom = $competencias['bloom'];

        if ($bloom['temDados']) {
            $niveis = collect($bloom['niveis']);
            $distancias = $niveis->map(function ($n) use ($bloom) {
                $p = $bloom['proficientes'][$n['chave']]['pct'] ?? null;
                $q = $bloom['naoProficientes'][$n['chave']]['pct'] ?? null;

                return $p !== null && $q !== null ? ['rotulo' => $n['rotulo'], 'distancia' => round($p - $q, 1)] : null;
            })->filter()->sortByDesc('distancia')->values();

            $leituras['bloom_distingue'] = ['tom' => null, 'texto' => $distancias->isEmpty() ? 'Sem questões com nível de Bloom e respostas suficientes.'
                : "A maior distância entre proficientes e não proficientes está em {$distancias->first()['rotulo']} (".self::pp($distancias->first()['distancia']).') e a menor em '.$distancias->last()['rotulo'].' ('.self::pp($distancias->last()['distancia']).'). Onde a distância é grande, a habilidade separa bem quem foi bem de quem não foi.'];

            $totais = $niveis->map(fn ($n) => ['rotulo' => $n['rotulo'], 'pct' => $bloom['total'][$n['chave']]['pct'] ?? null])->filter(fn ($n) => $n['pct'] !== null)->sortBy('pct')->values();
            $leituras['bloom_mapa'] = ['tom' => null, 'texto' => $totais->count() < 2 ? 'Sem níveis suficientes para comparar.'
                : "No conjunto, o nível com menor acerto é {$totais->first()['rotulo']} (".self::pct($totais->first()['pct'])."%) e o de maior acerto é {$totais->last()['rotulo']} (".self::pct($totais->last()['pct']).'%).'];
        }

        $ranking = $competencias['areas']['ranking'];
        if (count($ranking) >= 2) {
            $fraca = $ranking[0];
            $forte = $ranking[count($ranking) - 1];
            $leituras['areas_ranking'] = ['tom' => $fraca['pct'] < 40 ? 'ruim' : ($fraca['pct'] < 50 ? 'atencao' : null), 'texto' => "A área mais frágil é {$fraca['area']} (".self::pct($fraca['pct'])."% de acerto) e a mais forte é {$forte['area']} (".self::pct($forte['pct']).'%).'];
            $leituras['areas_mapa'] = $leituras['areas_ranking'];
        }

        return $leituras;
    }

    /**
     * @param  array{categoria: string, semestres: array<int, array<string, mixed>>}  $evolucao
     * @return array<string, array{texto: string, tom: ?string}>
     */
    private function leiturasDeEvolucao(array $evolucao, string $periodoSelecionado): array
    {
        $semestres = $evolucao['semestres'];
        $indice = collect($semestres)->search(fn ($s) => $s['periodoLetivo'] === $periodoSelecionado);
        $indice = $indice === false ? count($semestres) - 1 : $indice;
        if ($indice < 1) {
            return [];
        }

        $atual = $semestres[$indice];
        $anterior = $semestres[$indice - 1];
        $dProf = $atual['total']['proficienciaPct'] !== null && $anterior['total']['proficienciaPct'] !== null ? round($atual['total']['proficienciaPct'] - $anterior['total']['proficienciaPct'], 1) : null;
        $dMedia = $atual['total']['media'] !== null && $anterior['total']['media'] !== null ? round($atual['total']['media'] - $anterior['total']['media'], 1) : null;
        $dPart = $atual['total']['participacao'] !== null && $anterior['total']['participacao'] !== null ? round($atual['total']['participacao'] - $anterior['total']['participacao'], 1) : null;

        $partes = [];
        if ($dProf !== null) {
            $partes[] = 'a proficiência '.($dProf >= 0 ? 'subiu' : 'caiu').' '.self::pct(abs($dProf))." pp ({$this->p($anterior['total']['proficienciaPct'])}% → {$this->p($atual['total']['proficienciaPct'])}%)";
        }
        if ($dMedia !== null) {
            $partes[] = 'a média '.($dMedia >= 0 ? 'subiu' : 'caiu').' '.self::pct(abs($dMedia)).' pp';
        }
        if ($dPart !== null) {
            $partes[] = 'a participação '.($dPart >= 0 ? 'subiu' : 'caiu').' '.self::pct(abs($dPart)).' pp';
        }

        $variacoes = [];
        foreach ($atual['cursos'] as $chave => $c) {
            $antes = $anterior['cursos'][$chave] ?? null;
            if ($antes !== null && $c['proficienciaPct'] !== null && $antes['proficienciaPct'] !== null) {
                $variacoes[] = ['chave' => $chave, 'delta' => round($c['proficienciaPct'] - $antes['proficienciaPct'], 1)];
            }
        }
        usort($variacoes, fn ($a, $b) => $b['delta'] <=> $a['delta']);

        $texto = "De {$anterior['periodoLetivo']} para {$atual['periodoLetivo']}: ".implode('; ', $partes).'.';

        return [
            'evolucao_institucional' => ['tom' => $dProf === null ? null : ($dProf >= 1 ? 'bom' : ($dProf <= -1 ? 'ruim' : 'atencao')), 'texto' => $texto],
            'evolucao_cursos' => ['tom' => null, 'texto' => $variacoes === [] ? 'Sem cursos com resultado nos dois períodos.'
                : 'Entre os cursos presentes nos dois períodos, '.count(array_filter($variacoes, fn ($v) => $v['delta'] >= ReitorEvolucaoService::VARIACAO_RELEVANTE)).' melhoraram e '.count(array_filter($variacoes, fn ($v) => $v['delta'] <= -ReitorEvolucaoService::VARIACAO_RELEVANTE)).' pioraram a proficiência em '.ReitorEvolucaoService::VARIACAO_RELEVANTE.' pp ou mais; o restante ficou estável.'],
        ];
    }

    private function p(?float $v): string
    {
        return self::pct($v);
    }

    /**
     * @param  array<string, mixed>  $analise  saída de ReitorItensService::gerar()
     * @return array<string, array{texto: string, tom: ?string}>
     */
    public function leiturasDeItens(array $analise): array
    {
        $t = $analise['total'];
        if ($t['itens'] === 0) {
            return [];
        }

        $c = $analise['contagem'];
        $tom = $t['pctARevisar'] < 10 ? 'bom' : ($t['pctARevisar'] < 25 ? 'atencao' : 'ruim');
        $mapa = self::pct($t['pctARevisar']).'% dos itens ('.$t['aRevisar'].' de '.$t['itens'].') têm algum diagnóstico: '
            .$c['gabarito'].' com gabarito suspeito, '.$c['questao'].' com provável problema da questão, '.$c['formacao'].' com lacuna de formação, '
            .$c['fraco'].' fracos e '.$c['todos_cursos'].' baixos em todos os cursos. (Referência do tom: abaixo de 10% bom, 10–24% atenção, acima disso precisa de ação.)';

        $leituras = ['itens_mapa' => ['tom' => $tom, 'texto' => $mapa]];

        if ($analise['criticos'] !== []) {
            $i = $analise['criticos'][0];
            $leituras['itens_lista'] = ['tom' => ReitorItensService::DIAGNOSTICOS[$i['diagnostico']][2], 'texto' => 'O item mais urgente é a questão '.$i['numero'].' de '.$i['avaliacaoNome'].' ('.ReitorItensService::DIAGNOSTICOS[$i['diagnostico']][0].', acerto de '.self::pct($i['dificuldade']).'%). '.$i['explicacao']];
        }

        $areas = array_values(array_filter($analise['areas'], fn ($a) => $a['itens'] >= 5));
        if (count($areas) >= 2) {
            $pior = $areas[0];
            $melhor = $areas[count($areas) - 1];
            $leituras['itens_areas'] = ['tom' => null, 'texto' => "A área com maior proporção de itens a revisar é {$pior['area']} (".self::pct($pior['pctARevisar'])."% de {$pior['itens']} itens) e a menor é {$melhor['area']} (".self::pct($melhor['pctARevisar']).'% de '.$melhor['itens'].' itens).'];
        }

        return $leituras;
    }

    /**
     * @param  array<string, mixed>  $risco  saída de ReitorRiscoService::gerar()
     * @return array<string, array{texto: string, tom: ?string}>
     */
    public function leiturasDeRisco(array $risco): array
    {
        $total = $risco['total'];
        $regra = $risco['regra'];
        if (! $risco['temDados']) {
            return ['risco_cursos' => ['tom' => 'atencao', 'texto' => 'Neste recorte há poucos estudantes ('.$total['pessoas'].') para mostrar percentuais. Escolha uma categoria com mais avaliações ou "Todos os períodos".']];
        }

        $cursos = collect($risco['cursos'])->filter(fn ($c) => $c['pctRisco'] !== null)->sortByDesc('pctRisco')->values();
        $tom = $total['pctRisco'] === null ? null : ($total['pctRisco'] < 10 ? 'bom' : ($total['pctRisco'] < 25 ? 'atencao' : 'ruim'));
        $texto = self::pct($total['pctRisco']).'% dos estudantes ('.$total['risco'].' de '.$total['pessoas'].') estão em risco pela regra da instituição ('.$regra['descricao'].')';
        $partes = [];
        if ($regra['faltas'] !== null) {
            $partes[] = self::pct($total['pctPorFalta']).'% pelas faltas';
        }
        if ($regra['acertoAtivo']) {
            $partes[] = self::pct($total['pctPorAcerto']).'% pelo acerto';
        }
        $texto .= $partes === [] ? '.' : ': '.implode(' e ', $partes).'.';
        if ($cursos->count() >= 2) {
            $texto .= " O maior risco é o de {$cursos->first()['nome']} (".self::pct($cursos->first()['pctRisco'])."%) e o menor o de {$cursos->last()['nome']} (".self::pct($cursos->last()['pctRisco']).'%).';
        }
        if ($total['deltaRisco'] !== null && abs($total['deltaRisco']) >= 1) {
            $texto .= ' Frente a '.$risco['semestreAnterior'].', o risco '.($total['deltaRisco'] > 0 ? 'subiu ' : 'caiu ').self::pct(abs($total['deltaRisco'])).' pp.';
        }
        $texto .= ' (Referência do tom: abaixo de 10% bom, 10–24% atenção, acima disso precisa de ação.)';

        $porFalta = collect($risco['cursos'])->filter(fn ($c) => $c['pctPorFalta'] !== null)->sortByDesc('pctPorFalta')->first();
        $porAcerto = collect($risco['cursos'])->filter(fn ($c) => $c['pctPorAcerto'] !== null)->sortByDesc('pctPorAcerto')->first();
        $grafico = [];
        if ($regra['faltas'] !== null) {
            $grafico[] = $porFalta !== null ? "O maior percentual por faltas é o de {$porFalta['nome']} (".self::pct($porFalta['pctPorFalta']).'%)' : 'Sem curso com estudantes suficientes para as faltas';
        }
        if ($regra['acertoAtivo']) {
            $grafico[] = $porAcerto !== null ? "o maior por acerto, o de {$porAcerto['nome']} (".self::pct($porAcerto['pctPorAcerto']).'%)' : 'sem curso com estudantes suficientes para o acerto';
        }

        $maior = null;
        foreach ($risco['cursos'] as $c) {
            foreach ($c['periodos'] as $p) {
                if ($p['pctRisco'] !== null && ($maior === null || $p['pctRisco'] > $maior['pct'])) {
                    $maior = ['curso' => $c['nome'], 'rotulo' => $p['rotulo'], 'pct' => $p['pctRisco']];
                }
            }
        }

        return [
            'risco_cursos' => ['tom' => $tom, 'texto' => $texto],
            'risco_grafico' => ['tom' => null, 'texto' => $grafico === [] ? 'Nenhum critério de risco ligado.' : implode('; ', $grafico).'.'],
            'risco_mapa' => ['tom' => null, 'texto' => $maior === null ? 'Sem períodos do curso com estudantes suficientes.' : "O maior risco do mapa é o de {$maior['curso']} no {$maior['rotulo']} período (".self::pct($maior['pct']).'%).'],
        ];
    }

    // ------------------------------------------------------------------------------------------------------------
    // Pontos de atenção da visão geral
    // ------------------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $est
     * @param  ?array{categoria: string, semestres: array<int, array<string, mixed>>}  $evolucao
     * @return array<int, array{tom: string, icone: string, texto: string, ver: ?array{0: string, 1: string, 2: string}}>
     */
    public function pontosDeAtencao(array $ctx, array $est, ?array $evolucao = null): array
    {
        $cursos = array_values($est['cursos']);
        $total = $est['total'];
        $meta = $ctx['meta'];
        $cartoes = [];

        $abaixoMeta = collect($cursos)->filter(fn ($c) => $c['participacao'] !== null && $c['participacao'] < $meta)->sortBy('participacao')->values();
        if ($abaixoMeta->isNotEmpty()) {
            $cartoes[] = [
                'tom' => 'atencao',
                'icone' => 'ph-user-minus',
                'texto' => $abaixoMeta->count().' de '.count($cursos).' cursos estão abaixo da meta de participação de '.self::pct($meta).'%: '
                    .self::lista($abaixoMeta->map(fn ($c) => "{$c['nome']} (".self::pct($c['participacao']).'%)')->all(), 3)
                    .". Faltam {$total['alunosAMais']} estudantes para todos chegarem à meta.",
            ];
        } elseif ($cursos !== []) {
            $cartoes[] = ['tom' => 'positivo', 'icone' => 'ph-user-check', 'texto' => 'Todos os cursos estão na meta de participação ('.self::pct($meta).'%).'];
        }

        $duplo = collect($cursos)->filter(fn ($c) => $c['difParticipacao'] !== null && $c['difProficiencia'] !== null && $c['difParticipacao'] < 0 && $c['difProficiencia'] < 0)->sortBy('difProficiencia')->values();
        if ($duplo->isNotEmpty()) {
            $cartoes[] = [
                'tom' => 'atencao',
                'icone' => 'ph-warning-circle',
                'texto' => 'Abaixo do conjunto em participação e em proficiência ao mesmo tempo: '.self::lista($duplo->map(fn ($c) => $c['nome'])->all(), 4).'.',
            ];
        }

        $semAplicacao = collect($cursos)->filter(fn ($c) => $c['ativosSemAplicacao'] !== []);
        if ($semAplicacao->isNotEmpty()) {
            $cartoes[] = [
                'tom' => 'atencao',
                'icone' => 'ph-calendar-x',
                'texto' => 'Alunos ativos em períodos sem aplicação da prova: '.self::lista($semAplicacao->map(fn ($c) => "{$c['nome']} ({$c['ativosSemAplicacaoRotulo']})")->values()->all(), 3).'.',
            ];
        }
        if ($est['semAplicacao'] !== []) {
            $cartoes[] = [
                'tom' => 'atencao',
                'icone' => 'ph-prohibit',
                'texto' => 'Cursos com alunos ativos e nenhuma aplicação nesta avaliação: '.self::lista(array_column($est['semAplicacao'], 'nome'), 3).'.',
            ];
        }

        $porProf = collect($cursos)->filter(fn ($c) => $c['proficienciaPct'] !== null && $c['n'] >= 10)->sortByDesc('proficienciaPct')->values();
        if ($porProf->count() >= 2) {
            $cartoes[] = [
                'tom' => 'neutro',
                'icone' => 'ph-target',
                'texto' => "Maior proporção de proficientes: {$porProf->first()['nome']} (".self::pct($porProf->first()['proficienciaPct'])."%). Menor: {$porProf->last()['nome']} (".self::pct($porProf->last()['proficienciaPct']).'%).',
            ];
        }

        if ($evolucao !== null && count($evolucao['semestres']) >= 2) {
            $leitura = $this->leiturasDeEvolucao($evolucao, $ctx['avaliacao']['periodoLetivo'] ?? '');
            if (isset($leitura['evolucao_institucional'])) {
                $tom = $leitura['evolucao_institucional']['tom'];
                $cartoes[] = [
                    'tom' => $tom === 'bom' ? 'positivo' : ($tom === 'ruim' ? 'atencao' : 'neutro'),
                    'icone' => $tom === 'bom' ? 'ph-trend-up' : ($tom === 'ruim' ? 'ph-trend-down' : 'ph-minus'),
                    'texto' => $leitura['evolucao_institucional']['texto'],
                ];
            }
        }

        // Cada ponto de atenção aponta para o quadro de onde saiu (rota + âncora), para o reitor conferir os números.
        return array_map(fn ($c) => $c + ['ver' => self::ORIGEM_DOS_PONTOS[$c['icone']] ?? null], $cartoes);
    }
}
