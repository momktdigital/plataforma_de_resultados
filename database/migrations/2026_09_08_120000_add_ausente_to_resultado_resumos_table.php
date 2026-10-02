<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Distingue "aluno ausente" (nenhuma resposta registrada) de "aluno errou
// tudo" — ver App\Services\ResumoResultadoService::recalcular(). Sem isso,
// um aluno que não fez a prova entrava no boletim/relatórios com 0 acertos,
// puxando médias pra baixo como se tivesse comparecido e errado tudo.
//
// MESMO nome de arquivo e mesma coluna da migration homônima da branch
// claude/avalia-data-integration (integração Avalia Pro), de propósito: assim,
// ao juntar as duas, o Laravel enxerga UMA migration só — e, por isso, ela tem
// a guarda de "coluna já existe" (bancos que já rodaram a versão da outra branch).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('resultado_resumos', 'ausente')) {
            return;
        }

        Schema::table('resultado_resumos', function (Blueprint $table) {
            $table->boolean('ausente')->default(false)->after('percentual');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('resultado_resumos', 'ausente')) {
            return;
        }

        Schema::table('resultado_resumos', function (Blueprint $table) {
            $table->dropColumn('ausente');
        });
    }
};
