<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Antes desta coluna, uma linha de nota/resposta do Avalia sem CPF (obrigatório
// em `respostas`/`resultado_metricas`) era descartada silenciosamente — só
// aparecia como "linhas_lidas bem maior que linhas_gravadas" pra quem olhasse
// com atenção. Ver App\Services\Avalia\AvaliaSyncService — descoberto na
// prática quando o CPF não veio populado do Avalia Pro pra esta IES (RA/CPF
// dependem do "Módulo Integrador" do lado do Avalia, fora do nosso controle).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avalia_sync_execucoes', function (Blueprint $table) {
            $table->unsignedInteger('linhas_sem_identificador')->nullable()->after('linhas_gravadas');
        });
    }

    public function down(): void
    {
        Schema::table('avalia_sync_execucoes', function (Blueprint $table) {
            $table->dropColumn('linhas_sem_identificador');
        });
    }
};
