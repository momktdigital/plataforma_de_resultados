<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabela referenciada pelo protótipo legado (admin/alunos_di_process.php)
// mas que nunca chegou a ter uma migration — ver comentário em
// 2026_08_17_190000_add_matricula_columns_to_alunos_table.php. Registra os
// nomes de curso vistos na importação de matrícula, para telas de filtro.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cursos')) {
            return;
        }

        Schema::create('cursos', function (Blueprint $table) {
            $table->id();
            $table->string('nome')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cursos');
    }
};
