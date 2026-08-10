<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A tabela `admins` pertence à aplicação legada (database.sql) e já existe em
// produção — esta migration só a cria quando ausente (ambientes novos/testes),
// nunca altera uma tabela existente. `down()` é proposital: nunca apagamos os
// administradores do sistema legado a partir daqui.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admins')) {
            return;
        }

        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('username', 50)->unique();
            $table->string('password_hash', 255);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        // Intencionalmente vazio — ver comentário acima.
    }
};
