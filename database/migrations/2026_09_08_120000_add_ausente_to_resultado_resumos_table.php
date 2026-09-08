<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Distingue "aluno ausente" (nenhuma resposta registrada) de "aluno errou
// tudo" — ver App\Services\ResumoResultadoService::recalcular(). Sem isso,
// um aluno que não fez a prova entrava no boletim/relatórios com 0 acertos,
// puxando médias pra baixo como se tivesse comparecido e errado tudo.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resultado_resumos', function (Blueprint $table) {
            $table->boolean('ausente')->default(false)->after('percentual');
        });
    }

    public function down(): void
    {
        Schema::table('resultado_resumos', function (Blueprint $table) {
            $table->dropColumn('ausente');
        });
    }
};
