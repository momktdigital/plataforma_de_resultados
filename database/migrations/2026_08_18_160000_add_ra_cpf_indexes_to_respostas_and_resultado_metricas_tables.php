<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// O boletim do aluno busca respostas/métricas em TODAS as provas de uma vez,
// filtrando por `ra` OU `cpf` (não sabe de antemão qual das duas está
// preenchida em cada linha, nem em qual prova/período está). Sem um índice
// em cada uma dessas colunas, essa busca é um full table scan — inofensivo
// hoje, mas essas tabelas crescem uma linha por aluno×prova×questão/métrica,
// então é exatamente aqui que "milhões de resultados" travariam a consulta
// mais usada do sistema.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('respostas', function (Blueprint $table) {
            $table->index('ra');
            $table->index('cpf');
        });

        Schema::table('resultado_metricas', function (Blueprint $table) {
            $table->index('ra');
            $table->index('cpf');
        });
    }

    public function down(): void
    {
        Schema::table('respostas', function (Blueprint $table) {
            $table->dropIndex(['ra']);
            $table->dropIndex(['cpf']);
        });

        Schema::table('resultado_metricas', function (Blueprint $table) {
            $table->dropIndex(['ra']);
            $table->dropIndex(['cpf']);
        });
    }
};
