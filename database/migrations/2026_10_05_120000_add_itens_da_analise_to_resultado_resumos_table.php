<?php

use App\Services\ResumoResultadoService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Desempenho do Dashboard: o escore de cada respondente (e se ele estava ausente) era recalculado varrendo
// `respostas` mais de dez vezes por visita. Agora ele vem de `resultado_resumos`, que passa a guardar também:
//  - acertos_itens / itens_considerados: acertos e nº de respostas nos ITENS DA ANÁLISE PSICOMÉTRICA (questão com
//    gabarito e não anulada) — base do KR-20, da discriminação e das curvas dos itens;
//  - ausente (migration 2026_09_08_120000): prova inteira em branco.
// Ver App\Services\ResumoResultadoService::recalcular().
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resultado_resumos', function (Blueprint $table) {
            if (! Schema::hasColumn('resultado_resumos', 'acertos_itens')) {
                $table->unsignedInteger('acertos_itens')->default(0)->after('percentual');
            }
            if (! Schema::hasColumn('resultado_resumos', 'itens_considerados')) {
                $table->unsignedInteger('itens_considerados')->default(0)->after('percentual');
            }
        });

        // Preenche as três colunas das linhas que já existem (recalcular() refaz o resumo da avaliação inteira).
        $servico = new ResumoResultadoService;
        DB::table('respostas')->select('avaliacao_codigo')->distinct()->pluck('avaliacao_codigo')
            ->each(fn ($codigo) => $servico->recalcular((int) $codigo));
    }

    public function down(): void
    {
        Schema::table('resultado_resumos', function (Blueprint $table) {
            $table->dropColumn(['acertos_itens', 'itens_considerados']);
        });
    }
};
