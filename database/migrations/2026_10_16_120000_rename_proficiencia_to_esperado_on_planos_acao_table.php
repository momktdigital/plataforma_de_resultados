<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// O sistema não trabalha mais com "proficiência": o indicador do plano de ação passa a ser o percentual de ALUNOS COM DESEMPENHO
// ESPERADO (quem alcançou o mínimo esperado para o período do curso em que está). Renomeia as duas colunas e o visual de origem
// gravado em `origem_visual`. Migration à parte porque a que cria a tabela já foi executada em bancos reais.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('planos_acao')) {
            return;
        }

        foreach (['proficiencia_atual' => 'esperado_atual', 'meta_proficiencia' => 'meta_esperado'] as $de => $para) {
            if (Schema::hasColumn('planos_acao', $de) && ! Schema::hasColumn('planos_acao', $para)) {
                Schema::table('planos_acao', function (Blueprint $table) use ($de, $para) {
                    $table->renameColumn($de, $para);
                });
            }
        }

        if (Schema::hasColumn('planos_acao', 'origem_visual')) {
            DB::table('planos_acao')->where('origem_visual', 'proficiencia')->update(['origem_visual' => 'esperado']);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('planos_acao')) {
            return;
        }

        foreach (['esperado_atual' => 'proficiencia_atual', 'meta_esperado' => 'meta_proficiencia'] as $de => $para) {
            if (Schema::hasColumn('planos_acao', $de) && ! Schema::hasColumn('planos_acao', $para)) {
                Schema::table('planos_acao', function (Blueprint $table) use ($de, $para) {
                    $table->renameColumn($de, $para);
                });
            }
        }

        if (Schema::hasColumn('planos_acao', 'origem_visual')) {
            DB::table('planos_acao')->where('origem_visual', 'esperado')->update(['origem_visual' => 'proficiencia']);
        }
    }
};
