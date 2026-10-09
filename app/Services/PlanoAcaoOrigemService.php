<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\PlanoAcao;
use App\Support\NomeCurso;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * O ponto de partida de um plano de ação: dado "de onde o coordenador clicou" (curso, período letivo, categoria ou
 * avaliação, o visual e, se for o caso, o item — uma área, um nível de Bloom, um período do curso...), monta TUDO que o
 * plano já traz preenchido: identificação do curso, os indicadores (participação, meta, proficiência), as linhas de dados
 * do visual e as sugestões de texto para a leitura do dado.
 *
 * Nada vem pronto do navegador: a URL só diz ONDE o coordenador estava; os números são recalculados aqui, a partir dos
 * mesmos serviços do painel (CoordenadorDashboardService e PlanoAcaoIndicadoresService), e só para os cursos dele.
 *
 * Só dado agregado. O plano é lido pelo colaborador, que não enxerga o painel de resultados — por isso o dado que
 * motivou o plano viaja DENTRO dele (`contexto`), e nunca com nome, RA ou CPF de aluno.
 */
class PlanoAcaoOrigemService
{
    /** Linhas de dados guardadas no plano (o visual pode ter dezenas de itens; o resto é ruído numa análise). */
    private const MAXIMO_LINHAS = 20;

    /** Abaixo disto o item entra na lista de fragilidades sugerida (o mesmo corte do resto do painel). */
    private const LIMIAR = CoordenadorDashboardService::LIMIAR_ADEQUADO;

    public function __construct(
        private readonly CoordenadorDashboardService $dashboard,
        private readonly PlanoAcaoIndicadoresService $indicadores,
    ) {}

    /**
     * @param  array<string, mixed>  $params  curso, periodo_letivo, categoria, avaliacao, visual, item (todos opcionais)
     * @return array<string, mixed>
     */
    public function montar(Admin $coordenador, array $params): array
    {
        $visual = isset(PlanoAcao::VISUAIS[(string) ($params['visual'] ?? '')]) ? (string) $params['visual'] : 'geral';
        $item = trim((string) ($params['item'] ?? ''));
        $item = $item === '' ? null : mb_substr($item, 0, 255);

        $base = [
            'visual' => $visual,
            'item' => $item,
            'rotulo' => $this->rotulo($visual, $item),
            'cursos' => $coordenador->cursos(),
        ];

        // Qual curso? O pedido, se for um dos dele; senão o único que ele tem; senão ele escolhe na tela.
        // No comparativo entre cursos o "item" clicado é o próprio curso.
        $pedido = $visual === 'curso' && $item !== null ? $item : trim((string) ($params['curso'] ?? ''));
        $curso = null;
        foreach ($base['cursos'] as $meu) {
            if ($pedido !== '' && NomeCurso::mesmo($meu, $pedido)) {
                $curso = $meu;
            }
        }
        $curso ??= count($base['cursos']) === 1 ? $base['cursos'][0] : null;
        if ($curso === null) {
            return [...$base, 'curso' => null, 'precisaCurso' => $base['cursos'] !== [], 'semDados' => true];
        }

        $periodoPedido = array_key_exists('periodo_letivo', $params) ? (string) $params['periodo_letivo'] : null;
        $escopo = $this->dashboard->escopo($coordenador, $curso, $periodoPedido);
        if (! empty($escopo['semCurso']) || ! empty($escopo['semResultados'])) {
            return [...$base, 'curso' => $curso, 'semDados' => true];
        }

        // Uma avaliação pedida define o período e a categoria dela.
        $avaliacao = ctype_digit((string) ($params['avaliacao'] ?? '')) ? (int) $params['avaliacao'] : null;
        $categoriaId = ctype_digit((string) ($params['categoria'] ?? '')) && (int) $params['categoria'] > 0 ? (int) $params['categoria'] : null;
        if ($avaliacao !== null) {
            $daAvaliacao = $escopo['avaliacoes']->firstWhere('codigo', $avaliacao);
            if ($daAvaliacao === null) {
                $avaliacao = null;
            } else {
                $categoriaId = $daAvaliacao['categoriaId'];
                $escopo = $this->dashboard->escopo($coordenador, $curso, $daAvaliacao['periodoLetivo']);
            }
        }
        $periodo = $escopo['periodoSelecionado'];

        $painel = $this->dashboard->gerar($coordenador, $curso, $periodo, $escopo, [
            'detalhado' => true,
            'categoria' => $categoriaId !== null ? (string) $categoriaId : '',
        ]);
        $categorias = collect($painel['categorias'] ?? []);
        $disponiveis = $categorias->map(fn ($c) => ['id' => $c['id'], 'nome' => $c['nome']])->values()->all();

        // Sem categoria pedida e mais de uma no período: os visuais que dependem da prova (área, Bloom, evolução...)
        // usam a categoria com mais participações — e a tela diz isso, para o coordenador trocar se quiser. Os números
        // gerais (participação, proficiência, visão geral) seguem sobre o período inteiro.
        $automatica = false;
        $bloco = $categoriaId !== null ? $categorias->firstWhere('id', $categoriaId) : null;
        if ($bloco === null && $categorias->count() === 1) {
            $bloco = $categorias->first();
            $categoriaId = $bloco['id'];
        } elseif ($bloco === null && $categorias->count() > 1 && ! in_array($visual, ['geral', 'participacao', 'proficiencia', 'destaque', 'curso'], true)) {
            $bloco = $categorias->sortByDesc(fn ($c) => $c['totais']['inscritos'])->first();
            $categoriaId = $bloco['id'];
            $automatica = $categoriaId !== null;
        }

        $indicadores = $this->indicadores->calcular($curso, $periodo, $categoriaId, $avaliacao);

        // As avaliações do recorte: a pedida, ou as do período na categoria (todas do período se não há categoria). O plano
        // guarda esta lista para quem o analisa poder ir até elas.
        $avaliacoesDoRecorte = $escopo['doPeriodo']
            ->filter(fn ($a) => $avaliacao !== null ? $a['codigo'] === $avaliacao : ($categoriaId === null || $a['categoriaId'] === $categoriaId))
            ->sortBy(fn ($a) => [$a['data'] ?? '9999-12-31', $a['codigo']])
            ->take(20)
            ->map(fn ($a) => ['codigo' => $a['codigo'], 'nome' => $a['nome'], 'data' => $a['data'], 'periodoLetivo' => $a['periodoLetivo']])
            ->values()
            ->all();

        $leitura = match ($visual) {
            'area', 'bloom', 'tema' => $this->campo($visual, $item, $bloco, $periodo),
            'evolucao' => $this->evolucao($bloco),
            'periodo_curso' => $this->periodoDoCurso($item, $bloco),
            'curso' => $this->cursoComparado($coordenador, $curso, $periodo, $categoriaId),
            'avaliacao' => $this->avaliacao($avaliacao ?? (int) $item, $categorias),
            'destaque' => $this->destaque($item),
            default => $this->geral($visual, $indicadores, $bloco, $painel),
        };

        $nomeCategoria = $bloco['nome'] ?? ($categoriaId !== null ? ($categorias->firstWhere('id', $categoriaId)['nome'] ?? null) : null);
        $recorte = $this->recorteEmTexto($periodo, $nomeCategoria, $avaliacao, $escopo);

        return [
            ...$base,
            'curso' => $curso,
            'periodo_letivo' => $periodo,
            'periodos' => $escopo['periodosDisponiveis'],
            'categoria_id' => $categoriaId,
            'categoria_nome' => $nomeCategoria,
            'categoria_automatica' => $automatica,
            'categorias' => $disponiveis,
            'avaliacao_codigo' => $avaliacao,
            'avaliacoes' => $avaliacoesDoRecorte,
            'indicadores' => $indicadores,
            'linhas' => $leitura['linhas'],
            'sugestoes' => [
                'recorte' => $leitura['recorte'] !== null ? "{$recorte} · {$leitura['recorte']}" : $recorte,
                'resultado' => $leitura['resultado'],
                'fragilidades' => $leitura['fragilidades'],
                'evidencias' => $leitura['evidencias'],
            ],
            'semDados' => $bloco === null && $indicadores === null,
        ];
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Texto
    // ---------------------------------------------------------------------------------------------------------------

    public function rotulo(string $visual, ?string $item): string
    {
        $nome = PlanoAcao::VISUAIS[$visual]['rotulo'] ?? 'Painel';

        return $item !== null && $item !== '' && $visual !== 'destaque' && $visual !== 'avaliacao' ? "{$nome} · {$item}" : $nome;
    }

    private function pct(?float $valor): string
    {
        return $valor === null ? '—' : CoordenadorDashboardService::pct($valor).'%';
    }

    /** @param array<string, mixed> $escopo */
    private function recorteEmTexto(string $periodo, ?string $categoria, ?int $avaliacao, array $escopo): string
    {
        $partes = [$periodo !== '' ? "Período letivo {$periodo}" : 'Todos os períodos letivos'];
        if ($categoria !== null) {
            $partes[] = "Categoria {$categoria}";
        }
        if ($avaliacao !== null && ($a = $escopo['avaliacoes']->firstWhere('codigo', $avaliacao)) !== null) {
            $partes[] = "Avaliação {$a['nome']}";
        }

        return implode(' · ', $partes);
    }

    /** @return array{rotulo: string, valor: string, detalhe: ?string, destaque: bool} */
    private function linha(string $rotulo, string $valor, ?string $detalhe = null, bool $destaque = false): array
    {
        return ['rotulo' => $rotulo, 'valor' => $valor, 'detalhe' => $detalhe, 'destaque' => $destaque];
    }

    /**
     * @param  array<int, array{rotulo: string, valor: string, detalhe: ?string, destaque: bool}>  $linhas
     * @return array{linhas: array<int, array<string, mixed>>, recorte: ?string, resultado: string, fragilidades: string, evidencias: string}
     */
    private function leitura(array $linhas, ?string $recorte, string $resultado, string $fragilidades, string $evidencias): array
    {
        return [
            'linhas' => array_slice($linhas, 0, self::MAXIMO_LINHAS),
            'recorte' => $recorte,
            'resultado' => $resultado,
            'fragilidades' => $fragilidades,
            'evidencias' => $evidencias,
        ];
    }

    /** "- rótulo: valor (detalhe)" por linha: o texto que a coordenação pode editar na etapa "Leitura". */
    private function listar(array $linhas): string
    {
        return implode("\n", array_map(
            fn ($l) => "- {$l['rotulo']}: {$l['valor']}".($l['detalhe'] ? " ({$l['detalhe']})" : ''),
            array_slice($linhas, 0, self::MAXIMO_LINHAS),
        ));
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Um visual por vez
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Visão geral, participação ou proficiência: os indicadores do recorte e, se houver, onde está o ponto mais fraco.
     *
     * @param  ?array<string, mixed>  $ind
     * @param  ?array<string, mixed>  $bloco
     * @param  array<string, mixed>  $painel
     */
    private function geral(string $visual, ?array $ind, ?array $bloco, array $painel): array
    {
        $linhas = [];
        $evidencias = [];
        $resultado = '';

        if ($ind !== null) {
            $dist = $ind['participacao'] !== null ? round($ind['participacao'] - $ind['meta_participacao'], 1) : null;
            $linhas[] = $this->linha('Participação', $this->pct($ind['participacao']), "{$ind['fizeram']} de {$ind['previstos']} previstos; meta ".$this->pct($ind['meta_participacao']), $visual === 'participacao');
            $linhas[] = $this->linha('Proficiência', $this->pct($ind['proficiencia']), 'proficiente = '.(int) $ind['corte'].'% ou mais de acerto; '.$ind['com_nota'].' presentes com nota', $visual === 'proficiencia');
            if ($ind['media'] !== null) {
                $linhas[] = $this->linha('Média de acerto', $this->pct($ind['media']), 'só presentes');
            }
            foreach ($ind['periodos'] as $p) {
                $linhas[] = $this->linha("{$p['ordinal']}º período", $this->pct($visual === 'participacao' ? $p['participacao'] : $p['proficiencia']), $visual === 'participacao' ? "{$p['fizeram']} de {$p['previstos']} previstos" : null);
            }

            if ($visual !== 'proficiencia' && $ind['participacao'] !== null) {
                $evidencias[] = 'Participação de '.$this->pct($ind['participacao'])." ({$ind['fizeram']} de {$ind['previstos']} previstos), "
                    .($dist >= 0 ? $this->pct(abs($dist)).' acima' : $this->pct(abs($dist)).' abaixo').' da meta de '.$this->pct($ind['meta_participacao']).'.';
            }
            if ($visual !== 'participacao' && $ind['proficiencia'] !== null) {
                $evidencias[] = $this->pct($ind['proficiencia']).' dos presentes com nota atingiram '.(int) $ind['corte'].'% de acerto ('.$ind['com_nota'].' estudantes avaliados).';
            }

            $resultado = match ($visual) {
                'participacao' => $ind['participacao'] !== null ? 'Participação de '.$this->pct($ind['participacao']).', '.($dist >= 0 ? 'dentro da' : 'abaixo da').' meta de '.$this->pct($ind['meta_participacao']).'.' : '',
                'proficiencia' => $ind['proficiencia'] !== null ? 'Apenas '.$this->pct($ind['proficiencia']).' dos estudantes presentes atingiram o corte de proficiência ('.(int) $ind['corte'].'% de acerto).' : '',
                default => 'Participação de '.$this->pct($ind['participacao']).' e proficiência de '.$this->pct($ind['proficiencia']).'.',
            };

            // O período do curso com o indicador mais fraco (com amostra mínima, para não destacar uma turma de 3 alunos).
            $campo = $visual === 'participacao' ? 'participacao' : 'proficiencia';
            $fracos = collect($ind['periodos'])->filter(fn ($p) => $p[$campo] !== null && $p['fizeram'] >= 5)->sortBy($campo)->take(2);
            if ($fracos->isNotEmpty()) {
                $evidencias[] = 'Períodos do curso com menor '.($campo === 'participacao' ? 'participação' : 'proficiência').': '
                    .$fracos->map(fn ($p) => "{$p['ordinal']}º ({$this->pct($p[$campo])})")->implode(', ').'.';
            }
        }

        $fragilidades = $bloco !== null ? $this->fragilidadesDoBloco($bloco) : '';
        if ($fragilidades !== '') {
            $evidencias[] = $fragilidades;
        }

        return $this->leitura($linhas, null, $resultado, $fragilidades, implode("\n", $evidencias));
    }

    /** "Áreas mais fracas: X (43,8%)... Níveis de Bloom: ..." — só o que está abaixo do corte. */
    private function fragilidadesDoBloco(array $bloco): string
    {
        $nomes = ['area' => 'Áreas', 'bloom' => 'Níveis de Bloom', 'tema' => 'Temas'];
        $partes = [];
        foreach ($nomes as $tipo => $rotulo) {
            $fracos = collect($bloco['detalhe']['campos'][$tipo]['geral'] ?? [])->filter(fn ($i) => $i['percentual'] < self::LIMIAR)->take(3);
            if ($fracos->isNotEmpty()) {
                $partes[] = "{$rotulo} com menor acerto: ".$fracos->map(fn ($i) => "{$i['rotulo']} (".$this->pct($i['percentual']).')')->implode(', ').'.';
            }
        }

        return implode("\n", $partes);
    }

    /**
     * Área, nível de Bloom ou tema: o item clicado (ou o gráfico inteiro) frente aos demais.
     *
     * @param  ?array<string, mixed>  $bloco
     */
    private function campo(string $tipo, ?string $item, ?array $bloco, string $periodo): array
    {
        $nomes = ['area' => ['área', 'Áreas'], 'bloom' => ['nível de Bloom', 'Níveis de Bloom'], 'tema' => ['tema', 'Temas']];
        [$singular, $plural] = $nomes[$tipo];
        $grupo = collect($bloco['detalhe']['campos'][$tipo]['geral'] ?? []);
        if ($grupo->isEmpty()) {
            return $this->leitura([], null, '', '', '');
        }

        $media = $bloco['totais']['media'] ?? null;
        $linhas = $grupo->map(fn ($i) => $this->linha($i['rotulo'], $this->pct($i['percentual']), $i['respostas'].' respostas', $item !== null && $i['rotulo'] === $item))->all();

        $escolhido = $item !== null ? $grupo->first(fn ($i) => $i['rotulo'] === $item) : null;
        if ($escolhido !== null) {
            $posicao = $grupo->search(fn ($i) => $i['rotulo'] === $item) + 1;
            $dif = $media !== null ? round($escolhido['percentual'] - $media, 1) : null;
            $resultado = Str::ucfirst($singular)." {$item}: ".$this->pct($escolhido['percentual'])." de acerto em {$escolhido['respostas']} respostas"
                .($dif !== null ? ', '.$this->pct(abs($dif)).($dif < 0 ? ' abaixo' : ' acima').' da média da categoria ('.$this->pct($media).')' : '').'.';
            $evidencias = ["{$item}: ".$this->pct($escolhido['percentual'])." de acerto ({$escolhido['respostas']} respostas), {$posicao}ª posição entre {$grupo->count()} (da mais fraca para a mais forte)."];

            // Por período do curso: onde, dentro do item, o acerto é pior.
            $porPeriodo = collect($bloco['detalhe']['campos'][$tipo]['porPeriodo'] ?? [])
                ->map(fn ($itens, $ordinal) => ['ordinal' => (int) $ordinal, 'valor' => $itens[$item] ?? null])
                ->filter(fn ($p) => $p['valor'] !== null)->values();
            foreach ($porPeriodo as $p) {
                $linhas[] = $this->linha("{$item} · {$p['ordinal']}º período", $this->pct($p['valor']), null, true);
            }
            if ($porPeriodo->isNotEmpty()) {
                $evidencias[] = 'Por período do curso: '.$porPeriodo->map(fn ($p) => "{$p['ordinal']}º ({$this->pct($p['valor'])})")->implode(', ').'.';
            }

            return $this->leitura($linhas, Str::ucfirst($singular).": {$item}", $resultado, "{$item} (".$this->pct($escolhido['percentual']).')', implode("\n", $evidencias));
        }

        $fracos = $grupo->filter(fn ($i) => $i['percentual'] < self::LIMIAR)->take(5);
        $resultado = $fracos->isNotEmpty()
            ? "{$plural} com acerto abaixo de ".(int) self::LIMIAR.'%: '.$fracos->map(fn ($i) => "{$i['rotulo']} (".$this->pct($i['percentual']).')')->implode(', ').'.'
            : "Nenhum(a) {$singular} abaixo de ".(int) self::LIMIAR.'% de acerto; o mais fraco é '.$grupo->first()['rotulo'].' ('.$this->pct($grupo->first()['percentual']).').';

        return $this->leitura($linhas, "Desempenho por {$singular}", $resultado, $fracos->map(fn ($i) => "{$i['rotulo']} (".$this->pct($i['percentual']).')')->implode('; '), $this->listar($linhas));
    }

    /** @param ?array<string, mixed> $bloco */
    private function evolucao(?array $bloco): array
    {
        $pontos = collect($bloco['detalhe']['evolucao'] ?? []);
        if ($pontos->isEmpty()) {
            return $this->leitura([], null, '', '', '');
        }

        $comMeta = (bool) ($bloco['detalhe']['comMeta'] ?? false);
        $valor = fn (array $p) => $comMeta ? $p['geral']['pct'] : $p['geral']['media'];
        $linhas = $pontos->map(fn ($p) => $this->linha($p['nome'].($p['periodoLetivo'] !== '' ? " ({$p['periodoLetivo']})" : ''), $this->pct($valor($p)), $comMeta && $p['geral']['presentes'] > 0 ? "{$p['geral']['dentro']} de {$p['geral']['presentes']} alunos" : null, $p['noPeriodo']))->all();

        $primeiro = $pontos->first();
        $ultimo = $pontos->last();
        $dif = $valor($primeiro) !== null && $valor($ultimo) !== null ? round($valor($ultimo) - $valor($primeiro), 1) : null;
        $indicador = $comMeta ? 'dos alunos dentro do esperado' : 'da média de acerto';
        $resultado = $dif === null ? '' : ($dif === 0.0
            ? "Estabilidade {$indicador} entre {$primeiro['nome']} e {$ultimo['nome']}."
            : ($dif > 0 ? 'Avanço' : 'Queda')." {$indicador} de ".$this->pct(abs($dif)).' entre '.$primeiro['nome'].' ('.$this->pct($valor($primeiro)).') e '.$ultimo['nome'].' ('.$this->pct($valor($ultimo)).').');

        return $this->leitura($linhas, 'Evolução entre as avaliações da categoria', $resultado, '', $this->listar($linhas));
    }

    /** @param ?array<string, mixed> $bloco */
    private function periodoDoCurso(?string $item, ?array $bloco): array
    {
        $periodos = collect($bloco['porPeriodoDoCurso'] ?? []);
        if ($periodos->isEmpty()) {
            return $this->leitura([], null, '', '', '');
        }

        $linhas = $periodos->map(fn ($p) => $this->linha($p['rotulo'], $this->pct($p['media']), $p['presentes'].' presentes', $item !== null && $p['rotulo'] === $item))->all();
        $escolhido = $item !== null ? $periodos->first(fn ($p) => $p['rotulo'] === $item) : null;

        if ($escolhido !== null) {
            $media = $bloco['totais']['media'] ?? null;
            $resultado = "{$escolhido['rotulo']}: média de ".$this->pct($escolhido['media']).' ('.$escolhido['presentes'].' presentes)'
                .($media !== null ? ', frente a '.$this->pct($media).' na categoria' : '').'.';

            return $this->leitura($linhas, $escolhido['rotulo'], $resultado, $escolhido['rotulo'].' ('.$this->pct($escolhido['media']).')', $this->listar($linhas));
        }

        $pior = $periodos->sortBy('media')->first();

        return $this->leitura($linhas, 'Desempenho por período do curso', "O {$pior['rotulo']} tem a menor média da categoria (".$this->pct($pior['media']).').', "{$pior['rotulo']} (".$this->pct($pior['media']).')', $this->listar($linhas));
    }

    /** O curso escolhido no comparativo entre os cursos do coordenador (só dos cursos dele). */
    private function cursoComparado(Admin $coordenador, string $curso, string $periodo, ?int $categoriaId): array
    {
        $todos = $this->dashboard->gerar($coordenador, '', $periodo, null, ['categoria' => $categoriaId !== null ? (string) $categoriaId : '']);
        $bloco = collect($todos['categorias'] ?? [])->first(fn ($c) => $categoriaId === null || $c['id'] === $categoriaId);
        $cursos = collect($bloco['porCurso'] ?? []);
        if ($cursos->isEmpty()) {
            return $this->leitura([], null, '', '', '');
        }

        $linhas = $cursos->map(fn ($c) => $this->linha($c['curso'], $this->pct($c['media']), $c['presentes'].' presentes; '.$this->pct($c['abaixoPct']).' abaixo de '.(int) self::LIMIAR.'%', NomeCurso::mesmo($c['curso'], $curso)))->all();
        $meu = $cursos->first(fn ($c) => NomeCurso::mesmo($c['curso'], $curso));
        $resultado = $meu !== null ? "{$curso}: média de ".$this->pct($meu['media']).' na categoria, com '.$this->pct($meu['abaixoPct']).' dos presentes abaixo de '.(int) self::LIMIAR.'%.' : '';

        return $this->leitura($linhas, "Comparativo entre cursos · {$curso}", $resultado, '', $this->listar($linhas));
    }

    /** @param Collection<int, array<string, mixed>> $categorias */
    private function avaliacao(int $codigo, Collection $categorias): array
    {
        $a = $categorias->flatMap(fn ($c) => $c['avaliacoes'])->firstWhere('codigo', $codigo);
        if ($a === null) {
            return $this->leitura([], null, '', '', '');
        }

        $linhas = [
            $this->linha('Presença', $this->pct($a['presenca']), "{$a['presentes']} de {$a['inscritos']} participações"),
            $this->linha('Média de acerto', $this->pct($a['media']), 'só presentes'),
        ];
        if ($a['delta'] !== null) {
            $linhas[] = $this->linha('Variação frente à anterior da categoria', ($a['delta'] > 0 ? '+' : '').CoordenadorDashboardService::pct($a['delta']).' pp', $a['anterior']['nome']);
        }
        if (! empty($a['esperado']) && $a['esperado']['abaixoPct'] !== null) {
            $linhas[] = $this->linha($a['esperado']['comMeta'] ? 'Abaixo do esperado' : 'Abaixo de '.(int) self::LIMIAR.'%', $this->pct($a['esperado']['abaixoPct']), "{$a['esperado']['abaixo']} de {$a['esperado']['presentes']} presentes");
        }

        $resultado = $a['media'] !== null ? "{$a['nome']}: média de ".$this->pct($a['media']).' e presença de '.$this->pct($a['presenca']).'.' : '';

        return $this->leitura($linhas, "Avaliação {$a['nome']}", $resultado, '', $this->listar($linhas));
    }

    private function destaque(?string $texto): array
    {
        if ($texto === null) {
            return $this->leitura([], null, '', '', '');
        }

        return $this->leitura([$this->linha('Destaque do painel', $texto)], null, $texto, '', $texto);
    }
}
