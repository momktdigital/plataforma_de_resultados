<?php

namespace App\Services\Portal;

use App\Models\Aluno;
use App\Support\Anulacao;
use App\Support\CacheDeAnalise;
use App\Support\Dificuldade;
use App\Support\PeriodoCurso;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Análises que cruzam VÁRIAS avaliações do aluno num mesmo período letivo
 * (dificuldade, habilidades, Bloom/Miller, comparativo com turma, áreas
 * mais divergentes) — diferente de RelatorioAlunoService, que sempre parte
 * da Collection de respostas de UMA avaliação já carregada em memória, aqui
 * a base pode somar dezenas de avaliações × questões, então a agregação
 * sempre acontece em SQL (DB::table + JOIN + GROUP BY + SUM(CASE...)),
 * nunca varrendo `respostas` inteira em PHP — mesmo espírito de
 * RelatorioAdminService/BiDashboardService.
 */
class AnaliseConsolidadaService
{
    /** Mínimo esperado (% de acerto) de uma prova que não traz a meta por período nas questões. */
    public const MINIMO_PADRAO = 60.0;

    /**
     * "Você errou mais questões fáceis do que difíceis?" — % de acerto do
     * aluno por dificuldade pedagógica, somando todas as avaliações do
     * período informado. Equivalente individual de
     * RelatorioAdminService::curvaDificuldade() (que soma a turma inteira).
     *
     * @param  array<int, int>  $avaliacaoCodigos
     * @return array<string, array{label: string, percentual: float, respostas: int}>
     */
    public function curvaDificuldadePedagogica(Aluno $aluno, array $avaliacaoCodigos): array
    {
        $ordem = Dificuldade::rotulos();
        $linhas = $this->mediaPorCampoAgregado($aluno, $avaliacaoCodigos, 'dificuldade_pedagogica')->keyBy('campo');

        $resultado = [];
        foreach ($ordem as $chave => $label) {
            $linha = $linhas->get($chave);
            if ($linha === null || (int) $linha->total === 0) {
                continue;
            }

            $resultado[$chave] = [
                'label' => $label,
                'percentual' => round((int) $linha->acertos / (int) $linha->total * 100, 1),
                'respostas' => (int) $linha->total,
            ];
        }

        return $resultado;
    }

    /**
     * Dispersão dificuldade TRI x acerto, ponto a ponto — pensada pra um
     * gráfico de dispersão (scatter), já que dificuldade TRI é uma escala
     * contínua, sem faixas fixas de fácil/médio/difícil como a pedagógica.
     *
     * @param  array<int, int>  $avaliacaoCodigos
     * @return array<int, array{dificuldade_tri: float, acertou: bool}>
     */
    public function dispersaoTri(Aluno $aluno, array $avaliacaoCodigos): array
    {
        if (empty($avaliacaoCodigos)) {
            return [];
        }

        return DB::table('respostas as r')
            ->join('questoes as q', function ($join) use ($avaliacaoCodigos) {
                $join->on('q.numero', '=', 'r.questao_numero')
                    ->on('q.avaliacao_codigo', '=', 'r.avaliacao_codigo')
                    ->whereIn('q.avaliacao_codigo', $avaliacaoCodigos)
                    ->whereNull('q.deleted_at')
                    ->whereNotNull('q.gabarito')->where('q.gabarito', '!=', '')
                    ->whereNotNull('q.dificuldade_tri');
            })
            ->whereIn('r.avaliacao_codigo', $avaliacaoCodigos)
            ->whereNull('r.deleted_at')
            ->where(fn ($q) => $this->porAluno($q, $aluno))
            ->select('q.dificuldade_tri', 'r.resposta', 'q.gabarito', 'q.anulada_modo')
            ->get()
            ->filter(fn ($l) => ! Anulacao::distribuida($l->anulada_modo))
            ->map(fn ($l) => [
                'dificuldade_tri' => (float) $l->dificuldade_tri,
                'acertou' => Anulacao::acertou($l->resposta, $l->gabarito, $l->anulada_modo),
            ])
            ->values()
            ->all();
    }

    /**
     * % de acerto por habilidade, somando todas as avaliações do período —
     * ordenado do pior pro melhor, pra funcionar como "ranking de
     * habilidades a reforçar" (equivalente individual do
     * heatmap_habilidade_turma do admin, que compara turmas entre si em vez
     * de habilidades de um único aluno).
     *
     * @param  array<int, int>  $avaliacaoCodigos
     * @return array<string, float> habilidade => percentual
     */
    public function coberturaHabilidade(Aluno $aluno, array $avaliacaoCodigos): array
    {
        return $this->percentualPorCampo($aluno, $avaliacaoCodigos, 'habilidade')
            ->sort()
            ->all();
    }

    /** @param  array<int, int>  $avaliacaoCodigos
     * @return array<string, float> */
    public function desempenhoBloomConsolidado(Aluno $aluno, array $avaliacaoCodigos): array
    {
        return $this->percentualPorCampo($aluno, $avaliacaoCodigos, 'bloom_nivel')->all();
    }

    /** @param  array<int, int>  $avaliacaoCodigos
     * @return array<string, float> */
    public function desempenhoMillerConsolidado(Aluno $aluno, array $avaliacaoCodigos): array
    {
        return $this->percentualPorCampo($aluno, $avaliacaoCodigos, 'miller_nivel')->all();
    }

    /**
     * Acerto por nível de Bloom COM a contagem de questões de cada nível — o card "o que mais pesou" do resumo só
     * afirma "foram as mais difíceis pra você" quando o nível tem questões suficientes (ver InsightService). Usa o nível
     * de Bloom; se a planilha só trouxe o verbo (Lembrar, Aplicar...), cai para ele.
     *
     * @param  array<int, int>  $avaliacaoCodigos
     * @return array<string, array{percentual: float, total: int}>
     */
    public function bloomComContagem(Aluno $aluno, array $avaliacaoCodigos): array
    {
        foreach (['bloom_nivel', 'bloom_verbo'] as $campo) {
            $niveis = $this->mediaPorCampoAgregado($aluno, $avaliacaoCodigos, $campo)
                ->filter(fn ($l) => (int) $l->total > 0)
                ->mapWithKeys(fn ($l) => [$l->campo => [
                    'percentual' => round((int) $l->acertos / (int) $l->total * 100, 1),
                    'total' => (int) $l->total,
                ]])
                ->all();

            if ($niveis !== []) {
                return $niveis;
            }
        }

        return [];
    }

    /**
     * Acerto TOTAL do aluno em cada avaliação x o MÍNIMO ESPERADO para o período dele (a meta `questoes.periodo_minimo`):
     * uma questão marcada "a partir do 3º período" é esperada de quem está no 3º em diante; para quem está antes, acertar
     * é bônus. O mínimo esperado é a fatia da prova que já cabe no período do aluno — esperadas / total. Se a prova não
     * traz essa informação (nenhuma questão com meta, ou período do aluno irreconhecível), o mínimo é MINIMO_PADRAO (60%).
     *
     * O período do aluno é o de `respostas.periodo` DAQUELA prova (o mesmo valor da turma), nunca o do cadastro atual.
     * Questão sem meta conta como esperada para todos; anulada com distribuição de pontuação não entra na conta.
     *
     * Agrega em SQL por (avaliação, período, área, meta) — poucas linhas — e só então aplica a regra em PHP, porque o
     * período do aluno pode mudar de uma prova para outra. `geral`/`areas`/`adiante` somam todas as avaliações dadas e
     * só alimentam a "Leitura rápida"; o gráfico usa `avaliacoes`.
     *
     * @param  array<int, int>  $avaliacaoCodigos
     * @return array{avaliacoes: array<int, array{codigo: int, nome: string, data: ?string, percentual: float, minimo: float, comMeta: bool, periodoAluno: ?int, total: int}>, comMeta: bool, periodoAluno: ?int, geral: array{percentual: float, esperado: ?float, total: int}, areas: array<int, array{area: string, percentual: float, esperado: ?float, total: int}>, adiante: array{total: int, acertos: int}}|null
     */
    public function metaPorPeriodo(Aluno $aluno, array $avaliacaoCodigos, int $maximoAreas = 12): ?array
    {
        if (empty($avaliacaoCodigos)) {
            return null;
        }

        $linhas = DB::table('respostas as r')
            ->join('questoes as q', function ($join) use ($avaliacaoCodigos) {
                Anulacao::excluirDistribuidas(
                    $join->on('q.numero', '=', 'r.questao_numero')
                        ->on('q.avaliacao_codigo', '=', 'r.avaliacao_codigo')
                        ->whereIn('q.avaliacao_codigo', $avaliacaoCodigos)
                        ->whereNull('q.deleted_at')
                        ->whereNotNull('q.gabarito')->where('q.gabarito', '!=', ''),
                    'q.anulada_modo',
                );
            })
            ->whereIn('r.avaliacao_codigo', $avaliacaoCodigos)
            ->whereNull('r.deleted_at')
            ->where(fn ($q) => $this->porAluno($q, $aluno))
            ->groupBy('r.avaliacao_codigo', 'r.periodo', 'q.area', 'q.periodo_minimo')
            ->selectRaw('r.avaliacao_codigo as codigo, r.periodo as periodo_aluno, q.area as area, q.periodo_minimo as minimo')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN '.Anulacao::condicaoAcertoSql('r.resposta', 'q.gabarito', 'q.anulada_modo').' THEN 1 ELSE 0 END) as acertos')
            ->get();

        if ($linhas->isEmpty()) {
            return null;
        }

        $zerado = fn () => ['total' => 0, 'acertos' => 0, 'esperadas' => 0, 'haMeta' => false, 'periodoConhecido' => false, 'periodo' => null];
        $porAvaliacao = [];
        $geral = $zerado();
        $areas = [];
        $adiante = ['total' => 0, 'acertos' => 0];

        foreach ($linhas as $linha) {
            $total = (int) $linha->total;
            $acertos = (int) $linha->acertos;
            $ordinal = PeriodoCurso::ordinal($linha->periodo_aluno);
            $minimo = $linha->minimo !== null ? (int) $linha->minimo : null;

            $aFrente = $ordinal !== null && $minimo !== null && $minimo > $ordinal;
            $esperadas = $aFrente ? 0 : $total;

            if ($aFrente) {
                $adiante['total'] += $total;
                $adiante['acertos'] += $acertos;
            }

            $codigo = (int) $linha->codigo;
            $porAvaliacao[$codigo] ??= $zerado();

            foreach ([&$geral, &$porAvaliacao[$codigo]] as &$alvo) {
                $alvo['total'] += $total;
                $alvo['acertos'] += $acertos;
                $alvo['esperadas'] += $esperadas;
                $alvo['haMeta'] = $alvo['haMeta'] || $minimo !== null;
                $alvo['periodoConhecido'] = $alvo['periodoConhecido'] || $ordinal !== null;
                $alvo['periodo'] = $ordinal !== null ? max($alvo['periodo'] ?? 0, $ordinal) : $alvo['periodo'];
            }
            unset($alvo);

            $area = trim((string) $linha->area);
            if ($area !== '') {
                $areas[$area] ??= ['total' => 0, 'acertos' => 0, 'esperadas' => 0];
                $areas[$area]['total'] += $total;
                $areas[$area]['acertos'] += $acertos;
                $areas[$area]['esperadas'] += $esperadas;
            }
        }

        if ($geral['total'] === 0) {
            return null;
        }

        $comMeta = $geral['haMeta'] && $geral['periodoConhecido'];
        $montar = fn (array $a) => [
            'percentual' => round($a['acertos'] / $a['total'] * 100, 1),
            'esperado' => $comMeta ? round($a['esperadas'] / $a['total'] * 100, 1) : null,
            'total' => $a['total'],
        ];

        $listaAreas = [];
        foreach ($areas as $nome => $a) {
            $listaAreas[] = ['area' => (string) $nome] + $montar($a);
        }

        // Quem está mais longe do esperado vem primeiro (sem meta: o menor acerto) — é onde o aluno olha primeiro.
        usort($listaAreas, fn ($a, $b) => ($a['percentual'] - ($a['esperado'] ?? 100)) <=> ($b['percentual'] - ($b['esperado'] ?? 100))
            ?: strcmp($a['area'], $b['area']));

        $avaliacoes = DB::table('avaliacoes')
            ->whereIn('codigo', array_keys($porAvaliacao))
            ->orderBy('data_avaliacao')
            ->orderBy('codigo')
            ->select('codigo', 'nome', 'data_avaliacao')
            ->get()
            ->map(function ($av) use ($porAvaliacao) {
                $a = $porAvaliacao[(int) $av->codigo];
                $comMetaDaProva = $a['haMeta'] && $a['periodoConhecido'];

                return [
                    'codigo' => (int) $av->codigo,
                    'nome' => $av->nome ?: 'Avaliação #'.$av->codigo,
                    'data' => $av->data_avaliacao,
                    'percentual' => round($a['acertos'] / $a['total'] * 100, 1),
                    'minimo' => $comMetaDaProva ? round($a['esperadas'] / $a['total'] * 100, 1) : self::MINIMO_PADRAO,
                    'comMeta' => $comMetaDaProva,
                    'periodoAluno' => $a['periodo'],
                    'total' => $a['total'],
                ];
            })
            ->all();

        return [
            'avaliacoes' => $avaliacoes,
            'comMeta' => $comMeta,
            'periodoAluno' => $geral['periodo'],
            'geral' => $montar($geral),
            'areas' => array_slice($listaAreas, 0, $maximoAreas),
            'adiante' => $adiante,
        ];
    }
    /**
     * Áreas onde o aluno mais fica atrás da turma: % de acerto do aluno por
     * área (somando todas as avaliações do período) comparado ao % médio de
     * acerto da turma inteira na MESMA área — só entram áreas onde o aluno
     * fica pelo menos $diferencaMinima pontos abaixo da turma (uma diferença
     * de 1-2 pontos é ruído de amostra pequena, não divergência real),
     * ordenadas pela maior diferença primeiro. Trocado de "por tema" pra
     * "por área" porque tema é granular demais pra virar um sinal acionável
     * (a lista virava uma dúzia de linhas de 1 ocorrência cada); área
     * generaliza o suficiente pra apontar "onde estudar" sem perder a
     * comparação direta aluno×turma.
     *
     * @param  array<int, int>  $avaliacaoCodigos
     * @return array<int, array{area: string, percentualAluno: float, percentualTurma: float, diferenca: float}>
     */
    public function areasDivergentesDaTurma(Aluno $aluno, array $avaliacaoCodigos, float $diferencaMinima = 10.0, int $limite = 8): array
    {
        if (empty($avaliacaoCodigos)) {
            return [];
        }

        $doAluno = $this->mediaPorCampoAgregado($aluno, $avaliacaoCodigos, 'area')
            ->filter(fn ($l) => (int) $l->total > 0)
            ->keyBy('campo');

        $daTurma = $this->mediaPorCampoAgregado(null, $avaliacaoCodigos, 'area')
            ->filter(fn ($l) => (int) $l->total > 0)
            ->keyBy('campo');

        $resultado = [];
        foreach ($doAluno as $area => $linhaAluno) {
            $linhaTurma = $daTurma->get($area);
            if ($linhaTurma === null) {
                continue;
            }

            $percentualAluno = round((int) $linhaAluno->acertos / (int) $linhaAluno->total * 100, 1);
            $percentualTurma = round((int) $linhaTurma->acertos / (int) $linhaTurma->total * 100, 1);
            $diferenca = round($percentualTurma - $percentualAluno, 1);

            if ($diferenca < $diferencaMinima) {
                continue;
            }

            $resultado[] = [
                'area' => $area,
                'percentualAluno' => $percentualAluno,
                'percentualTurma' => $percentualTurma,
                'diferenca' => $diferenca,
            ];
        }

        usort($resultado, fn ($a, $b) => $b['diferenca'] <=> $a['diferenca']);

        return array_slice($resultado, 0, $limite);
    }

    /** @param  array<int, int>  $avaliacaoCodigos
     * @return Collection<string, float> */
    private function percentualPorCampo(Aluno $aluno, array $avaliacaoCodigos, string $campo): Collection
    {
        return $this->mediaPorCampoAgregado($aluno, $avaliacaoCodigos, $campo)
            ->filter(fn ($l) => (int) $l->total > 0)
            ->mapWithKeys(fn ($l) => [$l->campo => round((int) $l->acertos / (int) $l->total * 100, 1)]);
    }

    /**
     * Soma acertos/total agrupado por um campo direto de `questoes`
     * (dificuldade_pedagogica, habilidade, bloom_nivel, miller_nivel, area),
     * somando todas as avaliações informadas — mesmo padrão de
     * RelatorioAdminService::mediaPorCampoDireto(). $aluno null soma a TURMA
     * inteira (todos os respondentes, sem filtrar por um aluno) — usado por
     * areasDivergentesDaTurma() pra comparar o aluno contra a turma na mesma
     * agregação, sem duplicar a query.
     *
     * @param  array<int, int>  $avaliacaoCodigos
     * @return Collection<int, object{campo: string, total: int, acertos: int}>
     */
    private function mediaPorCampoAgregado(?Aluno $aluno, array $avaliacaoCodigos, string $campo): Collection
    {
        if (empty($avaliacaoCodigos)) {
            return collect();
        }

        // A TURMA inteira é a mesma para todo aluno que abre o boletim: cacheada por avaliação (só muda quando os
        // resultados ou as questões mudam). O agregado do próprio aluno é pequeno e não vale cache. O cache guarda
        // arrays (não desserializa objetos), então volta como stdClass aqui.
        if ($aluno === null) {
            return collect(CacheDeAnalise::lembrarVarias(
                'turma-por-campo',
                $avaliacaoCodigos,
                ['campo' => $campo],
                fn () => $this->somarPorCampo(null, $avaliacaoCodigos, $campo)->map(fn ($l) => (array) $l)->all(),
            ))->map(fn ($l) => (object) $l);
        }

        return $this->somarPorCampo($aluno, $avaliacaoCodigos, $campo);
    }

    /** @return Collection<int, object{campo: string, total: int, acertos: int}> */
    private function somarPorCampo(?Aluno $aluno, array $avaliacaoCodigos, string $campo): Collection
    {
        $query = DB::table('respostas as r')
            ->join('questoes as q', function ($join) use ($avaliacaoCodigos, $campo) {
                Anulacao::excluirDistribuidas(
                    $join->on('q.numero', '=', 'r.questao_numero')
                        ->on('q.avaliacao_codigo', '=', 'r.avaliacao_codigo')
                        ->whereIn('q.avaliacao_codigo', $avaliacaoCodigos)
                        ->whereNull('q.deleted_at')
                        ->whereNotNull('q.gabarito')->where('q.gabarito', '!=', '')
                        ->whereNotNull("q.{$campo}")->where("q.{$campo}", '!=', ''),
                    'q.anulada_modo',
                );
            })
            ->whereIn('r.avaliacao_codigo', $avaliacaoCodigos)
            ->whereNull('r.deleted_at');

        if ($aluno !== null) {
            $query->where(fn ($q) => $this->porAluno($q, $aluno));
        }

        return $query
            ->groupBy("q.{$campo}")
            ->selectRaw("q.{$campo} as campo")
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN '.Anulacao::condicaoAcertoSql('r.resposta', 'q.gabarito', 'q.anulada_modo').' THEN 1 ELSE 0 END) as acertos')
            ->get();
    }

    /**
     * Acerto por ÁREA em cada avaliação da categoria, em ordem cronológica —
     * o mapa de domínio do aluno.
     *
     * A evolução que já existia é uma linha de média geral: ela mostra que a
     * nota subiu, mas não que Cardiologia consolidou enquanto Saúde Coletiva
     * despencou depois de um pico. É exatamente essa leitura (consolidação x
     * esquecimento por conteúdo) que a média esconde e que este mapa expõe.
     *
     * Uma célula vazia (null) significa que aquela avaliação não tinha questão
     * da área — diferente de "foi mal", e a tela precisa distinguir os dois.
     *
     * Cada célula também traz o MÍNIMO ESPERADO (`esperados`, mesma chave de `valores`): das questões daquela área
     * naquela prova, a fatia que o aluno já deveria acertar pelo período dele (meta `questoes.periodo_minimo`, período
     * de `respostas.periodo` — ver metaPorPeriodo()). Sem meta nas questões da célula (ou sem período reconhecível), é
     * MINIMO_PADRAO. A tela pinta a célula de amarelo quando o acerto fica abaixo do mínimo.
     *
     * @param  array<int, int>  $avaliacaoCodigos
     * @return array{avaliacoes: array<int, array{codigo: int, nome: ?string}>, areas: array<int, array{area: string, valores: array<int, ?float>, esperados: array<int, ?float>}>}
     */
    public function mapaDominio(Aluno $aluno, array $avaliacaoCodigos): array
    {
        if (empty($avaliacaoCodigos)) {
            return ['avaliacoes' => [], 'areas' => []];
        }

        $linhas = DB::table('respostas as r')
            ->join('questoes as q', function ($join) use ($avaliacaoCodigos) {
                Anulacao::excluirDistribuidas(
                    $join->on('q.numero', '=', 'r.questao_numero')
                        ->on('q.avaliacao_codigo', '=', 'r.avaliacao_codigo')
                        ->whereIn('q.avaliacao_codigo', $avaliacaoCodigos)
                        ->whereNull('q.deleted_at')
                        ->whereNotNull('q.gabarito')->where('q.gabarito', '!=', '')
                        ->whereNotNull('q.area')->where('q.area', '!=', ''),
                    'q.anulada_modo',
                );
            })
            ->whereIn('r.avaliacao_codigo', $avaliacaoCodigos)
            ->whereNull('r.deleted_at')
            ->where(fn ($q) => $this->porAluno($q, $aluno))
            ->groupBy('r.avaliacao_codigo', 'q.area', 'r.periodo', 'q.periodo_minimo')
            ->selectRaw('r.avaliacao_codigo as codigo, q.area as area, r.periodo as periodo_aluno, q.periodo_minimo as minimo')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN '.Anulacao::condicaoAcertoSql('r.resposta', 'q.gabarito', 'q.anulada_modo').' THEN 1 ELSE 0 END) as acertos')
            ->get();

        if ($linhas->isEmpty()) {
            return ['avaliacoes' => [], 'areas' => []];
        }

        $codigosComDado = $linhas->pluck('codigo')->unique()->all();

        $avaliacoes = DB::table('avaliacoes')
            ->whereIn('codigo', $codigosComDado)
            ->whereNull('deleted_at')
            ->orderBy('data_avaliacao')
            ->orderBy('codigo')
            ->select('codigo', 'nome')
            ->get()
            ->map(fn ($a) => ['codigo' => (int) $a->codigo, 'nome' => $a->nome])
            ->all();

        // Várias linhas por célula (uma por período do aluno × meta da questão): soma antes de calcular.
        $celulas = [];
        foreach ($linhas as $linha) {
            $total = (int) $linha->total;
            $ordinal = PeriodoCurso::ordinal($linha->periodo_aluno);
            $minimo = $linha->minimo !== null ? (int) $linha->minimo : null;
            $aFrente = $ordinal !== null && $minimo !== null && $minimo > $ordinal;

            $c = &$celulas[$linha->area][(int) $linha->codigo];
            $c ??= ['total' => 0, 'acertos' => 0, 'esperadas' => 0, 'comMeta' => false];
            $c['total'] += $total;
            $c['acertos'] += (int) $linha->acertos;
            $c['esperadas'] += $aFrente ? 0 : $total;
            $c['comMeta'] = $c['comMeta'] || ($minimo !== null && $ordinal !== null);
            unset($c);
        }

        ksort($celulas);

        $areas = [];
        foreach ($celulas as $area => $porCodigo) {
            $valores = [];
            $esperados = [];
            foreach ($avaliacoes as $avaliacao) {
                $c = $porCodigo[$avaliacao['codigo']] ?? null;
                $temDado = $c !== null && $c['total'] > 0;
                $valores[$avaliacao['codigo']] = $temDado ? round($c['acertos'] / $c['total'] * 100, 1) : null;
                $esperados[$avaliacao['codigo']] = ! $temDado ? null
                    : ($c['comMeta'] ? round($c['esperadas'] / $c['total'] * 100, 1) : self::MINIMO_PADRAO);
            }
            $areas[] = ['area' => (string) $area, 'valores' => $valores, 'esperados' => $esperados];
        }
        return ['avaliacoes' => $avaliacoes, 'areas' => $areas];
    }

    /** Mesmo critério de identidade usado em ResultadoConsultaService::porAluno() — nunca casar por RA/CPF vazios. */
    private function porAluno(BuilderContract $query, Aluno $aluno): BuilderContract
    {
        if (! $aluno->ra && ! $aluno->cpf) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($aluno) {
            if ($aluno->ra) {
                $q->where('r.ra', $aluno->ra);
            }
            if ($aluno->cpf) {
                $q->orWhere('r.cpf', $aluno->cpf);
            }
        });
    }
}
