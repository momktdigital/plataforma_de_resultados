<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabela `verificacoes_email` pertence à aplicação legada (database.sql) e já
// existe em produção — mesmo padrão das demais tabelas compartilhadas (ver
// create_alunos_table.php). Guarda os códigos de 2FA por e-mail emitidos no
// portal público (`App\Http\Controllers\PortalController`).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('verificacoes_email')) {
            return;
        }

        Schema::create('verificacoes_email', function (Blueprint $table) {
            $table->id();
            $table->string('cpf', 20);
            $table->string('codigo', 10);
            $table->integer('tentativas_falhas')->default(0);
            $table->integer('vezes_reenviado')->default(0);
            $table->timestamp('ultimo_reenvio')->nullable();
            $table->timestamp('expira_em');
            $table->timestamp('criado_em')->useCurrent();

            $table->index('cpf');
        });
    }

    public function down(): void
    {
        // Intencionalmente vazio — ver comentário acima.
    }
};
