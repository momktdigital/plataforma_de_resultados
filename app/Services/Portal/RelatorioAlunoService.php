<?php

namespace App\Services\Portal;

use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Support\AlunoVinculoResolver;
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

    /** @return array{turma: string, suaMedia: float, mediaTurma: float, respondentesTurma: int}|null */
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

    /** @return array{posicao: int, totalRespondentes: int, percentil: float}|null */
    public function rankingPercentil(Aluno $aluno, Avaliacao $avaliacao, string $periodo): ?array
    {
        $resumos = DB::table('resultado_resumos')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->where('periodo', $periodo)
            ->whereNotNull('percentual')
            ->select('ra', 'cpf', 'percentual')
            ->get();

        // Casa por ra OU cpf (nunca uma chave só) pelo mesmo motivo de
        // comparativoTurma() logo acima: `aluno_chave` é COALESCE(cpf, ra) da
        // LINHA importada, que pode não ter cpf preenchido mesmo quando o
        // cadastro do Aluno tem — presumir "$aluno->cpf ?: $aluno->ra" aqui
        // deixava de encontrar a própria linha do aluno nesse caso.
        $suaLinha = $resumos->first(fn ($r) => $r->ra === $aluno->ra || ($aluno->cpf && $r->cpf === $aluno->cpf));
        if ($suaLinha === null || $resumos->count() < 2) {
            return null;
        }

        $suaNota = (float) $suaLinha->percentual;
        $total = $resumos->count();
        $posicao = $resumos->filter(fn ($r) => (float) $r->percentual > $suaNota)->count() + 1;

        return [
            'posicao' => $posicao,
            'totalRespondentes' => $total,
            'percentil' => round(($total - $posicao + 1) / $total * 100, 1),
        ];
    }

    /** @return array<string, float> */
    public function radarDisciplina(Collection $respostas, Collection $gabaritos, Avaliacao $avaliacao): array
    {
        $disciplinasPorNumero = DB::table('questao_matrizes as m')
            ->join('questoes as q', 'q.id', '=', 'm.questao_id')
            ->where('q.avaliacao_codigo', $avaliacao->codigo)
            ->whereNull('q.deleted_at')
            ->whereNotNull('m.disciplina')
            ->where('m.disciplina', '!=', '')
            ->select('q.numero', 'm.disciplina')
            ->get()
            ->groupBy('numero')
            ->map(fn ($grupo) => $grupo->pluck('disciplina')->unique()->all());

        return $this->mediaPorAgrupamentoMultiplo($respostas, $gabaritos, $disciplinasPorNumero);
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

    /** @return array<int, array{numero: int, sua_resposta: string, gabarito: string, acertou: bool, taxa_acerto_turma: float}> */
    public function comparativoQuestao(Avaliacao $avaliacao, string $periodo, Collection $respostas, Collection $gabaritos): array
    {
        $taxasPorQuestao = DB::table('respostas as r')
            ->join('questoes as q', function ($join) use ($avaliacao) {
                $join->on('q.numero', '=', 'r.questao_numero')
                    ->where('q.avaliacao_codigo', $avaliacao->codigo)
                    ->whereNull('q.deleted_at')
                    ->whereNotNull('q.gabarito')
                    ->where('q.gabarito', '!=', '');
            })
            ->where('r.avaliacao_codigo', $avaliacao->codigo)
            ->where('r.periodo', $periodo)
            ->groupBy('r.questao_numero')
            ->selectRaw('r.questao_numero as numero')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN r.resposta = q.gabarito THEN 1 ELSE 0 END) as acertos')
            ->get()
            ->keyBy('numero');

        $resultado = [];
        foreach ($respostas as $resposta) {
            $gabarito = $gabaritos->get($resposta->questao_numero);
            if ($gabarito === null || $gabarito === '') {
                continue;
            }

            $taxa = $taxasPorQuestao->get($resposta->questao_numero);

            $resultado[] = [
                'numero' => (int) $resposta->questao_numero,
                'sua_resposta' => (string) ($resposta->resposta ?: ''),
                'gabarito' => $gabarito,
                'acertou' => $resposta->resposta === $gabarito,
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
            ->select('numero', 'area', 'tema')
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

            if ($resposta->resposta === $gabarito) {
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
        $valoresPorNumero = DB::table('questoes')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->whereNull('deleted_at')
            ->whereNotNull($campo)
            ->where($campo, '!=', '')
            ->pluck($campo, 'numero')
            ->map(fn ($v) => [$v]);

        return $this->mediaPorAgrupamentoMultiplo($respostas, $gabaritos, $valoresPorNumero);
    }

    /**
     * Cada questão pode entrar em mais de um grupo (uma questão com 2 disciplinas
     * na matriz conta o acerto/erro pra cada uma) — por isso o mapa é
     * numero => lista de grupos, não numero => grupo único.
     *
     * @param  Collection<int, array<int, string>>  $gruposPorNumero
     * @return array<string, float>
     */
    private function mediaPorAgrupamentoMultiplo(Collection $respostas, Collection $gabaritos, Collection $gruposPorNumero): array
    {
        $acumulado = [];

        foreach ($respostas as $resposta) {
            $grupos = $gruposPorNumero->get($resposta->questao_numero);
            $gabarito = $gabaritos->get($resposta->questao_numero);

            if (empty($grupos) || $gabarito === null || $gabarito === '') {
                continue;
            }

            $acertou = $resposta->resposta === $gabarito;

            foreach ($grupos as $grupo) {
                $acumulado[$grupo] ??= ['acertos' => 0, 'total' => 0];
                $acumulado[$grupo]['total']++;
                $acumulado[$grupo]['acertos'] += $acertou ? 1 : 0;
            }
        }

        $resultado = [];
        foreach ($acumulado as $grupo => $s) {
            $resultado[$grupo] = $s['total'] > 0 ? round($s['acertos'] / $s['total'] * 100, 1) : 0.0;
        }

        return $resultado;
    }
}
