<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cronograma de atividades (checklist de auditoria ROC/ROD): o colaborador cadastra as atividades e indica a quais
// cursos se aplicam; isso monta o calendário de cada coordenador.
//
// - cronograma_itens: uma atividade numa data (rotina ROD/ROC/Auditoria, projeto, "o que vou conferir").
// - cronograma_item_cursos: os cursos a que a atividade se aplica, cada um com a sua situação. Curso fora da tabela =
//   "não se aplica" (a célula de "Não se aplica" da planilha). Curso guardado pelo NOME, como em admin_cursos.
// - cronograma_pendencias: o registro de pendências de uma atividade para um curso. Fica como histórico: o coordenador
//   só lê; o colaborador acrescenta e atualiza a situação (resolvida_em guarda quando foi resolvida).
//
// criado_por/registrado_por são INT (não foreignId): `admins.id` é INT no schema legado e o MySQL exige o mesmo tipo
// dos dois lados de uma FK (o SQLite não pega esse erro).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cronograma_itens', function (Blueprint $table) {
            $table->id();
            $table->date('data');
            $table->string('rotina', 20);
            $table->string('projeto', 120);
            $table->string('descricao', 500);
            $table->integer('criado_por')->nullable();
            $table->foreign('criado_por')->references('id')->on('admins')->nullOnDelete();
            $table->timestamps();

            $table->index('data');
        });

        Schema::create('cronograma_item_cursos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('cronograma_itens')->cascadeOnDelete();
            $table->string('curso', 200);
            $table->string('status', 30)->default('aguardando');

            $table->unique(['item_id', 'curso']);
            $table->index('curso');
        });

        Schema::create('cronograma_pendencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('cronograma_itens')->cascadeOnDelete();
            $table->string('curso', 200);
            $table->date('data');
            $table->text('pendencia');
            $table->text('encaminhamento')->nullable();
            $table->date('prazo')->nullable();
            $table->string('status', 30)->default('pendente');
            $table->string('responsavel', 120)->nullable();
            $table->integer('registrado_por')->nullable();
            $table->foreign('registrado_por')->references('id')->on('admins')->nullOnDelete();
            $table->timestamp('resolvida_em')->nullable();
            $table->timestamps();

            $table->index(['item_id', 'curso']);
            $table->index('curso');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cronograma_pendencias');
        Schema::dropIfExists('cronograma_item_cursos');
        Schema::dropIfExists('cronograma_itens');
    }
};
