<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Acompanhamento de alunos em risco pelo coordenador: um REGISTRO por contato/decisão (contatado, em acompanhamento,
// resolvido), com observação e data. O estado atual do aluno é o último registro; o histórico fica todo.
//
// `curso` guarda o curso em que o coordenador fez o registro: o registro só é visível para quem coordena aquele curso
// (a observação é dado sensível; outro curso — ou o reitor fora da visão do curso — não a enxerga).
//
// aluno_id/admin_id são INT (não foreignId): `alunos.id` e `admins.id` são INT no schema legado e o MySQL exige o mesmo
// tipo dos dois lados de uma FK (o SQLite não pega esse erro).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acompanhamentos', function (Blueprint $table) {
            $table->id();
            $table->integer('aluno_id');
            $table->foreign('aluno_id')->references('id')->on('alunos')->cascadeOnDelete();
            $table->integer('admin_id')->nullable();
            $table->foreign('admin_id')->references('id')->on('admins')->nullOnDelete();
            $table->string('curso', 200);
            $table->string('status', 30);
            $table->text('observacao')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['aluno_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acompanhamentos');
    }
};
