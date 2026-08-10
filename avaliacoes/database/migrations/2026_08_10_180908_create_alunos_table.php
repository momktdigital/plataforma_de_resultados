<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A tabela `alunos` pertence à aplicação legada (database.sql) e já existe em
// produção — esta migration só a cria quando ausente (ambientes novos/testes),
// nunca altera uma tabela existente. `down()` é proposital: nunca apagamos o
// cadastro de alunos a partir daqui.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('alunos')) {
            return;
        }

        Schema::create('alunos', function (Blueprint $table) {
            $table->id();
            $table->string('ra', 50)->unique();
            $table->string('cpf', 20)->unique();
            $table->date('data_nascimento');
            $table->string('nome')->nullable();
            $table->string('curso')->nullable();
            $table->string('campus')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Intencionalmente vazio — ver comentário acima.
    }
};
