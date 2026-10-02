<?php

namespace App\Services;

use App\Models\Avaliacao;
use App\Models\Resposta;
use App\Support\Anulacao;
use App\Support\CacheDeAnalise;
use App\Support\Concerns\ComEscopoDeCurso;
use App\Support\Psicometria;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Análise de itens da avaliação: dificuldade observada, discriminação,
 * ponto-bisserial por questão e KR-20 da prova inteira. As fórmulas ficam em
 * App\Support\Psicometria (puras, testadas sem banco); aqui mora só a parte
 * que toca `respostas`.
 *
 * Toda soma acontece em SQL (JOIN + GROUP BY + SUM(CASE...)) — `respostas`
 * cresce com aluno × avaliação × período × questão e uma avaliação de 100
 * questões com 10.000 respondentes já é 1 milhão de linhas (ver CLAUDE.md).
 * O único dado que chega ao PHP é uma linha POR RESPONDENTE (o total de
 * acertos dele), que é a ordem de grandeza de `resultado_resumos` e é
 * necessária para os cortes de 27% e para a variância do KR-20.
 *
 * Esse total por respondente (e se ele esteve ausente) NÃO é recalculado aqui:
 * vem de `resultado_resumos` (`acertos_itens`, `itens_considerados`, `ausente`,
 * gravados por ResumoResultadoService com o mesmo recorte de itens de
 * baseItens()). Recalculá-lo varrendo `respostas` em cada análise era o grosso
 * do tempo do Dashboard. Só a simulação de remoção de itens precisa de um
 * escore diferente (sem os itens fracos) e ainda o calcula em `respostas`.
 *
 * QUESTÕES ANULADAS FICAM DE FORA da análise inteira — em qualquer modo de
 * anulação. Não é um esquecimento da regra do Anulacao: é mais restritivo que
 * ela. Uma questão com `dar_ponto` credita todo mundo, então a dificuldade
 * observada vira 100% e a discriminação vira 0 artificialmente — número que
 * poluiria o mapa de itens sem dizer nada sobre a qualidade da questão. E o
 * mapa existe justamente para decidir o que fazer com um item que AINDA não
 * recebeu decisão do coordenador (mesmo raciocínio de
 * EstatisticaErroService, que também ignora anuladas nas "questões críticas").
 */
class PsicometriaService
{
    use ComEscopoDeCurso;

    /** @var array<string, array<int, array<string, mixed>>> ver agregadosDosItens() */
    private array $agregadosMemo = [];

    /**
     * Abaixo disto os cortes de 27% viram grupos minúsculos e o D não
     * significa nada. Público porque VisualizacaoDisponibilidadeService usa o
     * mesmo número para decidir se o visual sequer aparece.
     */
    public const MINIMO_RESPONDENTES = 10;

    /**
     * @return array{
     *   respondentes: int,
     *   questoes: int,
     *   media: float,
     *   mediana: float,
     *   desvio: float,
     *   kr20: ?float,
     *   itens: array<int, array{numero: int, area: ?string, tema: ?string, gabarito: string, respostas: int, acertos: int, emBranco: int, dificuldade: float, discriminacao: ?float, pontoBisserial: ?float, faixa: string, rotulo: string, acao: string}>,
     *   simulacao: ?array{removidos: array<int, int>, kr20: ?float, ganho: float} (ganho arredondado a 2 casas — ver simularRemocao())
     * }|null
     */
    public function analisar(Avaliacao $avaliacao, string $periodo = ''): ?array
    {
        $escores = $this->escores($avaliacao, $periodo);

        if (count($escores) < self::MINIMO_RESPONDENTES) {
            return null;
        }

        sort($escores);

        $desvioEscores = Psicometria::desvioPadrao($escores);

        $itens = $this->itens($avaliacao, $periodo, $escores, $desvioEscores);

        // Nº de itens = os que têm resposta (uma linha por questão em itens()).
        $k = count($itens);

        if ($k < 1) {
            return null;
        }

        $percentuais = array_map(fn ($acertos) => $acertos / $k * 100, $escores);

        return [
            'respondentes' => count($escores),
            'questoes' => count($itens),
            'media' => round(array_sum($percentuais) / count($percentuais), 1),
            'mediana' => round(Psicometria::mediana($percentuais), 1),
            'desvio' => round(Psicometria::desvioPadrao($percentuais), 1),
            'semAusentes' => $this->mediaMedianaSemAusentes($avaliacao, $periodo, $k),
            'kr20' => Psicometria::kr20(count($itens), $this->somaPQ($itens), Psicometria::variancia($escores)),
            'itens' => $itens,
            'simulacao' => $this->simularRemocao($avaliacao, $periodo, $itens, $escores),
        ];
    }

    /**
     * Média e mediana (% de acerto) considerando só quem compareceu — a "outra
     * visão" ao lado da que conta ausente como nota 0.
     *
     * @return array{media: float, mediana: float, respondentes: int}|null
     */
    private function mediaMedianaSemAusentes(Avaliacao $avaliacao, string $periodo, int $k): ?array
    {
        $escores = $this->resumos($avaliacao, $periodo)
            ->where('rr.itens_considerados', '>', 0)
            ->where('rr.ausente', false)
            ->pluck('rr.acertos_itens')
            ->map(fn ($v) => (int) $v / $k * 100)
            ->all();

        if ($escores === []) {
            return null;
        }

        return [
            'media' => round(array_sum($escores) / count($escores), 1),
            'mediana' => round(Psicometria::mediana($escores), 1),
            'respondentes' => count($escores),
        ];
    }

    /**
     * Presença na prova: respondente (aluno × período) AUSENTE é o que deixou a
     * prova inteira sem resposta (todas as linhas em branco/sentinela). Conta
     * todas as questões da avaliação, inclusive anuladas — ausência é sobre o
     * aluno ter comparecido, não sobre a questão valer nota.
     *
     * @return array{total: int, presentes: int, ausentes: int, percentual: float}
     */
    public function presenca(Avaliacao $avaliacao, string $periodo = ''): array
    {
        $linha = $this->resumos($avaliacao, $periodo)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN rr.ausente = 0 THEN 1 ELSE 0 END) as presentes')
            ->first();

        $total = (int) ($linha->total ?? 0);
        $presentes = (int) ($linha->presentes ?? 0);

        return [
            'total' => $total,
            'presentes' => $presentes,
            'ausentes' => $total - $presentes,
            'percentual' => $total > 0 ? round($presentes / $total * 100, 1) : 0.0,
        ];
    }

    /**
     * % de acerto por quinto de desempenho geral, de TODAS as questões de uma
     * vez — a curva característica de cada item. Uma questão saudável sobe da
     * esquerda para a direita; uma curva plana ou invertida é o retrato de um
     * item que não mede o que a prova mede.
     *
     * Sai da mesma varredura de `respostas` da análise de itens (ver
     * agregadosDosItens()): a tela deixa o usuário clicar de item em item, e
     * uma consulta por clique seria uma varredura por curiosidade do
     * coordenador.
     *
     * @return array<int, array<int, float>> número da questão => [quinto => % de acerto]
     */
    public function curvasCaracteristicas(Avaliacao $avaliacao, string $periodo = ''): array
    {
        $escores = $this->escores($avaliacao, $periodo);

        if (count($escores) < self::MINIMO_RESPONDENTES) {
            return [];
        }

        sort($escores);

        $porQuinto = [];
        foreach ($this->agregadosDosItens($avaliacao, $periodo, $escores) as $linha) {
            $celula = &$porQuinto[(int) $linha['numero']][(int) $linha['quinto']];
            $celula['respondentes'] = ($celula['respondentes'] ?? 0) + (int) $linha['respostas'];
            $celula['acertos'] = ($celula['acertos'] ?? 0) + (int) $linha['acertos'];
            unset($celula);
        }

        $curvas = [];
        foreach ($porQuinto as $numero => $quintos) {
            foreach ($quintos as $quinto => $c) {
                $curvas[$numero][$quinto] = $c['respondentes'] > 0 ? round($c['acertos'] / $c['respondentes'] * 100, 1) : 0.0;
            }
            ksort($curvas[$numero]);
        }

        return $curvas;
    }

    /**
     * Uma linha por item com as duas medidas de discriminação.
     *
     * @param  array<int, int>  $escores  ordenados
     * @return array<int, array<string, mixed>>
     */
    private function itens(Avaliacao $avaliacao, string $periodo, array $escores, float $desvioEscores): array
    {
        $porItem = [];
        foreach ($this->agregadosDosItens($avaliacao, $periodo, $escores) as $l) {
            $i = &$porItem[(int) $l['numero']];
            $i ??= [
                'gabarito' => $l['gabarito'], 'area' => $l['area'], 'tema' => $l['tema'],
                'respostas' => 0, 'acertos' => 0, 'em_branco' => 0,
                'n_superior' => 0, 'acertos_superior' => 0, 'n_inferior' => 0, 'acertos_inferior' => 0,
                'soma_escore_acertou' => 0, 'soma_escore_errou' => 0,
            ];
            $i['respostas'] += (int) $l['respostas'];
            $i['acertos'] += (int) $l['acertos'];
            $i['em_branco'] += (int) $l['em_branco'];
            if ((int) $l['superior'] === 1) {
                $i['n_superior'] += (int) $l['respostas'];
                $i['acertos_superior'] += (int) $l['acertos'];
            }
            if ((int) $l['inferior'] === 1) {
                $i['n_inferior'] += (int) $l['respostas'];
                $i['acertos_inferior'] += (int) $l['acertos'];
            }
            $i['soma_escore_acertou'] += (int) $l['soma_escore_acertou'];
            $i['soma_escore_errou'] += (int) $l['soma_escore_errou'];
            unset($i);
        }
        ksort($porItem);

        $itens = [];
        foreach ($porItem as $numero => $l) {
            $respostas = $l['respostas'];
            $acertos = $l['acertos'];
            $erros = $respostas - $acertos;

            if ($respostas === 0) {
                continue;
            }

            $dificuldade = $acertos / $respostas;
            $d = Psicometria::discriminacao($l['acertos_superior'], $l['n_superior'], $l['acertos_inferior'], $l['n_inferior']);
            $faixa = Psicometria::classificar($d);

            $itens[] = [
                'numero' => $numero,
                'area' => $l['area'],
                'tema' => $l['tema'],
                'gabarito' => (string) $l['gabarito'],
                'respostas' => $respostas,
                'acertos' => $acertos,
                'emBranco' => $l['em_branco'],
                'dificuldade' => round($dificuldade * 100, 1),
                'discriminacao' => $d,
                'pontoBisserial' => Psicometria::pontoBisserial(
                    $acertos > 0 ? (float) $l['soma_escore_acertou'] / $acertos : null,
                    $erros > 0 ? (float) $l['soma_escore_errou'] / $erros : null,
                    $desvioEscores,
                    $dificuldade,
                ),
                'faixa' => $faixa,
                'rotulo' => Psicometria::rotulo($faixa),
                'acao' => Psicometria::acao($faixa),
            ];
        }

        return $itens;
    }

    /**
     * A ÚNICA varredura de `respostas` da análise de itens: respostas, acertos e brancos por (questão × quinto do
     * escore × grupo superior/inferior de 27%), com a soma dos escores de quem acertou/errou. Dela saem os dois
     * produtos — a análise de itens e as curvas características — que antes varriam `respostas` uma vez cada
     * (cada uma refazendo o escore de todo mundo). O escore de cada respondente vem de `resultado_resumos`.
     *
     * Memorizada por avaliação/período/escopo: analisar() e curvasCaracteristicas() são chamados em sequência
     * pela mesma tela.
     *
     * @param  array<int, int>  $escores  escores dos respondentes, ordenados (daí saem os cortes)
     * @return array<int, object>
     */
    private function agregadosDosItens(Avaliacao $avaliacao, string $periodo, array $escores): array
    {
        $memo = $avaliacao->codigo.'|'.$periodo.'|'.($this->escopo === null ? '*' : implode(',', $this->escopo->cursos));
        if (isset($this->agregadosMemo[$memo])) {
            return $this->agregadosMemo[$memo];
        }

        return $this->agregadosMemo[$memo] = CacheDeAnalise::lembrar(
            'itens',
            $avaliacao->codigo,
            ['periodo' => $periodo, 'escopo' => $this->assinaturaDoEscopo($avaliacao)],
            fn () => $this->calcularAgregadosDosItens($avaliacao, $periodo, $escores),
        );
    }

    /**
     * @param  array<int, int>  $escores
     * @return array<int, object>
     */
    private function calcularAgregadosDosItens(Avaliacao $avaliacao, string $periodo, array $escores): array
    {
        // Cortes calculados no PHP: NTILE() não existe no SQLite usado nos testes e só chegou ao MySQL no 8.0. Arredondados
        // às mesmas 6 casas com que sempre foram comparados (numero()).
        $superior = (float) $this->numero(Psicometria::percentil($escores, 1 - Psicometria::FRACAO_GRUPO_EXTREMO));
        $inferior = (float) $this->numero(Psicometria::percentil($escores, Psicometria::FRACAO_GRUPO_EXTREMO));
        $cortesQuintos = array_map(fn ($i) => (float) $this->numero(Psicometria::percentil($escores, $i / 5)), [1, 2, 3, 4]);
        $acerto = $this->condicaoAcerto();

        // O banco agrupa só por (questão × escore do respondente): colunas simples, ~questões × escores distintos
        // linhas. Quinto do escore e grupos de 27% são decididos aqui, no PHP, a partir do escore — a soma dos escores
        // de quem acertou/errou também (acertos × escore, erros × escore).
        $linhas = $this->comEscore($this->baseItens($avaliacao, $periodo), $avaliacao)
            ->groupBy('r.questao_numero', 'sc.acertos_itens')
            ->selectRaw('r.questao_numero as numero, sc.acertos_itens as escore')
            ->selectRaw('COUNT(*) as respostas')
            ->selectRaw("SUM(CASE WHEN {$acerto} THEN 1 ELSE 0 END) as acertos")
            ->selectRaw('SUM(CASE WHEN '.Resposta::semRespostaSql('r.resposta').' THEN 1 ELSE 0 END) as em_branco')
            ->get();

        $questoes = DB::table('questoes')
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->whereNull('deleted_at')
            ->whereNotNull('gabarito')->where('gabarito', '!=', '')
            ->whereNull('anulada_modo')
            ->get(['numero', 'gabarito', 'area', 'tema'])
            ->keyBy('numero');

        $grupos = [];
        foreach ($linhas as $l) {
            $escore = (int) $l->escore;
            $quinto = 5;
            foreach ($cortesQuintos as $i => $corte) {
                if ($escore <= $corte) {
                    $quinto = $i + 1;
                    break;
                }
            }
            $ehSuperior = $escore >= $superior ? 1 : 0;
            $ehInferior = $escore <= $inferior ? 1 : 0;

            $numero = (int) $l->numero;
            $chave = "{$numero}|{$quinto}|{$ehSuperior}|{$ehInferior}";
            $q = $questoes->get($numero);
            $g = $grupos[$chave] ??= (object) [
                'numero' => $numero, 'gabarito' => $q?->gabarito, 'area' => $q?->area, 'tema' => $q?->tema,
                'quinto' => $quinto, 'superior' => $ehSuperior, 'inferior' => $ehInferior,
                'respostas' => 0, 'acertos' => 0, 'em_branco' => 0, 'soma_escore_acertou' => 0, 'soma_escore_errou' => 0,
            ];
            $respostas = (int) $l->respostas;
            $acertos = (int) $l->acertos;
            $g->respostas += $respostas;
            $g->acertos += $acertos;
            $g->em_branco += (int) $l->em_branco;
            $g->soma_escore_acertou += $acertos * $escore;
            $g->soma_escore_errou += ($respostas - $acertos) * $escore;
        }

        // Só arrays: o cache não desserializa objetos (cache.serializable_classes = false).
        return array_map(fn ($g) => (array) $g, array_values($grupos));
    }

    /** Quem está no escopo de cursos nesta avaliação ('*' = todos) — entra na chave do cache. */
    private function assinaturaDoEscopo(Avaliacao $avaliacao): string
    {
        return $this->escopo === null ? '*' : $this->escopo->assinatura($avaliacao->codigo);
    }

    /**
     * "E se tirássemos os itens fracos?" — recalcula o KR-20 sem as questões
     * com D abaixo do aceitável. É a conta que a comissão de prova precisa
     * para decidir o que sai do banco de itens; null quando não há item fraco.
     *
     * @param  array<int, array<string, mixed>>  $itens
     * @param  array<int, int>  $escoresOriginais
     * @return array{removidos: array<int, int>, kr20: ?float, ganho: float}|null
     *         'ganho' é a diferença entre os dois KR-20 já arredondados a 2
     *         casas (a precisão exibida na tela), não a diferença "crua" dos
     *         valores de 4 casas — ver comentário mais abaixo.
     */
    private function simularRemocao(Avaliacao $avaliacao, string $periodo, array $itens, array $escoresOriginais): ?array
    {
        $fracos = array_values(array_filter(
            $itens,
            fn ($i) => $i['discriminacao'] === null || $i['discriminacao'] < Psicometria::D_MINIMO_ACEITAVEL,
        ));

        $mantidos = array_values(array_filter(
            $itens,
            fn ($i) => $i['discriminacao'] !== null && $i['discriminacao'] >= Psicometria::D_MINIMO_ACEITAVEL,
        ));

        if ($fracos === [] || count($mantidos) < 2) {
            return null;
        }

        $numerosFracos = array_map(fn ($i) => $i['numero'], $fracos);
        $escores = $this->escores($avaliacao, $periodo, $numerosFracos);

        if (count($escores) < self::MINIMO_RESPONDENTES) {
            return null;
        }

        $original = Psicometria::kr20(count($itens), $this->somaPQ($itens), Psicometria::variancia($escoresOriginais));
        $novo = Psicometria::kr20(count($mantidos), $this->somaPQ($mantidos), Psicometria::variancia($escores));

        // Arredondado às mesmas 2 casas que a tela exibe pros dois números
        // (KR-20 atual no card, KR-20 simulado neste aviso) — não à diferença
        // "crua" de 4 casas. Senão dava pra ler "sobe para 0,93 (+0,01)" com
        // 0,93 sendo EXATAMENTE o valor que já aparece no card acima: a
        // melhoria era real na 4ª casa decimal, mas nas 2 casas visíveis os
        // dois números são idênticos, e a diferença anunciada teria que
        // bater com o que a pessoa consegue conferir na tela.
        $ganho = $novo !== null && $original !== null ? round($novo, 2) - round($original, 2) : 0.0;

        return [
            'removidos' => $numerosFracos,
            'kr20' => $novo,
            'ganho' => round($ganho, 2),
        ];
    }

    /** Σ(p·q) sobre os itens informados — entrada do KR-20. @param array<int, array<string, mixed>> $itens */
    private function somaPQ(array $itens): float
    {
        $soma = 0.0;
        foreach ($itens as $item) {
            $p = $item['dificuldade'] / 100;
            $soma += $p * (1 - $p);
        }

        return $soma;
    }

    /**
     * Total de acertos de cada respondente, considerando só os itens que
     * entram na análise — uma linha por respondente. Sem itens excluídos vem
     * direto de `resultado_resumos`; com itens excluídos (simulação de
     * remoção) é recalculado em `respostas`, porque o resumo só guarda o
     * escore da prova inteira.
     *
     * @param  array<int, int>  $excluirNumeros
     * @return array<int, int>
     */
    private function escores(Avaliacao $avaliacao, string $periodo, array $excluirNumeros = []): array
    {
        if ($excluirNumeros === []) {
            return $this->resumos($avaliacao, $periodo)
                ->where('rr.itens_considerados', '>', 0)
                ->pluck('rr.acertos_itens')
                ->map(fn ($v) => (int) $v)
                ->all();
        }

        return CacheDeAnalise::lembrar(
            'escores-sem-itens',
            $avaliacao->codigo,
            ['periodo' => $periodo, 'escopo' => $this->assinaturaDoEscopo($avaliacao), 'excluir' => $excluirNumeros],
            fn () => $this->consultaEscores($avaliacao, $periodo, $excluirNumeros)
                ->pluck('acertos')
                ->map(fn ($v) => (int) $v)
                ->all(),
        );
    }

    /** `resultado_resumos` da avaliação no período e no escopo de cursos — uma linha por respondente. */
    private function resumos(Avaliacao $avaliacao, string $periodo): Builder
    {
        return $this->escoparResumos(DB::table('resultado_resumos as rr'), 'rr.curso')
            ->where('rr.avaliacao_codigo', $avaliacao->codigo)
            ->when($periodo !== '', fn ($q) => $q->where('rr.periodo', $periodo));
    }

    /**
     * Junta o escore de cada respondente (`sc.acertos_itens`) às linhas de `respostas`: um JOIN por índice em
     * `resultado_resumos` em vez de recalcular o escore numa subconsulta que varre `respostas` outra vez.
     */
    private function comEscore(Builder $query, Avaliacao $avaliacao): Builder
    {
        return $query->join('resultado_resumos as sc', function ($join) use ($avaliacao) {
            $join->on('sc.aluno_chave', '=', 'r.aluno_chave')
                ->on('sc.periodo', '=', 'r.periodo')
                ->where('sc.avaliacao_codigo', $avaliacao->codigo)
                ->where('sc.itens_considerados', '>', 0);
        });
    }

    /**
     * Escore por respondente recalculado em `respostas`, sem os itens excluídos (só a simulação de remoção usa).
     *
     * @param  array<int, int>  $excluirNumeros
     */
    private function consultaEscores(Avaliacao $avaliacao, string $periodo, array $excluirNumeros = []): Builder
    {
        return $this->baseItens($avaliacao, $periodo)
            ->when($excluirNumeros !== [], fn ($q) => $q->whereNotIn('r.questao_numero', $excluirNumeros))
            ->groupBy('r.aluno_chave', 'r.periodo')
            ->selectRaw('r.aluno_chave as aluno_chave, r.periodo as periodo')
            ->selectRaw('SUM(CASE WHEN '.$this->condicaoAcerto().' THEN 1 ELSE 0 END) as acertos');
    }

    /** `respostas` × `questoes` da avaliação, já sem anuladas e sem questão soft-deletada. */
    private function baseItens(Avaliacao $avaliacao, string $periodo): Builder
    {
        return $this->escopar(DB::table('respostas as r'), 'r.', $avaliacao->codigo)
            ->join('questoes as q', function ($join) use ($avaliacao) {
                $join->on('q.numero', '=', 'r.questao_numero')
                    ->where('q.avaliacao_codigo', $avaliacao->codigo)
                    ->whereNull('q.deleted_at')
                    ->whereNotNull('q.gabarito')
                    ->where('q.gabarito', '!=', '')
                    // Mais restritivo que Anulacao::excluirDistribuidas() de
                    // propósito — ver o comentário no topo da classe.
                    ->whereNull('q.anulada_modo');
            })
            ->where('r.avaliacao_codigo', $avaliacao->codigo)
            ->whereNull('r.deleted_at')
            ->when($periodo !== '', fn ($q) => $q->where('r.periodo', $periodo));
    }

    /**
     * Nunca comparar resposta com gabarito na mão: a regra é uma só e mora no
     * Anulacao (ver CLAUDE.md), senão duas telas discordam sobre o percentual
     * de acerto do mesmo aluno na mesma avaliação.
     */
    private function condicaoAcerto(): string
    {
        return Anulacao::condicaoAcertoSql('r.resposta', 'q.gabarito', 'q.anulada_modo');
    }

    /** Float para dentro de SQL cru sempre com ponto decimal, independente do locale. */
    private function numero(float $valor): string
    {
        return sprintf('%.6F', $valor);
    }
}
