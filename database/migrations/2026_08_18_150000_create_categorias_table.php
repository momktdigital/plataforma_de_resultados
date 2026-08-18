<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Categorias de Prova em árvore (categoria → subcategorias), usadas para
// agrupar o boletim do aluno no portal público (ex.: "Simulados" →
// "1º ao 4º período" → "Prova X").
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->foreignId('categoria_pai_id')->nullable()->constrained('categorias')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorias');
    }
};
