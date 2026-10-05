<?php

namespace App\Services;

use App\Models\Categoria;
use App\Models\ConfiguracaoSistema;
use App\Support\CacheDeAnalise;
use App\Support\Histograma;
use App\Support\NomeCurso;
use App\Support\PeriodoCurso;
use App\Support\Previstos;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Painel da reitoria: a visão INSTITUCIONAL — todos os cursos lado a lado numa avaliação ou numa CATEGORIA de
 * avaliações (ex.: o Diagnóstico Institucional do semestre, que costuma ser uma avaliação por curso). Só indicadores agregados: nenhum dado nominal de aluno passa por aqui.
 *
 * A UNIDADE é UMA avaliação (aluno × avaliação = uma pessoa), escolhida na barra de filtros — por isso "estudantes"
 * é a contagem de resultados dela. Avaliações de categorias diferentes não são comparáveis (ver
 * CoordenadorDashboardService); a evolução entre semestres (ReitorEvolucaoService) usa a MESMA categoria.
 *
 * Mesmas regras do resto do sistema (CLAUDE.md):
 *  - o curso de cada resultado é `resultado_resumos.curso` (o curso na data da prova), nunca `alunos.curso`;
 *  - ausente = prova inteira em branco (`resultado_resumos.ausente`): fora de média, mediana, faixas e
 *    proficiência — conta só na participação;
 *  - tudo é agregado em SQL sobre `resultado_resumos` (um GROUP BY por curso × período × percentual, ver
 *    Histograma) — nada de varrer `respostas` aqui (isso fica no ReitorCompetenciasService, em SQL e em cache).
 *
 * PROFICIENTE = percentual de acerto igual ou acima do CORTE (padrão 60%, o limiar do resto do sistema; o
 * administrador pode mudar em Configurações). É um critério interno da instituição, sem validação contra a
 * proficiência de exames externos — as telas dizem isso.
 */
class ReitorDashboardService
{
    public const CORTE_PADRAO = CoordenadorDashboardService::LIMIAR_ADEQUADO;

    public const META_PADRAO = 98.0;

    /** Períodos do curso com menos participantes que isto aparecem na tabela, mas não nas linhas dos gráficos. */
    public const MINIMO_PERIODO = 5;

    public function __construct(private readonly CoordenadorDashboardService $dashboard) {}

    // ------------------------------------------------------------------------------------------------------------
    // Parâmetros institucionais (editáveis em Configurações)
    // ------------------------------------------------------------------------------------------------------------

    /** Percentual de acerto a partir do qual o estudante é "proficiente" (30 a 90, inteiro). */
    public static function corte(): float
    {
        $valor = (float) ConfiguracaoSistema::valor('reitor_corte_proficiencia', (string) self::CORTE_PADRAO);

        return (float) max(30, min(90, round($valor)));
    }

    /** Meta de participação (% de previstos que fizeram a prova), 50 a 100. */
    public static function meta(): float
    {
        $valor = (float) str_replace(',', '.', (string) ConfiguracaoSistema::valor('reitor_meta_participacao', (string) self::META_PADRAO));

        return max(50.0, min(100.0, round($valor, 1)));
    }

    /**
     * As 5 faixas de acerto, ancoradas no corte: com 60%, "< 40", "40–49", "50–59", "60–69" e "≥ 70" — o corte é
     * sempre o começo da 4ª faixa, então "quem passou" fica sempre nas duas últimas.
     *
     * @return array<int, array{rotulo: string, de: float, ate: ?float}>
     */
    public static function faixas(float $corte): array
    {
        $c = (int) $corte;

        return [
            ['rotulo' => '< '.($c - 20).'%', 'de' => 0.0, 'ate' => (float) ($c - 20)],
            ['rotulo' => ($c - 20).'–'.($c - 11).'%', 'de' => (float) ($c - 20), 'ate' => (float) ($c - 10)],
            ['rotulo' => ($c - 10).'–'.($c - 1).'%', 'de' => (float) ($c - 10), 'ate' => (float) $c],
            ['rotulo' => $c.'–'.($c + 9).'%', 'de' => (float) $c, 'ate' => (float) ($c + 10)],
            ['rotulo' => '≥ '.($c + 10).'%', 'de' => (float) ($c + 10), 'ate' => null],
        ];
    }

    /** @return array<int, int> o corte e mais quatro patamares de 5 em 5 pontos (60, 65, 70, 75, 80) */
    public static function patamares(float $corte): array
    {
        return array_map(fn ($i) => (int) $corte + 5 * $i, range(0, 4));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Contexto: o que o reitor está olhando
    // ------------------------------------------------------------------------------------------------------------

    /**
     * O recorte em foco, os cursos selecionados e os dados brutos (do cache). Compartilhado por todas as telas do
     * painel, para que concordem.
     *
     * O recorte é um PERÍODO LETIVO + uma CATEGORIA ("todas" por padrão) + uma AVALIAÇÃO ("todas" da categoria por
     * padrão). Em geral cada avaliação é de um curso (a mesma prova aplicada a vários cursos vira várias avaliações
     * da mesma categoria), então a visão institucional é por categoria: os resultados das avaliações do recorte são
     * somados por curso. Avaliações de categorias diferentes NÃO são comparáveis em desempenho — quando o recorte as
     * reúne (categoria "todas" com mais de uma), `mistura` fica true e as telas avisam.
     *
     * @param  int|array{periodo?: ?string, categoria?: ?string, avaliacao?: int|string|null}|null  $filtro  um código de
     *                                                                                                       avaliação equivale a escolher só ela; `categoria` '' = todas, '0' = sem categoria, senão o id; `periodo`
     *                                                                                                       '-' = avaliações sem período letivo
     * @param  array<int, string>  $chavesSelecionadas  chaves de curso (NomeCurso::chave) — [] = todos
     * @return array<string, mixed>
     */
    public function contexto(int|array|null $filtro, array $chavesSelecionadas): array
    {
        $filtro = is_array($filtro) ? $filtro : ['avaliacao' => $filtro];
        $base = ['corte' => self::corte(), 'meta' => self::meta()];

        $avaliacoes = $this->dashboard->avaliacoesDoCurso(null);
        if ($avaliacoes->isEmpty()) {
            return [...$base, 'semResultados' => true];
        }

        $participantes = $this->participantesPorAvaliacao($avaliacoes->pluck('codigo')->all());
        $categorias = $this->dashboard->nomesDeCategoria();
        $paiDe = Categoria::pluck('categoria_pai_id', 'id')->all();

        $opcoes = $avaliacoes
            ->map(fn ($a) => [
                ...$a,
                'categoria' => $a['categoriaId'] !== null ? ($categorias[$a['categoriaId']] ?? 'Sem categoria') : 'Sem categoria',
                'inscritos' => $participantes[$a['codigo']]['inscritos'] ?? 0,
                'presentes' => $participantes[$a['codigo']]['presentes'] ?? 0,
                // a categoria, o pai, o avô... (ids como texto; '0' = sem categoria): escolher "Diagnóstico
                // Institucional" também vale para as filhas dele ("DI › Direito", "DI › Medicina"...)
                'cadeia' => $this->cadeiaDeCategorias($a['categoriaId'], $paiDe),
            ])
            ->filter(fn ($a) => $a['presentes'] > 0)
            ->sort(fn ($x, $y) => [$y['periodoLetivo'], $y['presentes'], $y['codigo']] <=> [$x['periodoLetivo'], $x['presentes'], $x['codigo']])
            ->values();

        if ($opcoes->isEmpty()) {
            return [...$base, 'semResultados' => true];
        }

        // Uma avaliação pedida define o período e a categoria dela; senão valem os pedidos (ou os padrões).
        $codigo = $filtro['avaliacao'] ?? null;
        $pedida = ctype_digit((string) $codigo) ? $opcoes->firstWhere('codigo', (int) $codigo) : null;

        $periodos = $opcoes->pluck('periodoLetivo')->unique()->sortDesc()->values()->all();
        $periodoPedido = ($filtro['periodo'] ?? '') === '-' ? '' : (string) ($filtro['periodo'] ?? '');
        // '*' = todos os períodos letivos (uma avaliação pedida sempre define o próprio período)
        $todosPeriodos = ($filtro['periodo'] ?? '') === '*' && $pedida === null;
        $periodo = $todosPeriodos
            ? 'Todos os períodos'
            : ($pedida['periodoLetivo'] ?? (in_array($periodoPedido, $periodos, true) && ($filtro['periodo'] ?? '') !== '' ? $periodoPedido : $periodos[0]));

        $doPeriodo = $todosPeriodos ? $opcoes : $opcoes->filter(fn ($a) => $a['periodoLetivo'] === $periodo)->values();
        $categoriasDoPeriodo = $this->categoriasDoPeriodo($doPeriodo, $categorias);
        // quantas avaliações a categoria tem em TODOS os períodos (o seletor mostra "3 de 8" quando o período recorta)
        foreach ($categoriasDoPeriodo as $id => $categoria) {
            $categoriasDoPeriodo[$id]['totalAvaliacoes'] = $opcoes->filter(fn ($a) => in_array((string) $id, $a['cadeia'], true))->count();
        }

        $categoriaParam = (string) ($filtro['categoria'] ?? '');
        if ($pedida !== null) {
            // A avaliação pedida manda; mas se a categoria pedida é ela ou um ANCESTRAL dela (escolheu "Diagnóstico
            // Institucional" e depois uma avaliação dele), a categoria escolhida fica.
            $categoriaParam = in_array($categoriaParam, $pedida['cadeia'], true) && $categoriaParam !== '' ? $categoriaParam : $pedida['cadeia'][0];
        }
        if ($categoriaParam !== '' && ! isset($categoriasDoPeriodo[$categoriaParam])) {
            $categoriaParam = '';
        }

        $daCategoria = $categoriaParam === ''
            ? $doPeriodo
            : $doPeriodo->filter(fn ($a) => in_array($categoriaParam, $a['cadeia'], true))->values();

        $alvo = $this->descritor(
            $pedida !== null ? collect([$pedida]) : $daCategoria,
            $periodo,
            $categoriaParam,
            $pedida !== null,
            $categoriaParam === '' ? null : ($categoriasDoPeriodo[$categoriaParam]['caminho'] ?? null),
            $categoriaParam === '' ? null : $opcoes->filter(fn ($a) => in_array($categoriaParam, $a['cadeia'], true))->map(fn ($a) => (int) ($a['categoriaId'] ?? 0))->unique()->values()->all(),
        );

        $alvo['todosPeriodos'] = $todosPeriodos;
        // avaliações da categoria escolhida que ficam fora do período em foco (para avisar, em vez de parecer que faltam)
        $alvo['emOutrosPeriodos'] = $todosPeriodos || $categoriaParam === '' || $pedida !== null
            ? 0
            : $opcoes->filter(fn ($a) => in_array($categoriaParam, $a['cadeia'], true) && $a['periodoLetivo'] !== $periodo)->count();

        $bruto = $this->carregar($alvo['codigos']);
        $nomes = $this->nomesDosCursos($bruto);
        $disponiveis = array_keys($nomes);
        $selecionados = array_values(array_intersect($disponiveis, array_map('strval', $chavesSelecionadas)));
        if ($selecionados === []) {
            $selecionados = $disponiveis;
        }

        return [
            ...$base,
            'avaliacoes' => $opcoes,
            'avaliacao' => $alvo,
            // O que a barra de filtros mostra e o que as abas repassam na URL (valores vazios = "todas").
            'filtro' => ['periodo' => $todosPeriodos ? '*' : ($periodo === '' ? '-' : $periodo), 'categoria' => $categoriaParam, 'avaliacao' => $pedida !== null ? (string) $pedida['codigo'] : ''],
            'periodos' => $periodos,
            'categoriasDoPeriodo' => $categoriasDoPeriodo,
            'avaliacoesDaCategoria' => $daCategoria->map(fn ($a) => [
                'valor' => (string) $a['codigo'],
                'rotulo' => $a['nome'],
                'categoria' => $a['categoria'],
                'extra' => number_format($a['presentes'], 0, ',', '.').' part.',
            ])->all(),
            'bruto' => $bruto,
            'cursosDisponiveis' => $nomes,
            'cursosSelecionados' => $selecionados,
            'filtrando' => count($selecionados) < count($disponiveis),
            // Cor estável de cada curso (pela ordem alfabética de TODOS os disponíveis): o mesmo curso tem a mesma
            // cor em todos os gráficos e não muda quando o filtro de cursos muda.
            'cores' => $this->cores($disponiveis),
        ];
    }

    /**
     * @param  array<int, string>  $caminhos  id => "Pai › Filha"
     * @return array<int, string> a cadeia (ela, o pai, o avô...) como texto; ['0'] = sem categoria
     */
    private function cadeiaDeCategorias(?int $id, array $paiDe): array
    {
        if ($id === null) {
            return ['0'];
        }
        $cadeia = [];
        for ($guarda = 0; $id !== null && $guarda < 12; $guarda++) {
            $cadeia[] = (string) $id;
            $id = $paiDe[$id] ?? null;
        }

        return $cadeia;
    }

    /**
     * As categorias do período, em árvore: as que têm avaliação e os pais delas, cada uma com as avaliações e
     * participantes dela JUNTO com os das filhas (escolher o pai reúne tudo que está embaixo). '0' = sem categoria.
     *
     * @param  Collection<int, array<string, mixed>>  $doPeriodo
     * @param  array<int, string>  $caminhos  id => "Pai › Filha"
     * @return array<string, array{rotulo: string, caminho: string, nivel: int, pai: ?string, avaliacoes: int, participantes: int, totalAvaliacoes?: int}>
     */
    private function categoriasDoPeriodo(Collection $doPeriodo, array $caminhos): array
    {
        $lista = [];
        foreach ($doPeriodo as $avaliacao) {
            foreach ($avaliacao['cadeia'] as $posicao => $id) {
                $caminho = $id === '0' ? 'Sem categoria' : ($caminhos[(int) $id] ?? 'Categoria #'.$id);
                $segmentos = explode(' › ', $caminho);
                $lista[$id] ??= ['rotulo' => end($segmentos), 'caminho' => $caminho, 'nivel' => count($segmentos) - 1, 'pai' => $avaliacao['cadeia'][$posicao + 1] ?? null, 'avaliacoes' => 0, 'participantes' => 0];
                $lista[$id]['avaliacoes']++;
                $lista[$id]['participantes'] += $avaliacao['presentes'];
            }
        }

        // ordem da árvore (o pai antes das filhas, irmãs por nome); "Sem categoria" por último
        // (não comparar arrays de segmentos: o PHP compara o TAMANHO primeiro e jogaria as filhas para o fim)
        $chaveDeOrdem = fn ($c) => [$c['caminho'] === 'Sem categoria', implode("\x01", array_map('mb_strtolower', explode(' › ', $c['caminho'])))];
        uasort($lista, fn ($a, $b) => $chaveDeOrdem($a) <=> $chaveDeOrdem($b));

        return $lista;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $conjunto  as avaliações do recorte
     * @param  ?array<int, int>  $categoriaIds  as categorias que o filtro abrange (ela e as filhas; 0 = sem categoria); null = todas
     * @return array<string, mixed>
     */
    private function descritor(Collection $conjunto, string $periodo, string $categoriaParam, bool $avulsa, ?string $caminhoDaCategoria, ?array $categoriaIds): array
    {
        $maior = $conjunto->sortByDesc('presentes')->first();
        $n = $conjunto->count();
        $nomeCategoria = $categoriaParam === '' ? 'Todas as categorias' : ($caminhoDaCategoria ?? $maior['categoria']);
        // "mistura" = provas de famílias de categoria diferentes (a raiz da árvore): "DI › Direito" e "DI › Medicina"
        // são a mesma prova em cursos diferentes; "DI" e "Simulado" não.
        $familias = $conjunto->map(fn ($a) => (string) end($a['cadeia']))->unique()->count();

        return [
            ...$maior,
            'nome' => $n === 1 ? $maior['nome'] : $nomeCategoria.' · '.$n.' avaliações',
            'categoria' => $avulsa ? $maior['categoria'] : $nomeCategoria,
            'categoriaId' => $avulsa ? $maior['categoriaId'] : null,
            'categoriaParam' => $categoriaParam,
            'categoriaIds' => $categoriaIds,
            'periodoLetivo' => $periodo,
            'codigos' => $conjunto->pluck('codigo')->all(),
            'ehGrupo' => $n > 1,
            'avulsa' => $avulsa,
            'qtdAvaliacoes' => $n,
            'inscritos' => (int) $conjunto->sum('inscritos'),
            'presentes' => (int) $conjunto->sum('presentes'),
            'mistura' => ! $avulsa && $categoriaParam === '' && $familias > 1,
        ];
    }

    /** @param array<int, int> $codigos @return array<int, array{inscritos: int, presentes: int}> */
    private function participantesPorAvaliacao(array $codigos): array
    {
        return DB::table('resultado_resumos')
            ->whereIn('avaliacao_codigo', $codigos)
            ->whereNotNull('curso')->where('curso', '!=', '')
            ->groupBy('avaliacao_codigo')
            ->selectRaw('avaliacao_codigo, COUNT(*) as inscritos, SUM(CASE WHEN ausente = 0 THEN 1 ELSE 0 END) as presentes')
            ->get()
            ->mapWithKeys(fn ($l) => [(int) $l->avaliacao_codigo => ['inscritos' => (int) $l->inscritos, 'presentes' => (int) $l->presentes]])
            ->all();
    }

    /**
     * Resultados do recorte agregados no banco, só ARRAYS (o cache não desserializa objetos). Duas consultas pequenas:
     * contagens por (curso, período do curso) e o histograma de percentuais por (curso, período, percentual) —
     * somando as avaliações do recorte.
     *
     * @param  array<int, int>  $codigos
     * @return array{contagens: array<int, array{0: int, 1: string, 2: string, 3: int, 4: int}>, notas: array<int, array{0: string, 1: string, 2: int, 3: int}>}
     */
    private function carregar(array $codigos): array
    {
        return CacheDeAnalise::lembrarVarias('reitor-base', $codigos, [], function () use ($codigos) {
            $resumos = fn () => DB::table('resultado_resumos as rr')
                ->whereIn('rr.avaliacao_codigo', $codigos)
                ->whereNotNull('rr.curso')->where('rr.curso', '!=', '');

            $contagens = $resumos()
                ->groupBy('rr.avaliacao_codigo', 'rr.curso', 'rr.periodo')
                ->selectRaw('rr.avaliacao_codigo as codigo, rr.curso as curso, rr.periodo as periodo, COUNT(*) as inscritos')
                ->selectRaw('SUM(CASE WHEN rr.ausente = 0 THEN 1 ELSE 0 END) as presentes')
                ->get()
                ->map(fn ($l) => [(int) $l->codigo, (string) $l->curso, (string) $l->periodo, (int) $l->inscritos, (int) $l->presentes])
                ->all();

            $notas = $resumos()
                ->where('rr.ausente', false)
                ->whereNotNull('rr.percentual')
                ->groupBy('rr.curso', 'rr.periodo', 'rr.percentual')
                ->selectRaw('rr.curso as curso, rr.periodo as periodo, rr.percentual as percentual, COUNT(*) as n')
                ->get()
                ->map(fn ($l) => [(string) $l->curso, (string) $l->periodo, (int) round(((float) $l->percentual) * 10), (int) $l->n])
                ->all();

            return ['contagens' => $contagens, 'notas' => $notas];
        });
    }

    /**
     * Cursos da avaliação: chave => nome. Grafias que só diferem em acento/caixa viram um curso só, com a grafia
     * mais acentuada (NomeCurso::unicos).
     *
     * @return array<string, string>
     */
    private function nomesDosCursos(array $bruto): array
    {
        $grafias = [];
        foreach ($bruto['contagens'] as [, $curso]) {
            $grafias[NomeCurso::chave($curso)][] = $curso;
        }

        $nomes = [];
        foreach ($grafias as $chave => $variantes) {
            $nomes[$chave] = NomeCurso::unicos($variantes)[0] ?? $variantes[0];
        }
        uasort($nomes, fn ($a, $b) => strnatcasecmp(NomeCurso::chave($a), NomeCurso::chave($b)));

        return $nomes;
    }

    /**
     * Paleta categórica para os cursos (até 12 distintos; passou disso, repete). Escolhida para linhas e pontos
     * legíveis sobre fundo branco; o gráfico também mostra o nome do curso (legenda/tooltip), nunca só a cor.
     *
     * @param  array<int, string>  $chaves
     * @return array<string, string>
     */
    private function cores(array $chaves): array
    {
        $paleta = ['#1e3a5f', '#d97706', '#7c3aed', '#be123c', '#0284c7', '#4d7c0f', '#92400e', '#64748b', '#dc2626', '#0f766e', '#c026d3', '#4f46e5'];
        $cores = [];
        foreach (array_values($chaves) as $i => $chave) {
            $cores[$chave] = $paleta[$i % count($paleta)];
        }

        return $cores;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Estatísticas por curso
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Tudo que as telas mostram por curso e para a visão como um todo: participação, proficiência, média, mediana,
     * quartis, faixas, patamares e o detalhe por período do curso.
     *
     * @param  array<string, mixed>  $ctx  saída de contexto()
     * @return array{cursos: array<string, array<string, mixed>>, total: array<string, mixed>, periodos: array<int, int>, semAplicacao: array<string, array{nome: string, ativos: int}>, previstosPelosResultados: bool}
     */
    public function estatisticas(array $ctx): array
    {
        $corte = $ctx['corte'];
        $meta = $ctx['meta'];
        $nomes = $ctx['cursosDisponiveis'];
        $selecionados = $ctx['cursosSelecionados'];
        // período letivo de cada avaliação: os previstos vêm das matrículas de CADA período (com "todos os períodos",
        // cada semestre tem os seus previstos, e a participação soma os semestres)
        $periodoDe = $ctx['avaliacoes']->pluck('periodoLetivo', 'codigo')->all();

        $acc = [];
        foreach ($ctx['bruto']['contagens'] as [$codigo, $curso, $periodo, $inscritos, $presentes]) {
            $chave = NomeCurso::chave($curso);
            if (! in_array($chave, $selecionados, true)) {
                continue;
            }
            $ordinal = PeriodoCurso::ordinal($periodo);
            $periodoLetivo = (string) ($periodoDe[$codigo] ?? '');
            $c = &$acc[$chave];
            $c ??= ['inscritos' => 0, 'presentes' => 0, 'hist' => [], 'ordinais' => [], 'semPeriodoInscritos' => 0, 'semPeriodoPresentes' => 0, 'porPeriodoLetivo' => []];
            $c['inscritos'] += $inscritos;
            $c['presentes'] += $presentes;
            $pl = &$c['porPeriodoLetivo'][$periodoLetivo];
            $pl ??= ['ordinais' => [], 'sem' => 0];
            if ($ordinal === null) {
                $pl['sem'] += $inscritos;
            } else {
                $pl['ordinais'][$ordinal] = ($pl['ordinais'][$ordinal] ?? 0) + $inscritos;
            }
            unset($pl);
            if ($ordinal === null) {
                $c['semPeriodoInscritos'] += $inscritos;
                $c['semPeriodoPresentes'] += $presentes;
            } else {
                $o = &$c['ordinais'][$ordinal];
                $o ??= ['inscritos' => 0, 'presentes' => 0, 'hist' => []];
                $o['inscritos'] += $inscritos;
                $o['presentes'] += $presentes;
                unset($o);
            }
            unset($c);
        }

        foreach ($ctx['bruto']['notas'] as [$curso, $periodo, $decimos, $n]) {
            $chave = NomeCurso::chave($curso);
            if (! isset($acc[$chave])) {
                continue;
            }
            $acc[$chave]['hist'][$decimos] = ($acc[$chave]['hist'][$decimos] ?? 0) + $n;
            if (($ordinal = PeriodoCurso::ordinal($periodo)) !== null && isset($acc[$chave]['ordinais'][$ordinal])) {
                $acc[$chave]['ordinais'][$ordinal]['hist'][$decimos] = ($acc[$chave]['ordinais'][$ordinal]['hist'][$decimos] ?? 0) + $n;
            }
        }

        $matriculas = [];
        foreach (array_unique(array_map(fn ($c) => (string) ($periodoDe[$c] ?? ''), $ctx['avaliacao']['codigos'])) as $periodoLetivo) {
            $matriculas[$periodoLetivo] = Previstos::matriculasVigentes($periodoLetivo);
        }

        $cursos = [];
        $totalHist = [];
        $totalOrdinais = [];
        foreach ($nomes as $chave => $nome) {
            if (! isset($acc[$chave])) {
                continue;
            }
            $a = $acc[$chave];
            $previsao = $this->previsaoDoCurso($a['porPeriodoLetivo'], $matriculas, $chave);

            $periodos = [];
            ksort($a['ordinais']);
            foreach ($a['ordinais'] as $ordinal => $o) {
                $periodos[$ordinal] = $this->indicadores($o['hist'], $o['presentes'], $previsao['porOrdinal'][$ordinal] ?? $o['inscritos'], $corte, $meta)
                    + ['ordinal' => $ordinal, 'rotulo' => $ordinal.'º', 'patamares' => $this->patamaresDe($o['hist'], $corte)];
                $totalOrdinais[$ordinal]['hist'] = Histograma::mesclar($totalOrdinais[$ordinal]['hist'] ?? [], $o['hist']);
                $totalOrdinais[$ordinal]['presentes'] = ($totalOrdinais[$ordinal]['presentes'] ?? 0) + $o['presentes'];
                $totalOrdinais[$ordinal]['previstos'] = ($totalOrdinais[$ordinal]['previstos'] ?? 0) + ($previsao['porOrdinal'][$ordinal] ?? $o['inscritos']);
            }

            $totalHist = Histograma::mesclar($totalHist, $a['hist']);
            $avaliados = array_keys($periodos);

            $cursos[$chave] = [
                'chave' => $chave,
                'nome' => $nome,
                ...$this->indicadores($a['hist'], $a['presentes'], $previsao['previstos'], $corte, $meta),
                'faixas' => $this->faixasDe($a['hist'], $corte),
                'patamares' => $this->patamaresDe($a['hist'], $corte),
                'distribuicao' => Histograma::baldes($a['hist']),
                'periodos' => $periodos,
                'periodosAvaliados' => $avaliados,
                'periodosAvaliadosRotulo' => Previstos::rotuloDosPeriodos($avaliados),
                'ativosSemAplicacao' => $previsao['semAplicacao'],
                'ativosSemAplicacaoRotulo' => $previsao['semAplicacao'] === [] ? '' : Previstos::rotuloDosPeriodos(array_keys($previsao['semAplicacao'])),
                'fontePrevistos' => $previsao['fonte'],
                'semPeriodo' => $a['semPeriodoPresentes'],
            ];
        }

        // Total da visão: histogramas somados (a mediana/quartis são do conjunto, não a média das medianas).
        $totalPrevistos = array_sum(array_column($cursos, 'previstos'));
        $totalPresentes = array_sum(array_column($cursos, 'fizeram'));
        $total = [
            'chave' => '__total',
            'nome' => 'Total da visão',
            ...$this->indicadores($totalHist, $totalPresentes, $totalPrevistos, $corte, $meta),
            'faixas' => $this->faixasDe($totalHist, $corte),
            'patamares' => $this->patamaresDe($totalHist, $corte),
            'distribuicao' => Histograma::baldes($totalHist),
            'alunosAMais' => array_sum(array_column($cursos, 'alunosAMais')),
        ];
        $total['periodos'] = [];
        ksort($totalOrdinais);
        foreach ($totalOrdinais as $ordinal => $o) {
            $total['periodos'][$ordinal] = $this->indicadores($o['hist'], $o['presentes'], $o['previstos'], $corte, $meta)
                + ['ordinal' => $ordinal, 'rotulo' => $ordinal.'º', 'patamares' => $this->patamaresDe($o['hist'], $corte)];
        }

        // Diferença para a média da visão — só faz sentido depois do total.
        foreach ($cursos as $chave => $curso) {
            $cursos[$chave]['difParticipacao'] = $curso['participacao'] !== null && $total['participacao'] !== null ? round($curso['participacao'] - $total['participacao'], 1) : null;
            $cursos[$chave]['difProficiencia'] = $curso['proficienciaPct'] !== null && $total['proficienciaPct'] !== null ? round($curso['proficienciaPct'] - $total['proficienciaPct'], 1) : null;
            $cursos[$chave]['difMedia'] = $curso['media'] !== null && $total['media'] !== null ? round($curso['media'] - $total['media'], 1) : null;
        }

        // Cursos com aluno ativo no semestre, mas sem NENHUMA aplicação nesta avaliação (só quando não há filtro de curso).
        $semAplicacao = [];
        if (! $ctx['filtrando']) {
            foreach ($matriculas as $doPeriodo) {
                foreach ($doPeriodo as $chave => $m) {
                    if (isset($nomes[$chave])) {
                        continue;
                    }
                    $ativos = array_sum($m['ordinais']);
                    if ($ativos > 0) {
                        $semAplicacao[$chave] = ['nome' => $m['nome'], 'ativos' => ($semAplicacao[$chave]['ativos'] ?? 0) + $ativos];
                    }
                }
            }
            uasort($semAplicacao, fn ($a, $b) => strnatcasecmp(NomeCurso::chave($a['nome']), NomeCurso::chave($b['nome'])));
        }

        return [
            'cursos' => $cursos,
            'total' => $total,
            'periodos' => array_keys($totalOrdinais),
            'semAplicacao' => $semAplicacao,
            'previstosPelosResultados' => collect($cursos)->contains(fn ($c) => $c['fontePrevistos'] === 'resultados'),
        ];
    }

    /**
     * Previstos de um curso: o cálculo de Previstos::calcular() para CADA período letivo do recorte, somado.
     *
     * @param  array<string, array{ordinais: array<int, int>, sem: int}>  $porPeriodoLetivo
     * @param  array<string, array<string, array{nome: string, ordinais: array<int, int>}>>  $matriculas  por período letivo
     * @return array{previstos: int, fonte: string, porOrdinal: array<int, int>, semAplicacao: array<int, int>}
     */
    private function previsaoDoCurso(array $porPeriodoLetivo, array $matriculas, string $chave): array
    {
        $soma = ['previstos' => 0, 'fonte' => 'resultados', 'porOrdinal' => [], 'semAplicacao' => []];
        foreach ($porPeriodoLetivo as $periodoLetivo => $dados) {
            $r = Previstos::calcular($matriculas[$periodoLetivo][$chave]['ordinais'] ?? [], $dados['ordinais'], $dados['sem']);
            $soma['previstos'] += $r['previstos'];
            if ($r['fonte'] === 'matricula') {
                $soma['fonte'] = 'matricula';
            }
            foreach (['porOrdinal', 'semAplicacao'] as $campo) {
                foreach ($r[$campo] as $ordinal => $n) {
                    $soma[$campo][$ordinal] = ($soma[$campo][$ordinal] ?? 0) + $n;
                }
            }
        }
        ksort($soma['porOrdinal']);
        ksort($soma['semAplicacao']);

        return $soma;
    }

    /**
     * Os números de um conjunto de resultados (um curso, um período do curso, ou a visão inteira).
     *
     * @param  array<int, int>  $hist  histograma dos percentuais dos PRESENTES com nota
     * @return array<string, mixed>
     */
    private function indicadores(array $hist, int $presentes, int $previstos, float $corte, float $meta): array
    {
        $n = Histograma::n($hist);
        $previstos = max($previstos, $presentes);
        $proficientes = Histograma::contarAcima($hist, $corte);
        $participacao = $previstos > 0 ? round($presentes / $previstos * 100, 1) : null;
        $alvo = (int) ceil($meta / 100 * $previstos - 1e-9);

        return [
            'previstos' => $previstos,
            'fizeram' => $presentes,
            'ausentes' => $previstos - $presentes,
            'participacao' => $participacao,
            'distanciaMeta' => $participacao !== null ? round($participacao - $meta, 1) : null,
            'alunosAMais' => max(0, $alvo - $presentes),
            'n' => $n,
            'media' => $n > 0 ? round(Histograma::media($hist), 1) : null,
            'mediana' => $n > 0 ? round(Histograma::quantil($hist, 0.5), 1) : null,
            'q1' => $n > 0 ? round(Histograma::quantil($hist, 0.25), 1) : null,
            'q3' => $n > 0 ? round(Histograma::quantil($hist, 0.75), 1) : null,
            'p10' => $n > 0 ? round(Histograma::quantil($hist, 0.10), 1) : null,
            'p90' => $n > 0 ? round(Histograma::quantil($hist, 0.90), 1) : null,
            'minimo' => Histograma::minimo($hist),
            'maximo' => Histograma::maximo($hist),
            'proficientes' => $proficientes,
            'proficienciaPct' => $n > 0 ? round($proficientes / $n * 100, 1) : null,
        ];
    }

    /** @param array<int, int> $hist @return array<int, array{rotulo: string, n: int, pct: ?float}> */
    private function faixasDe(array $hist, float $corte): array
    {
        $n = Histograma::n($hist);

        return array_map(function ($faixa) use ($hist, $n) {
            $quantos = Histograma::contarEntre($hist, $faixa['de'], $faixa['ate']);

            return ['rotulo' => $faixa['rotulo'], 'n' => $quantos, 'pct' => $n > 0 ? round($quantos / $n * 100, 1) : null];
        }, self::faixas($corte));
    }

    /** @param array<int, int> $hist @return array<int, ?float> patamar => % dos presentes com nota nesse patamar ou acima */
    private function patamaresDe(array $hist, float $corte): array
    {
        $n = Histograma::n($hist);
        $resultado = [];
        foreach (self::patamares($corte) as $patamar) {
            $resultado[$patamar] = $n > 0 ? round(Histograma::contarAcima($hist, (float) $patamar) / $n * 100, 1) : null;
        }

        return $resultado;
    }
}
