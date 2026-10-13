<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// O plano de ação deixou de ter aprovação: o coordenador publica e o colaborador só acompanha. Os planos que estavam no meio do
// caminho entram em execução (os que esperavam análise, voltaram para ajustes ou já estavam aprovados) e os recusados viram
// cancelados. Os eventos do histórico antigo (enviado, aprovado, ajustes...) ficam como estão.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('planos_acao')->whereIn('status', ['em_analise', 'ajustes', 'aprovado'])->update(['status' => 'em_execucao']);
        DB::table('planos_acao')->where('status', 'recusado')->update(['status' => 'cancelado']);
    }

    public function down(): void
    {
        // Sem volta: não há como saber quais em_execucao eram "aprovado" ou "em_analise".
    }
};
