<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Nome `configuracoes_sistema`, não `configuracoes` — a aplicação legada já
// tem uma tabela `configuracoes` própria (CAPTCHA/SMTP/aparência) no mesmo
// banco compartilhado, com finalidade totalmente diferente.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracoes_sistema', function (Blueprint $table) {
            $table->id();
            $table->string('chave')->unique();
            $table->text('valor')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracoes_sistema');
    }
};
