<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Métricas agregadas por aluno+prova+período que não são resposta de uma
// questão específica (ex.: "Nota de Redação", "Total", "Cirurgia - Total de
// acertos"). Equivalente ao antigo JSON `resultados.notas_finais`, só que
// como linhas — mesma flexibilidade (nome livre), sem inventar colunas fixas.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resultado_metricas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prova_codigo')->constrained('provas', 'codigo')->cascadeOnDelete();

            // `integer` (não `foreignId`/BIGINT UNSIGNED) de propósito: precisa
            // bater com o tipo de `alunos.id` (INT) para o MySQL aceitar a FK.
            $table->integer('aluno_id')->nullable();
            $table->foreign('aluno_id')->references('id')->on('alunos')->nullOnDelete();

            $table->string('ra', 50)->nullable();
            $table->string('cpf', 20)->nullable();
            $table->string('aluno_chave', 50)->storedAs('COALESCE(cpf, ra)');
            $table->string('periodo', 100)->default('');

            $table->string('nome_metrica');
            $table->string('valor')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['prova_codigo', 'aluno_chave', 'periodo', 'nome_metrica'],
                'uk_metrica_aluno_periodo_nome'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resultado_metricas');
    }
};
