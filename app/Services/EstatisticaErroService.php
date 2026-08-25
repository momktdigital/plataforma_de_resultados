<?php

namespace App\Services;

use App\Models\Avaliacao;
use App\Models\Resposta;

/**
 * Taxa de erro por questão de uma Avaliação — equivalente ao painel "Questões
 * críticas" de admin/avaliacao_editar.php, recalculado aqui a partir de
 * `respostas` (formato longo) em vez do antigo JSON por aluno.
 *
 * A contagem é feita inteira em SQL (JOIN + SUM(CASE...) agrupado por
 * questão) em vez de trazer cada resposta pra PHP: a versão anterior usava
 * ->chunk() sobre a tabela toda, que pagina por LIMIT/OFFSET — cada chunk
 * fica mais lento que o anterior porque o banco tem que pular um offset
 * cada vez maior, um comportamento ~O(n²) que travava (timeout de 30s) numa
 * avaliação com 167 mil respostas.
 */
class EstatisticaErroService
{
    private const MAXIMO_EXIBIDO = 10;

    /** @return array<int, array{numero: int, acertos: int, erros: int, em_branco: int, taxa_erro: float}> */
    public function calcular(Avaliacao $avaliacao): array
    {
        $linhas = Resposta::query()
            ->join('questoes', function ($join) use ($avaliacao) {
                $join->on('questoes.numero', '=', 'respostas.questao_numero')
                    ->where('questoes.avaliacao_codigo', $avaliacao->codigo)
                    ->whereNull('questoes.deleted_at')
                    ->whereNotNull('questoes.gabarito')
                    ->where('questoes.gabarito', '!=', '');
            })
            ->where('respostas.avaliacao_codigo', $avaliacao->codigo)
            ->selectRaw('respostas.questao_numero as numero')
            ->selectRaw("SUM(CASE WHEN respostas.resposta IS NULL OR respostas.resposta = '' THEN 1 ELSE 0 END) as em_branco")
            ->selectRaw('SUM(CASE WHEN respostas.resposta = questoes.gabarito THEN 1 ELSE 0 END) as acertos')
            ->selectRaw("SUM(CASE WHEN respostas.resposta IS NOT NULL AND respostas.resposta != '' AND respostas.resposta != questoes.gabarito THEN 1 ELSE 0 END) as erros")
            ->groupBy('respostas.questao_numero')
            ->get();

        $stats = [];
        foreach ($linhas as $linha) {
            $acertos = (int) $linha->acertos;
            $erros = (int) $linha->erros;
            $totalRespondido = $acertos + $erros;

            $stats[] = [
                'numero' => (int) $linha->numero,
                'acertos' => $acertos,
                'erros' => $erros,
                'em_branco' => (int) $linha->em_branco,
                'taxa_erro' => $totalRespondido > 0 ? round($erros / $totalRespondido * 100, 1) : 0.0,
            ];
        }

        $stats = array_values(array_filter($stats, fn ($s) => $s['taxa_erro'] > 0));
        usort($stats, fn ($a, $b) => $b['taxa_erro'] <=> $a['taxa_erro']);

        return array_slice($stats, 0, self::MAXIMO_EXIBIDO);
    }
}
