<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Uma questão pode estar associada a mais de um período/disciplina/código de
// matriz curricular ao mesmo tempo — por isso é uma tabela filha (1:N) em vez
// de colunas fixas em `questoes`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questao_matrizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('questao_id')->constrained('questoes')->cascadeOnDelete();
            $table->unsignedInteger('periodo')->nullable();
            $table->string('disciplina')->nullable();
            $table->string('codigo')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questao_matrizes');
    }
};
