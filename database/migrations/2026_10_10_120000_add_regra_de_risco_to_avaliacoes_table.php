<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Regra de "estudante em risco" por avaliação (ver App\Support\RegraDeRisco). O padrão da instituição fica em
// `configuracoes_sistema` (risco_acerto, risco_faltas, risco_operador); estas colunas só SOBREPÕEM o padrão para uma prova:
//
//   risco_acerto       NULL = usa o padrão · 0 = esta prova não entra no critério de acerto · >0 = limite próprio (% de acerto)
//   risco_ignora_falta 1 = faltar a esta prova NÃO conta como falta no critério de faltas (ex.: prova opcional)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->decimal('risco_acerto', 5, 2)->nullable()->after('status');
            $table->boolean('risco_ignora_falta')->default(false)->after('risco_acerto');
        });
    }

    public function down(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->dropColumn(['risco_acerto', 'risco_ignora_falta']);
        });
    }
};
