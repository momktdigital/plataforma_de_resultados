<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Forma de ingresso do aluno (Vestibular, ENEM, PROUNI, Transferência...), vinda da planilha de alunos/matrículas. Serve à
// análise demográfica do Dashboard: comparar se a forma de ingresso influencia o desempenho. Texto livre (cada instituição
// tem as suas formas); a tabela `alunos` é compartilhada com o sistema legado, então a coluna é nullable e só acrescentada.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('alunos', 'forma_ingresso')) {
            Schema::table('alunos', function (Blueprint $table) {
                $table->string('forma_ingresso', 100)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('alunos', 'forma_ingresso')) {
            Schema::table('alunos', function (Blueprint $table) {
                $table->dropColumn('forma_ingresso');
            });
        }
    }
};
