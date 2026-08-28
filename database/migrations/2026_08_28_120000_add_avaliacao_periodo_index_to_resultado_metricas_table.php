<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A chave única existente (avaliacao_codigo, aluno_chave, periodo,
// nome_metrica) só é útil por inteiro, ou pelo prefixo avaliacao_codigo
// sozinho — RelatorioAdminService::correlacaoMetricas() filtra por
// avaliacao_codigo + periodo SEM aluno_chave (métrica de todos os alunos
// daquele período), o que pula a segunda coluna da chave única e não
// aproveita periodo como parte do índice. Um índice dedicado cobre
// exatamente esse filtro.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resultado_metricas', function (Blueprint $table) {
            $table->index(['avaliacao_codigo', 'periodo'], 'idx_metricas_avaliacao_periodo');
        });
    }

    public function down(): void
    {
        Schema::table('resultado_metricas', function (Blueprint $table) {
            $table->dropIndex('idx_metricas_avaliacao_periodo');
        });
    }
};
