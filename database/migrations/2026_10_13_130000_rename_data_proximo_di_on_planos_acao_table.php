<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// O sistema é de avaliações (o DI é só uma delas): a data do "próximo DI" do plano de ação passa a ser a data da
// PRÓXIMA AVALIAÇÃO. Migration à parte (e não edição da que cria a tabela) porque a anterior já foi executada em bancos reais.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('planos_acao', 'data_proximo_di') && ! Schema::hasColumn('planos_acao', 'data_proxima_avaliacao')) {
            Schema::table('planos_acao', function (Blueprint $table) {
                $table->renameColumn('data_proximo_di', 'data_proxima_avaliacao');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('planos_acao', 'data_proxima_avaliacao') && ! Schema::hasColumn('planos_acao', 'data_proximo_di')) {
            Schema::table('planos_acao', function (Blueprint $table) {
                $table->renameColumn('data_proxima_avaliacao', 'data_proximo_di');
            });
        }
    }
};
