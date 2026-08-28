<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Resolve coluna+direção de ordenação a partir de `?sort=`/`?direction=` na
 * query string, restrito a uma lista de colunas permitidas — nunca interpola
 * o nome de coluna vindo da requisição direto num orderBy() (evitaria expor
 * uma coluna que não deveria, ou um erro SQL, pra quem editar a URL na mão).
 */
final class Ordenacao
{
    /**
     * @param  array<int, string>  $colunasPermitidas
     * @param  'asc'|'desc'  $direcaoPadrao  Direção quando nem `sort` nem `direction` vêm na query —
     *                                       deixa a lista continuar com sua ordem natural de hoje (ex.: avaliação mais nova primeiro).
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    public static function resolver(Request $request, array $colunasPermitidas, string $colunaPadrao, string $direcaoPadrao = 'asc'): array
    {
        $coluna = $request->query('sort');

        // Sem `sort` na query (primeira visita à tela, sem clique ainda): usa
        // a ordem natural de hoje da lista, ignorando `direction` — o link do
        // th-ordenavel sempre manda os dois juntos, então só chega aqui um
        // `direction` sem `sort` correspondente se alguém editar a URL à mão.
        if (! is_string($coluna) || ! in_array($coluna, $colunasPermitidas, true)) {
            return [$colunaPadrao, $direcaoPadrao];
        }

        $direcao = $request->query('direction') === 'desc' ? 'desc' : 'asc';

        return [$coluna, $direcao];
    }
}
