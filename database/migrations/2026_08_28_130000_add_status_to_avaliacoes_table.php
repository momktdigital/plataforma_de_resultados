<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Status da avaliação inteira (não confundir com anulada_modo, que é por
// QUESTÃO — ver 2026_08_27_130000_add_anulada_modo_to_questoes_table.php):
// 'ativa' é o padrão; 'anulada' marca a avaliação inteira como invalidada
// (ex.: fraude, falha técnica na aplicação da prova). Só um rótulo visível
// no admin por enquanto — não afeta cálculo de nota nem o que já foi
// mostrado no portal.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->string('status', 20)->default('ativa')->after('data_avaliacao');
        });
    }

    public function down(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
