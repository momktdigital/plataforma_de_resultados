<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prova_codigo')->constrained('provas', 'codigo')->cascadeOnDelete();

            // Únicos campos obrigatórios no import de questões/gabarito.
            $table->unsignedInteger('numero');
            $table->string('gabarito', 5);

            // Metadados pedagógicos — todos opcionais, nem toda avaliação os possui.
            $table->string('matriz_prova_a')->nullable();
            $table->string('matriz_prova_b')->nullable();
            $table->string('matriz_prova_c')->nullable();
            $table->string('bloom_nivel')->nullable();
            $table->string('bloom_verbo')->nullable();
            $table->string('miller_nivel')->nullable();
            $table->enum('dificuldade_pedagogica', ['facil', 'medio', 'dificil'])->nullable();
            $table->decimal('dificuldade_tri', 8, 4)->nullable();
            $table->string('dcn_a')->nullable();
            $table->string('dcn_b')->nullable();
            $table->string('portaria_inep_a')->nullable();
            $table->string('portaria_inep_b')->nullable();
            $table->string('portaria_inep_c')->nullable();
            $table->string('ppc_a')->nullable();
            $table->string('ppc_b')->nullable();
            $table->string('ppc_c')->nullable();
            $table->string('ppc_d')->nullable();

            $table->timestamps();

            $table->unique(['prova_codigo', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questoes');
    }
};
