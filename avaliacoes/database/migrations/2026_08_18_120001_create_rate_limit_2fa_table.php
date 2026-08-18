<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabela `rate_limit_2fa` pertence à aplicação legada (database.sql) e já
// existe em produção — mesmo padrão das demais tabelas compartilhadas.
// Bloqueia por IP após tentativas malsucedidas de 2FA no portal público,
// independente do CPF (ver App\Services\Portal\RateLimit2faService).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rate_limit_2fa')) {
            return;
        }

        Schema::create('rate_limit_2fa', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address', 45)->unique();
            $table->integer('tentativas')->default(1);
            $table->timestamp('bloqueado_ate')->nullable();
            $table->timestamp('ultima_tentativa')->useCurrent();

            $table->index(['ip_address', 'bloqueado_ate']);
        });
    }

    public function down(): void
    {
        // Intencionalmente vazio — ver comentário acima.
    }
};
