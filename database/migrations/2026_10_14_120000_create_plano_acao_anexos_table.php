<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Evidências anexadas ao acompanhamento do plano de ação: um LINK (pasta compartilhada, formulário, vídeo...) ou um ARQUIVO
// (lista de presença, relatório, print). Cada anexo pertence ao evento do histórico em que foi enviado (nota de andamento,
// conclusão de ação, encerramento) e, quando houver, à ação. O arquivo fica em disco PRIVADO (storage/app/private/planos/...),
// nunca em public/: só abre por rota autenticada, para quem enxerga o plano.
//
// admin_id é INT (não foreignId): `admins.id` é INT no schema legado e o MySQL exige o mesmo tipo dos dois lados de uma FK.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plano_acao_anexos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plano_id')->constrained('planos_acao')->cascadeOnDelete();
            $table->unsignedBigInteger('acao_id')->nullable();
            $table->unsignedBigInteger('evento_id')->nullable();
            $table->integer('admin_id')->nullable();
            $table->foreign('admin_id')->references('id')->on('admins')->nullOnDelete();
            $table->string('tipo', 10); // link | arquivo
            $table->string('titulo', 200);
            $table->text('url')->nullable();
            $table->string('caminho', 300)->nullable();
            $table->string('nome_original', 255)->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('tamanho')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['plano_id', 'id']);
            $table->index('evento_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plano_acao_anexos');
    }
};
