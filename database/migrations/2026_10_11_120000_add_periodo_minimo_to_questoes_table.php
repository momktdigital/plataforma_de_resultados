<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Meta de resultado por período: `periodo_minimo` é o período do curso (1 = 1º período...) a partir do qual se espera
// que o aluno acerte a questão. Quem ainda não chegou lá pode acertar sem problema, mas não é cobrado. NULL = sem meta
// por período (a questão vale para todos). É um dado de classificação da questão — não altera acerto, nota nem
// resultado_resumos. Não confundir com `questao_matrizes.periodo` (período da disciplina na matriz curricular, texto livre).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questoes', function (Blueprint $table) {
            $table->unsignedTinyInteger('periodo_minimo')->nullable()->after('dificuldade_tri');
        });
    }

    public function down(): void
    {
        Schema::table('questoes', function (Blueprint $table) {
            $table->dropColumn('periodo_minimo');
        });
    }
};
