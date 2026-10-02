<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Notificações do coordenador (novos resultados no curso dele, alunos que entraram em atenção, presença baixa...).
// Uma linha por (coordenador, chave): a chave identifica o acontecimento ("resultados:123"), então importar de novo a
// mesma avaliação ATUALIZA o aviso em vez de empilhar duplicados. Ver App\Services\NotificacaoCoordenadorService.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificacoes', function (Blueprint $table) {
            $table->id();
            // INT (não foreignId): admins.id é INT no schema legado.
            $table->integer('admin_id');
            $table->foreign('admin_id')->references('id')->on('admins')->cascadeOnDelete();

            $table->string('tipo', 30);
            $table->string('titulo', 200);
            $table->text('texto');
            $table->string('url', 500)->nullable();
            $table->string('chave', 120);
            $table->timestamp('lida_em')->nullable();
            $table->timestamps();

            $table->unique(['admin_id', 'chave']);
            $table->index(['admin_id', 'lida_em', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificacoes');
    }
};
