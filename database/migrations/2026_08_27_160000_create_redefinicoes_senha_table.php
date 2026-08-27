<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fluxo de "esqueci minha senha" pro login de admin — sem isso, um admin
// sozinho (sem outra conta de admin pra pedir reset) que esquece a senha
// não tem caminho de recuperação nenhum.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redefinicoes_senha', function (Blueprint $table) {
            $table->id();

            // INT (não BIGINT UNSIGNED) pra bater com `admins.id`.
            // cascadeOnDelete: sem a conta, o pedido de redefinição não
            // significa mais nada.
            $table->integer('admin_id');
            $table->foreign('admin_id')->references('id')->on('admins')->cascadeOnDelete();

            // Nunca o token em claro — só o hash, igual senha (se o banco
            // vazar, ninguém consegue montar um link de redefinição válido
            // a partir daqui).
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expira_em');
            $table->timestamp('criado_em')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redefinicoes_senha');
    }
};
