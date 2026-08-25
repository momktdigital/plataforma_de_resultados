<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Área do conhecimento, tema e habilidade avaliada por questão — um valor só
// por questão (igual bloom_nivel/miller_nivel/dificuldade_pedagogica), por
// isso são colunas, não uma tabela filha como questao_referencias (que é
// para atributos com 0..N valores por questão).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questoes', function (Blueprint $table) {
            $table->string('area')->nullable()->after('gabarito');
            $table->string('tema')->nullable()->after('area');
            $table->string('habilidade')->nullable()->after('tema');
        });
    }

    public function down(): void
    {
        Schema::table('questoes', function (Blueprint $table) {
            $table->dropColumn(['area', 'tema', 'habilidade']);
        });
    }
};
