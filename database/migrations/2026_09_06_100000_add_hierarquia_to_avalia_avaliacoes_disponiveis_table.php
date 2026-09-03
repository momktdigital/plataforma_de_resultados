<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Uma "prova" do Avalia Pro pode cobrir dezenas de disciplinas em vários
// cursos ao mesmo tempo (confirmado com dado real desta IES: uma única
// prova chegou a ter 89 disciplinas em 8 cursos diferentes) — sem isso,
// selecionar "uma prova" no catálogo forçava sincronizar tudo isso junto,
// às cegas. Agora o catálogo tem dois níveis: `pai_id` null = prova (como
// antes); `pai_id` preenchido = disciplina dentro daquela prova, com
// `curso` como rótulo de agrupamento na tela (não é uma entidade própria,
// só orienta a árvore de seleção). A seleção real sempre vive na folha
// (disciplina) — ver App\Services\Avalia\AvaliaSyncService::idsPermitidos().
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avalia_avaliacoes_disponiveis', function (Blueprint $table) {
            $table->foreignId('pai_id')->nullable()->after('id')
                ->constrained('avalia_avaliacoes_disponiveis')->cascadeOnDelete();
            $table->string('curso')->nullable()->after('nome');
        });
    }

    public function down(): void
    {
        Schema::table('avalia_avaliacoes_disponiveis', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pai_id');
            $table->dropColumn('curso');
        });
    }
};
