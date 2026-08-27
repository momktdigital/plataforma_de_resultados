<?php

namespace App\Support;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * `respostas`/`resultado_resumos` vinculam ao aluno por `aluno_id` (quando o
 * import conseguiu casar a linha) OU por `ra` como fallback — nunca só um dos
 * dois, porque `aluno_id` pode ficar nulo mesmo quando o RA existe em
 * `alunos`. Comparar `a.ra = <tabela>.ra` diretamente quebra em produção
 * (MySQL) quando as duas colunas têm collation diferente — `alunos` é uma
 * tabela do sistema legado, criada fora do controle das migrations daqui, e
 * pode ter uma collation diferente da usada pelas migrations novas
 * ("Illegal mix of collations" — SQLSTATE HY000/1267). O cast pra BINARY dos
 * dois lados força uma comparação byte a byte, contornando isso
 * independente de qual collation cada tabela tiver — só no MySQL, porque
 * `BINARY coluna` não é uma expressão válida no SQLite (usado nos testes) e
 * o SQLite não tem esse problema de collation pra começo de conversa.
 */
trait JuntaAlunoPorIdOuRa
{
    private function juntaAlunoPorIdOuRa(JoinClause $join, string $aliasOutraTabela): void
    {
        $join->on('a.id', '=', "{$aliasOutraTabela}.aluno_id");

        if (DB::connection()->getDriverName() === 'mysql') {
            $join->orOn(DB::raw('BINARY a.ra'), '=', DB::raw("BINARY {$aliasOutraTabela}.ra"));
        } else {
            $join->orOn('a.ra', '=', "{$aliasOutraTabela}.ra");
        }
    }
}
