<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Anulação de questão: 'dar_ponto' credita a questão como acerto pra todo
// mundo mas ela continua contando no total (ex.: prova de 100 questões
// continua /100); 'distribuir_pontuacao' remove a questão do cálculo
// inteiro — nem soma acerto nem entra no total (a prova de 100 vira /99,
// "distribuindo" o peso dela pelas demais). null = questão não anulada.
// Ver App\Support\Anulacao pelo uso consistente dessas duas regras em toda
// consulta que soma acertos/total a partir de respostas x questoes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questoes', function (Blueprint $table) {
            $table->string('anulada_modo')->nullable()->after('gabarito');
        });
    }

    public function down(): void
    {
        Schema::table('questoes', function (Blueprint $table) {
            $table->dropColumn('anulada_modo');
        });
    }
};
