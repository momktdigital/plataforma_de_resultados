<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Veredito pré-calculado, quando a fonte já manda ele pronto por resposta em
// vez de um gabarito comparável — ver App\Support\Anulacao e o motivo desta
// coluna existir na docblock da classe.
//
// Caso real que motivou isto: o Avalia embaralha a ordem das alternativas
// por aluno (confirmado com dado real — a MESMA questão teve as 5 letras
// diferentes marcadas como "correta" por alunos diferentes), então não existe
// um gabarito de letra único e comparável entre respondentes pra essa
// questão. `question_answer` (a letra) só é comparável dentro da prova de UM
// mesmo aluno — nunca entre alunos. `correta` é null pra todo import manual
// (comportamento inalterado: cai no resposta=gabarito de sempre) e só é
// preenchido pra respostas de origem cujo provedor já manda o veredito
// pronto (hoje, avalia_pro via answer_status).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('respostas', function (Blueprint $table) {
            $table->boolean('correta')->nullable()->after('resposta');
        });
    }

    public function down(): void
    {
        Schema::table('respostas', function (Blueprint $table) {
            $table->dropColumn('correta');
        });
    }
};
