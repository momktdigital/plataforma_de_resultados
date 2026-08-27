<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Nullable de propósito: contas já existentes na tabela legada `admins` não
// têm e-mail cadastrado. Habilita o fluxo de "esqueci minha senha" (só
// funciona pra quem tiver e-mail preenchido) sem forçar migração de dados.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->string('email', 255)->nullable()->unique()->after('username');
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->dropColumn('email');
        });
    }
};
