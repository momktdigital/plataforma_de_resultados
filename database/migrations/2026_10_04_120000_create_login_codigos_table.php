<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Código de acesso por e-mail do login de COORDENADOR (sem senha). Diferente do
// 2FA do aluno (`verificacoes_email`, código em texto puro, schema legado), este
// guarda só o HASH do código e fica atrelado ao admin, não ao CPF.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_codigos', function (Blueprint $table) {
            $table->id();
            // INT (não foreignId): admins.id é INT no schema legado.
            $table->integer('admin_id');
            $table->foreign('admin_id')->references('id')->on('admins')->cascadeOnDelete();

            $table->string('codigo_hash', 64);
            $table->dateTime('expira_em');
            $table->unsignedTinyInteger('tentativas_falhas')->default(0);
            $table->unsignedTinyInteger('vezes_reenviado')->default(0);
            $table->dateTime('ultimo_reenvio')->nullable();
            $table->timestamp('criado_em')->useCurrent();

            $table->index('admin_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_codigos');
    }
};
