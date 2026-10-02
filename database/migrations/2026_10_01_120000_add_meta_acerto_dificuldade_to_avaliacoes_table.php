<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Meta de % de acerto por nível de dificuldade pedagógica, definida na
     * configuração da avaliação (ex.: {"facil": 80, "medio": 60}). O BI compara
     * o observado com essa meta e mostra o desvio. Nível sem meta = null/ausente.
     */
    public function up(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->json('meta_acerto_dificuldade')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->dropColumn('meta_acerto_dificuldade');
        });
    }
};
