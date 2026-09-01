<?php

namespace App\Services\Portal;

use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Support\AlunoVinculoResolver;
use App\Support\Anulacao;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Análises individuais mostradas no boletim do aluno (comparativo com a
 * turma, radar por disciplina, evolução histórica...) — complementam a nota/
 * grade de questões já existentes em Portal\ResultadoConsultaService.
 *
 * Diferente de RelatorioAdminService (que agrega em SQL por escalar a milhares
 * de respondentes), aqui a base é sempre as respostas de UM aluno numa
 * avaliação — no máximo algumas centenas de questões — então agrupar em PHP
 * sobre a Collection já carregada é seguro e evita consultas extras.
 */
class RelatorioAlunoService
{
    public function __construct(
        private readonly AlunoVinculoResolver $alunoResolver = new AlunoVinculoResolver,
    ) {}

    /**
     * A única das duas consultas de resultado_resumos que continua trazendo
     * a Collection inteira: comparativoTurma() precisa do percentual de cada
     * respondente pra agrupar por turma via AlunoVinculoResolver, algo que
     * rankingPercentil() (logo abaixo) não precisa mais fazer.
     *
     * @return array{turma: string, suaMedia: float, mediaTurma: float, respondentesTurma: int}|null
     */
    public function comparativoTurma(Aluno $aluno, Avaliacao $avaliacao, string $periodo): ?array
    {
        if (empty($aluno->turma)) {
            return null;
        }

        $resumos = DB::table('resultado_resumos')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->where('periodo', $periodo)
            ->whereNotNull('percentual')
            ->select('aluno_chave', 'ra', 'cpf', 'percentual')
            ->get();

        $suaMedia = $resumos->first(fn ($r) => $r->ra === $aluno->ra || ($aluno->cpf && $r->cpf === $aluno->cpf))?->percentual;
        if ($suaMedia === null) {
            return null;
        }

        $alunos = $this->alunoResolver->resolver($avaliacao->codigo, $periodo);
        $percentuaisDaTurma = $resumos
            ->filter(fn ($r) => $alunos->get($r->aluno_chave)?->turma === $aluno->turma)
            ->pluck('percentual');

        if ($percentuaisDaTurma->isEmpty()) {
            return null;
        }

        return [
            'turma' => $aluno->turma,
            'suaMedia' => (float) $suaMedia,
            'mediaTurma' => round((float) $percentuaisDaTurma->avg(), 1),
            'respondentesTurma' => $percentuaisDaTurma->count(),
        ];
    }

    /**
     * Ao contrário de comparativoTurma() (que precisa de toda a Collection
     * pra agrupar por turma), o ranking só precisa da própria nota do aluno e
     * de duas contagens — resolvidas em SQL (COUNT) em vez de carregar
     * `resultado_resumos` inteira pra PHP só pra comparar percentuais.
     *
     * @return array{posicao: int, totalRespondentes: int, percentil: float}|null
     */
    public function rankingPercentil(Aluno $aluno, Avaliacao $avaliacao, string $periodo): ?array
    {
        $base = DB::table('resultado_resumos')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->where('periodo', $periodo)
            ->whereNotNull('percentual');

        // Casa por ra OU cpf (nunca uma chave só) pelo mesmo motivo do
        // comentário de mediaTurma() logo acima: `aluno_chave` é
        // COALESCE(cpf, ra) da LINHA importada, que pode não ter cpf
        // preenchido mesmo quando o cadastro do Aluno tem — presumir
        // "$aluno->cpf ?: $aluno->ra" aqui deixava de encontrar a própria
        // linha do aluno nesse caso.
        $suaNota = (clone $base)
            ->where(function ($query) use ($aluno) {
                $query->where('ra', $aluno->ra);
                if ($aluno->cpf) {
                    $query->orWhere('cpf', $aluno->cpf);
                }
            })
            ->value('percentual');

        if ($suaNota === null) {
            return null;
        }

        $suaNota = (float) $suaNota;
        $total = (clone $base)->count();

        if ($total < 2) {
            return null;
        }

        $posicao = (clone $base)->where('percentual', '>', $suaNota)->count() + 1;

        return [
            'posicao' => $posicao,
            'totalRespondentes' => $total,
            'percentil' => round(($total - $posicao + 1) / $total * 100, 1),
        ];
    }

    /**
     * Versão consolidada de comparativoTurma() — em vez de "você x turma"
     * numa avaliação só, faz a média simples do aluno e da turma ao longo de
     * TODAS as avaliações do período informado (mesmo critério de média
     * usada em PortalController::renderizarResultados() pra "média geral":
     * média das médias, não ponderada por quantidade de questões).
     *
     * @param  array<int, array<string, mixed>>  $resultados  já filtrados pelo período letivo selecionado
     * @return array{turma: string, suaMedia: float, mediaTurma: float, avaliacoesComparadas: int}|null
     */
    public function comparativoTurmaConsolidado(Aluno $aluno, array $resultados): ?array
    {
        if (empty($aluno->turma)) {
            return null;
        }

        $comparativos = collect($resultados)
            ->map(fn ($r) => $this->comparativoTurma($aluno, $r['avaliacao'], $r['periodo']))
            ->filter();

        if ($comparativos->isEmpty()) {
            return null;
        }

        return [
            'turma' => $aluno->turma,
            'suaMedia' => round((float) $comparativos->avg('suaMedia'), 1),
            'mediaTurma' => round((float) $comparativos->avg('mediaTurma'), 1),
            'avaliacoesComparadas' => $comparativos->count(),
        ];
    }

    /** @return array<string, float> */
    public function radarDisciplina(Collection $respostas, Collection $gabaritos, Avaliacao $avaliacao): array
    {
        $linhas = DB::table('questao_matrizes as m')
            ->join('questoes as q', 'q.id', '=', 'm.questao_id')
            ->where('q.avaliacao_codigo', $avaliacao->codigo)
            ->whereNull('q.deleted_at')
            ->whereNotNull('m.disciplina')
            ->where('m.disciplina', '!=', '')
            ->select('q.numero', 'm.disciplina', 'q.anulada_modo')
            ->get();

        $disciplinasPorNumero = $linhas->groupBy('numero')->map(fn ($grupo) => $grupo->pluck('disciplina')->unique()->all());
        $anuladasPorNumero = $linhas->pluck('anulada_modo', 'numero');

        return $this->mediaPorAgrupamentoMultiplo($respostas, $gabaritos, $disciplinasPorNumero, $anuladasPorNumero);
    }

    /** @return array<string, float> */
    public function desempenhoPorArea(Collection $respostas, Collection $gabaritos, Avaliacao $avaliacao): array
    {
        return $this->desempenhoPorCampoDireto($respostas, $gabaritos, $avaliacao, 'area');
    }

    /** @return array<string, float> */
    public function desempenhoPorBloom(Collection $respostas, Collection $gabaritos, Avaliacao $avaliacao): array
    {
        return $this->desempenhoPorCampoDireto($respostas, $gabaritos, $avaliacao, 'bloom_nivel');
    }

    /** @return array<string, float> */
    public function desempenhoPorMiller(Collection $respostas, Collection $gabaritos, Avaliacao $avaliacao): array
    {
        return $this->desempenhoPorCampoDireto($respostas, $gabaritos, $avaliacao, 'miller_nivel');
    }

    /** @return array<int, array{numero: int, sua_resposta: string, gabarito: string, anulada: bool, acertou: bool, taxa_acerto_turma: float}> */
    public function comparativoQuestao(Avaliacao $avaliacao, string $periodo, Collection $respostas, Collection $gabaritos): array
    {
        $taxasPorQuestao = DB::table('respostas as r')
            ->join('questoes as q', function ($join) use ($avaliacao) {
                Anulacao::excluirDistribuidas(
                    $join->on('q.numero', '=', 'r.questao_numero')
                        ->where('q.avaliacao_codigo', $avaliacao->codigo)
                        ->whereNull('q.deleted_at')
                        ->whereNotNull('q.gabarito')
                        ->where('q.gabarito', '!=', ''),
                    'q.anulada_modo',
                );
            })
            ->where('r.avaliacao_codigo', $avaliacao->codigo)
            ->where('r.periodo', $periodo)
            ->groupBy('r.questao_numero')
            ->selectRaw('r.questao_numero as numero')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN '.Anulacao::condicaoAcertoSql('r.resposta', 'q.gabarito', 'q.anulada_modo').' THEN 1 ELSE 0 END) as acertos')
            ->get()
            ->keyBy('numero');

        $anuladasPorNumero = DB::table('questoes')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->whereNull('deleted_at')
            ->pluck('anulada_modo', 'numero');

        $resultado = [];
        foreach ($respostas as $resposta) {
            $gabarito = $gabaritos->get($resposta->questao_numero);
            if ($gabarito === null || $gabarito === '') {
                continue;
            }

            $anuladaModo = $anuladasPorNumero->get($resposta->questao_numero);
            $taxa = $taxasPorQuestao->get($resposta->questao_numero);

            $resultado[] = [
                'numero' => (int) $resposta->questao_numero,
                'sua_resposta' => (string) ($resposta->resposta ?: ''),
                'gabarito' => $gabarito,
                'anulada' => $anuladaModo !== null,
                'acertou' => Anulacao::acertou($resposta->resposta, $gabarito, $anuladaModo),
                'taxa_acerto_turma' => $taxa !== null && (int) $taxa->total > 0
                    ? round((int) $taxa->acertos / (int) $taxa->total * 100, 1)
                    : 0.0,
            ];
        }

        return $resultado;
    }

    /**
     * @param  array<int, array<string, mixed>>  $resultadosDoAluno  saída de ResultadoConsultaService::buscarPorAluno()
     * @return array<int, array{codigo: int, nome: string, data: ?string, percentual: ?float}>
     */
    public function evolucaoHistorica(array $resultadosDoAluno, ?int $categoriaId): array
    {
        if ($categoriaId === null) {
            return [];
        }

        return collect($resultadosDoAluno)
            ->filter(fn ($r) => $r['avaliacao']->categoria_id === $categoriaId)
            ->sortBy(fn ($r) => $r['avaliacao']->data_avaliacao?->format('Y-m-d') ?? '0000-00-00')
            ->map(fn ($r) => [
                'codigo' => $r['avaliacao']->codigo,
                'nome' => $r['avaliacao']->nome ?? "Avaliação #{$r['avaliacao']->codigo}",
                'data' => $r['avaliacao']->data_avaliacao?->format('d/m/Y'),
                'percentual' => $r['percentual'],
            ])
            ->values()
            ->all();
    }

    /**
     * Agrupa evolucaoHistorica() por categoria — usada no boletim (lista de
     * resultados) pra mostrar uma série por categoria em vez de misturar
     * avaliações de categorias diferentes numa única linha (percentuais de
     * provas com propósitos distintos não são comparáveis entre si). Mesmo
     * critério de "pelo menos 2 avaliações com data e percentual" já usado
     * em VisualizacaoDisponibilidadeService::calcular() para 'evolucao_categoria'.
     *
     * @param  array<int, array<string, mixed>>  $resultadosDoAluno  saída de ResultadoConsultaService::buscarPorAluno()
     * @return array<int, array{categoria_id: int, categoria_nome: string, pontos: array<int, array{codigo: int, nome: string, data: string, percentual: float}>}>
     */
    public function evolucaoPorCategoria(array $resultadosDoAluno): array
    {
        $porCategoriaId = collect($resultadosDoAluno)
            ->filter(fn ($r) => $r['avaliacao']->categoria_id !== null)
            ->groupBy(fn ($r) => $r['avaliacao']->categoria_id);

        $resultado = [];
        foreach ($porCategoriaId as $categoriaId => $doCategoria) {
            $pontos = collect($this->evolucaoHistorica($resultadosDoAluno, (int) $categoriaId))
                ->filter(fn ($p) => $p['percentual'] !== null && $p['data'] !== null)
                ->values()
                ->all();

            if (count($pontos) < 2) {
                continue;
            }

            $resultado[] = [
                'categoria_id' => (int) $categoriaId,
                'categoria_nome' => $doCategoria->first()['avaliacao']->categoria->nome ?? "Categoria #{$categoriaId}",
                'pontos' => $pontos,
            ];
        }

        usort($resultado, fn ($a, $b) => strcmp($a['categoria_nome'], $b['categoria_nome']));

        return $resultado;
    }

    /**
     * Cartões explicativos por área: "lacunas" agrupa as questões que o
     * aluno errou (por área, listando os temas envolvidos) e "consolidados"
     * as que ele acertou — pensados pra dar um resumo em texto, não só em
     * gráfico, de onde vale reforçar o estudo e onde a base já está sólida.
     * Só entra questão com área E tema cadastrados (ver 'lacunas_conhecimentos'
     * em VisualizacaoDisponibilidadeService). A frase usada por área roda
     * entre 3 variações (ver self::TEMPLATES_LACUNA/CONSOLIDADO) só pra não
     * repetir o mesmo texto quando a avaliação tem várias áreas.
     *
     * @return array{lacunas: array<int, array{area: string, total: int, texto: string}>, consolidados: array<int, array{area: string, total: int, texto: string}>}
     */
    public function lacunasEConsolidados(Collection $respostas, Collection $gabaritos, Avaliacao $avaliacao): array
    {
        $metaPorNumero = DB::table('questoes')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->whereNull('deleted_at')
            ->whereNotNull('area')->where('area', '!=', '')
            ->whereNotNull('tema')->where('tema', '!=', '')
            ->select('numero', 'area', 'tema', 'anulada_modo')
            ->get()
            ->keyBy('numero');

        $errosPorArea = [];
        $acertosPorArea = [];

        foreach ($respostas as $resposta) {
            $meta = $metaPorNumero->get($resposta->questao_numero);
            $gabarito = $gabaritos->get($resposta->questao_numero);
            if ($meta === null || $gabarito === null || $gabarito === '') {
                continue;
            }

            // Questão distribuir_pontuacao saiu da prova — não deve aparecer
            // nem como lacuna nem como conhecimento consolidado, pois não
            // reflete mais o conteúdo oficialmente avaliado.
            if (Anulacao::distribuida($meta->anulada_modo)) {
                continue;
            }

            if (Anulacao::acertou($resposta->resposta, $gabarito, $meta->anulada_modo)) {
                $acertosPorArea[$meta->area]['total'] = ($acertosPorArea[$meta->area]['total'] ?? 0) + 1;
                $acertosPorArea[$meta->area]['temas'][$meta->tema] = true;
            } else {
                $errosPorArea[$meta->area]['total'] = ($errosPorArea[$meta->area]['total'] ?? 0) + 1;
                $errosPorArea[$meta->area]['temas'][$meta->tema] = true;
            }
        }

        return [
            'lacunas' => $this->montarCardsPorArea($errosPorArea, self::TEMPLATES_LACUNA),
            'consolidados' => $this->montarCardsPorArea($acertosPorArea, self::TEMPLATES_CONSOLIDADO),
        ];
    }

    private const TEMPLATES_LACUNA = [
        'Foram %d questão(ões) sem acerto, envolvendo %s. Retomar esses conteúdos com revisão dirigida e questões comentadas tende a consolidar a compreensão.',
        'Área com %d ponto(s) a recuperar — em especial %s. Vale priorizar a base conceitual antes dos exercícios de fixação.',
        'Concentração de dificuldades em %d questão(ões) (%s). Um plano de estudo com metas semanais nesses temas ajuda a fechar a lacuna.',
    ];

    private const TEMPLATES_CONSOLIDADO = [
        'Bom domínio em %d questão(ões), abrangendo %s. Esse resultado indica base sólida na área.',
        'Desempenho firme em %d questão(ões) — incluindo %s. Um ponto de apoio para avançar em conteúdos mais complexos.',
        '%d acerto(s) demonstram segurança em %s. Vale aprofundar com desafios de maior nível.',
    ];

    /**
     * @param  array<string, array{total: int, temas: array<string, bool>}>  $porArea
     * @param  array<int, string>  $templates
     * @return array<int, array{area: string, total: int, texto: string}>
     */
    private function montarCardsPorArea(array $porArea, array $templates): array
    {
        $cards = [];
        $indice = 0;
        foreach ($porArea as $area => $dados) {
            $texto = sprintf($templates[$indice % count($templates)], $dados['total'], $this->juntarTemas(array_keys($dados['temas'])));
            $cards[] = ['area' => $area, 'total' => $dados['total'], 'texto' => $texto];
            $indice++;
        }

        usort($cards, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $cards;
    }

    /** @param  array<int, string>  $temas */
    private function juntarTemas(array $temas): string
    {
        $lista = array_slice($temas, 0, 4);
        if (count($lista) === 1) {
            return $lista[0];
        }

        $ultimo = array_pop($lista);

        return implode(', ', $lista).' e '.$ultimo;
    }

    /** @return array<string, float> */
    private function desempenhoPorCampoDireto(Collection $respostas, Collection $gabaritos, Avaliacao $avaliacao, string $campo): array
    {
        $linhas = DB::table('questoes')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->whereNull('deleted_at')
            ->whereNotNull($campo)
            ->where($campo, '!=', '')
            ->select('numero', "{$campo} as valor", 'anulada_modo')
            ->get()
            ->keyBy('numero');

        $valoresPorNumero = $linhas->map(fn ($l) => [$l->valor]);
        $anuladasPorNumero = $linhas->pluck('anulada_modo', 'numero');

        return $this->mediaPorAgrupamentoMultiplo($respostas, $gabaritos, $valoresPorNumero, $anuladasPorNumero);
    }

    /**
     * Cada questão pode entrar em mais de um grupo (uma questão com 2 disciplinas
     * na matriz conta o acerto/erro pra cada uma) — por isso o mapa é
     * numero => lista de grupos, não numero => grupo único.
     *
     * @param  Collection<int, array<int, string>>  $gruposPorNumero
     * @param  ?Collection<int, ?string>  $anuladasPorNumero  numero => anulada_modo
     * @return array<string, float>
     */
    private function mediaPorAgrupamentoMultiplo(Collection $respostas, Collection $gabaritos, Collection $gruposPorNumero, ?Collection $anuladasPorNumero = null): array
    {
        $acumulado = $this->contagemPorAgrupamentoMultiplo($respostas, $gabaritos, $gruposPorNumero, $anuladasPorNumero);

        $resultado = [];
        foreach ($acumulado as $grupo => $s) {
            $resultado[$grupo] = $s['total'] > 0 ? round($s['acertos'] / $s['total'] * 100, 1) : 0.0;
        }

        return $resultado;
    }

    /**
     * Cada questão pode entrar em mais de um grupo (uma questão com 2 disciplinas
     * na matriz conta o acerto/erro pra cada uma) — por isso o mapa é
     * numero => lista de grupos, não numero => grupo único.
     *
     * @param  Collection<int, array<int, string>>  $gruposPorNumero
     * @param  ?Collection<int, ?string>  $anuladasPorNumero  numero => anulada_modo
     * @return array<string, array{acertos: int, total: int}>
     */
    private function contagemPorAgrupamentoMultiplo(Collection $respostas, Collection $gabaritos, Collection $gruposPorNumero, ?Collection $anuladasPorNumero = null): array
    {
        $acumulado = [];

        foreach ($respostas as $resposta) {
            $grupos = $gruposPorNumero->get($resposta->questao_numero);
            $gabarito = $gabaritos->get($resposta->questao_numero);

            if (empty($grupos) || $gabarito === null || $gabarito === '') {
                continue;
            }

            $anuladaModo = $anuladasPorNumero?->get($resposta->questao_numero);
            if (Anulacao::distribuida($anuladaModo)) {
                continue;
            }

            $acertou = Anulacao::acertou($resposta->resposta, $gabarito, $anuladaModo);

            foreach ($grupos as $grupo) {
                $acumulado[$grupo] ??= ['acertos' => 0, 'total' => 0];
                $acumulado[$grupo]['total']++;
                $acumulado[$grupo]['acertos'] += $acertou ? 1 : 0;
            }
        }

        return $acumulado;
    }

    /**
     * Versão de desempenhoPorArea() com a contagem bruta (não só o %) —
     * usada pelos cards "total por área" do boletim, que mostram o valor
     * absoluto de acertos em destaque e o percentual entre parênteses.
     *
     * @return array<string, array{acertos: int, total: int, percentual: float}>
     */
    public function desempenhoPorAreaComContagem(Collection $respostas, Collection $gabaritos, Avaliacao $avaliacao): array
    {
        $linhas = DB::table('questoes')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->whereNull('deleted_at')
            ->whereNotNull('area')
            ->where('area', '!=', '')
            ->select('numero', 'area as valor', 'anulada_modo')
            ->get()
            ->keyBy('numero');

        $valoresPorNumero = $linhas->map(fn ($l) => [$l->valor]);
        $anuladasPorNumero = $linhas->pluck('anulada_modo', 'numero');

        $acumulado = $this->contagemPorAgrupamentoMultiplo($respostas, $gabaritos, $valoresPorNumero, $anuladasPorNumero);

        $resultado = [];
        foreach ($acumulado as $area => $s) {
            $resultado[$area] = [
                'acertos' => $s['acertos'],
                'total' => $s['total'],
                'percentual' => $s['total'] > 0 ? round($s['acertos'] / $s['total'] * 100, 1) : 0.0,
            ];
        }

        return $resultado;
    }
}
