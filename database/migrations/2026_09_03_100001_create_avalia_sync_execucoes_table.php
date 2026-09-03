<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Histórico das sincronizações com o Avalia (+A Data / Redshift) — uma linha
// por execução de `avalia:sincronizar`, disparada pelo agendador (a cada 12h,
// ver bootstrap/app.php) ou manualmente pelo botão "Forçar sincronização" em
// admin/sistema/integracao-avalia. Alimenta a tabela de logs daquela tela;
// o status "atual" (em andamento ou não) fica em ConfiguracaoSistema, igual
// ao padrão já usado para backup (ver App\Services\Backup\BackupStatusTracker).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avalia_sync_execucoes', function (Blueprint $table) {
            $table->id();

            $table->string('produto', 20); // 'avalia_pro' ou 'avalia_online'
            $table->string('status', 20); // 'processando' | 'sucesso' | 'erro'
            $table->string('disparado_por', 20); // 'agendado' | 'manual'

            // INT (não BIGINT UNSIGNED) e nullOnDelete de propósito: precisa
            // bater com o tipo de `admins.id` (INT) pro MySQL aceitar a FK —
            // ver convenção equivalente em `atividades.admin_id`.
            $table->integer('admin_id')->nullable();
            $table->foreign('admin_id')->references('id')->on('admins')->nullOnDelete();

            $table->timestamp('iniciado_em');
            $table->timestamp('concluido_em')->nullable();

            $table->unsignedInteger('linhas_lidas')->nullable();
            $table->unsignedInteger('linhas_gravadas')->nullable();
            $table->text('mensagem_erro')->nullable();

            $table->timestamps();

            $table->index(['produto', 'iniciado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avalia_sync_execucoes');
    }
};
