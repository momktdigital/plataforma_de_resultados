<?php

namespace App\Services;

use App\Models\Avaliacao;
use App\Models\Resposta;
use App\Support\Anulacao;
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
     *   simulacao: ?array{removidos: array<int, int>, kr20: ?float, ganho: float}
     * }|null
     */
    public function analisar(Avaliacao $avaliacao, string $periodo = ''): ?array
    {
        $escores = $this->escores($avaliacao, $periodo);

        if (count($escores) < self::MINIMO_RESPONDENTES) {
            return null;
        }

        sort($escores);
        $k = $this->totalItens($avaliacao, $periodo);

        if ($k < 1) {
            return null;
        }

        $corteSuperior = Psicometria::percentil($escores, 1 - Psicometria::FRACAO_GRUPO_EXTREMO);
        $corteInferior = Psicometria::percentil($escores, Psicometria::FRACAO_GRUPO_EXTREMO);
        $desvioEscores = Psicometria::desvioPadrao($escores);

        $itens = $this->itens($avaliacao, $periodo, $corteSuperior, $corteInferior, $desvioEscores);

        $percentuais = array_map(fn ($acertos) => $acertos / $k * 100, $escores);

        return [
            'respondentes' => count($escores),
            'questoes' => count($itens),
            'media' => round(array_sum($percentuais) / count($percentuais), 1),
            'mediana' => round(Psicometria::mediana($percentuais), 1),
            'desvio' => round(Psicometria::desvioPadrao($percentuais), 1),
            'kr20' => Psicometria::kr20(count($itens), $this->somaPQ($itens), Psicometria::variancia($escores)),
            'itens' => $itens,
            'simulacao' => $this->simularRemocao($avaliacao, $periodo, $itens, $escores),
        ];
    }

    /**
     * % de acerto por quinto de desempenho geral, de TODAS as questões de uma
     * vez — a curva característica de cada item. Uma questão saudável sobe da
     * esquerda para a direita; uma curva plana ou invertida é o retrato de um
     * item que não mede o que a prova mede.
     *
     * Tudo numa consulta só (agrupada por questão E por quinto) de propósito:
     * a tela deixa o usuário clicar de item em item, e uma consulta por clique
     * seria uma varredura de `respostas` por curiosidade do coordenador.
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

        // Cortes dos quintos calculados no PHP: NTILE() não existe no SQLite
        // usado nos testes e só chegou ao MySQL no 8.0.
        $cortes = [];
        for ($i = 1; $i <= 4; $i++) {
            $cortes[] = Psicometria::percentil($escores, $i / 5);
        }

        $quinto = 'CASE';
        foreach ($cortes as $i => $corte) {
            $quinto .= ' WHEN sc.acertos <= '.$this->numero($corte).' THEN '.($i + 1);
        }
        $quinto .= ' ELSE 5 END';

        $linhas = $this->baseItens($avaliacao, $periodo)
            ->joinSub($this->consultaEscores($avaliacao, $periodo), 'sc', function ($join) {
                $join->on('sc.aluno_chave', '=', 'r.aluno_chave')
                    ->on('sc.periodo', '=', 'r.periodo');
            })
            ->groupByRaw('r.questao_numero, '.$quinto)
            ->selectRaw('r.questao_numero as numero')
            ->selectRaw($quinto.' as quinto')
            ->selectRaw('COUNT(*) as respondentes')
            ->selectRaw('SUM(CASE WHEN '.$this->condicaoAcerto().' THEN 1 ELSE 0 END) as acertos')
            ->get();

        $curvas = [];
        foreach ($linhas as $linha) {
            $curvas[(int) $linha->numero][(int) $linha->quinto] = (int) $linha->respondentes > 0
                ? round((int) $linha->acertos / (int) $linha->respondentes * 100, 1)
                : 0.0;
        }

        foreach ($curvas as &$curva) {
            ksort($curva);
        }

        return $curvas;
    }

    /**
     * Uma linha por item com as duas medidas de discriminação.
     *
     * @return array<int, array<string, mixed>>
     */
    private function itens(Avaliacao $avaliacao, string $periodo, float $corteSuperior, float $corteInferior, float $desvioEscores): array
    {
        $acerto = $this->condicaoAcerto();
        $superior = 'sc.acertos >= '.$this->numero($corteSuperior);
        $inferior = 'sc.acertos <= '.$this->numero($corteInferior);

        $linhas = $this->baseItens($avaliacao, $periodo)
            ->joinSub($this->consultaEscores($avaliacao, $periodo), 'sc', function ($join) {
                $join->on('sc.aluno_chave', '=', 'r.aluno_chave')
                    ->on('sc.periodo', '=', 'r.periodo');
            })
            ->groupBy('r.questao_numero', 'q.gabarito', 'q.area', 'q.tema')
            ->selectRaw('r.questao_numero as numero, q.gabarito as gabarito, q.area as area, q.tema as tema')
            ->selectRaw('COUNT(*) as respostas')
            ->selectRaw("SUM(CASE WHEN {$acerto} THEN 1 ELSE 0 END) as acertos")
            ->selectRaw('SUM(CASE WHEN '.Resposta::semRespostaSql('r.resposta').' THEN 1 ELSE 0 END) as em_branco')
            ->selectRaw("SUM(CASE WHEN {$superior} THEN 1 ELSE 0 END) as n_superior")
            ->selectRaw("SUM(CASE WHEN {$superior} AND {$acerto} THEN 1 ELSE 0 END) as acertos_superior")
            ->selectRaw("SUM(CASE WHEN {$inferior} THEN 1 ELSE 0 END) as n_inferior")
            ->selectRaw("SUM(CASE WHEN {$inferior} AND {$acerto} THEN 1 ELSE 0 END) as acertos_inferior")
            ->selectRaw("SUM(CASE WHEN {$acerto} THEN sc.acertos ELSE 0 END) as soma_escore_acertou")
            ->selectRaw("SUM(CASE WHEN NOT ({$acerto}) THEN sc.acertos ELSE 0 END) as soma_escore_errou")
            ->orderBy('r.questao_numero')
            ->get();

        $itens = [];
        foreach ($linhas as $l) {
            $respostas = (int) $l->respostas;
            $acertos = (int) $l->acertos;
            $erros = $respostas - $acertos;

            if ($respostas === 0) {
                continue;
            }

            $dificuldade = $acertos / $respostas;
            $d = Psicometria::discriminacao(
                (int) $l->acertos_superior,
                (int) $l->n_superior,
                (int) $l->acertos_inferior,
                (int) $l->n_inferior,
            );
            $faixa = Psicometria::classificar($d);

            $itens[] = [
                'numero' => (int) $l->numero,
                'area' => $l->area,
                'tema' => $l->tema,
                'gabarito' => (string) $l->gabarito,
                'respostas' => $respostas,
                'acertos' => $acertos,
                'emBranco' => (int) $l->em_branco,
                'dificuldade' => round($dificuldade * 100, 1),
                'discriminacao' => $d,
                'pontoBisserial' => Psicometria::pontoBisserial(
                    $acertos > 0 ? (float) $l->soma_escore_acertou / $acertos : null,
                    $erros > 0 ? (float) $l->soma_escore_errou / $erros : null,
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
     * "E se tirássemos os itens fracos?" — recalcula o KR-20 sem as questões
     * com D abaixo do aceitável. É a conta que a comissão de prova precisa
     * para decidir o que sai do banco de itens; null quando não há item fraco.
     *
     * @param  array<int, array<string, mixed>>  $itens
     * @param  array<int, int>  $escoresOriginais
     * @return array{removidos: array<int, int>, kr20: ?float, ganho: float}|null
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

        return [
            'removidos' => $numerosFracos,
            'kr20' => $novo,
            'ganho' => $novo !== null && $original !== null ? round($novo - $original, 4) : 0.0,
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
     * entram na análise — uma linha por respondente.
     *
     * @param  array<int, int>  $excluirNumeros
     * @return array<int, int>
     */
    private function escores(Avaliacao $avaliacao, string $periodo, array $excluirNumeros = []): array
    {
        return $this->consultaEscores($avaliacao, $periodo, $excluirNumeros)
            ->pluck('acertos')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** @param array<int, int> $excluirNumeros */
    private function consultaEscores(Avaliacao $avaliacao, string $periodo, array $excluirNumeros = []): Builder
    {
        return $this->baseItens($avaliacao, $periodo)
            ->when($excluirNumeros !== [], fn ($q) => $q->whereNotIn('r.questao_numero', $excluirNumeros))
            ->groupBy('r.aluno_chave', 'r.periodo')
            ->selectRaw('r.aluno_chave as aluno_chave, r.periodo as periodo')
            ->selectRaw('SUM(CASE WHEN '.$this->condicaoAcerto().' THEN 1 ELSE 0 END) as acertos');
    }

    private function totalItens(Avaliacao $avaliacao, string $periodo): int
    {
        return (int) $this->baseItens($avaliacao, $periodo)->distinct()->count('r.questao_numero');
    }

    /** `respostas` × `questoes` da avaliação, já sem anuladas e sem questão soft-deletada. */
    private function baseItens(Avaliacao $avaliacao, string $periodo): Builder
    {
        return DB::table('respostas as r')
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
