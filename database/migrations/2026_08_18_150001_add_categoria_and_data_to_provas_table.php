<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// `categoria_id` agrupa a Prova na árvore de categorias exibida no boletim
// do portal público; `data_prova` é a data em que a prova foi aplicada
// (distinta de `created_at`, que é só quando o registro foi cadastrado) —
// usada para ordenar/filtrar o boletim por data.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provas', function (Blueprint $table) {
            $table->foreignId('categoria_id')->nullable()->after('tipo')->constrained('categorias')->nullOnDelete();
            $table->date('data_prova')->nullable()->after('categoria_id');
        });
    }

    public function down(): void
    {
        Schema::table('provas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('categoria_id');
            $table->dropColumn('data_prova');
        });
    }
};
