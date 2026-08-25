<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Colunas de dados pessoais/matrícula que a planilha de matrícula (Cor/Raça,
// Religião, Matriz, Sexo, Estado Civil, Cidade, UF, Celular) passa a
// alimentar, além de ficarem editáveis manualmente na tela do aluno. Segue o
// mesmo padrão defensivo das migrations anteriores de `alunos` (tabela
// legada): só adiciona cada coluna se ainda não existir.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alunos', function (Blueprint $table) {
            if (! Schema::hasColumn('alunos', 'matriz')) {
                $table->string('matriz')->nullable()->after('curso');
            }
            if (! Schema::hasColumn('alunos', 'cor_raca')) {
                $table->string('cor_raca', 50)->nullable()->after('turma');
            }
            if (! Schema::hasColumn('alunos', 'religiao')) {
                $table->string('religiao', 50)->nullable()->after('cor_raca');
            }
            if (! Schema::hasColumn('alunos', 'sexo')) {
                $table->string('sexo', 20)->nullable()->after('religiao');
            }
            if (! Schema::hasColumn('alunos', 'estado_civil')) {
                $table->string('estado_civil', 30)->nullable()->after('sexo');
            }
            if (! Schema::hasColumn('alunos', 'cidade')) {
                $table->string('cidade', 100)->nullable()->after('estado_civil');
            }
            if (! Schema::hasColumn('alunos', 'uf')) {
                $table->string('uf', 2)->nullable()->after('cidade');
            }
            if (! Schema::hasColumn('alunos', 'celular')) {
                $table->string('celular', 20)->nullable()->after('uf');
            }
        });
    }

    public function down(): void
    {
        Schema::table('alunos', function (Blueprint $table) {
            foreach (['matriz', 'cor_raca', 'religiao', 'sexo', 'estado_civil', 'cidade', 'uf', 'celular'] as $coluna) {
                if (Schema::hasColumn('alunos', $coluna)) {
                    $table->dropColumn($coluna);
                }
            }
        });
    }
};
