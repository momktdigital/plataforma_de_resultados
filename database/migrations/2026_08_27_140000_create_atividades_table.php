<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Trilha de auditoria leve: quem fez o quê e quando, pras ações que mexem em
// nota (editar gabarito, anular questão, excluir/restaurar período, rodar um
// import) ou em conta de administrador — nada disso tinha "quem/quando"
// registrado antes, só Avaliacao.criado_por guardava um criador.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atividades', function (Blueprint $table) {
            $table->id();

            // INT (não BIGINT UNSIGNED) e nullOnDelete de propósito: precisa
            // bater com o tipo de `admins.id` (INT) pro MySQL aceitar a FK, e
            // o registro precisa sobreviver à exclusão da conta do admin (só
            // perde o vínculo, não a linha do log) — por isso admin_username
            // guarda uma cópia do nome no momento da ação, não só o id.
            $table->integer('admin_id')->nullable();
            $table->foreign('admin_id')->references('id')->on('admins')->nullOnDelete();
            $table->string('admin_username', 50)->nullable();

            // Ex.: "questao.gabarito_alterado", "periodo.excluido", "import.resultados".
            $table->string('acao', 100);
            $table->string('alvo_tipo', 100)->nullable();
            $table->string('alvo_id', 100)->nullable();
            $table->text('detalhes')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['alvo_tipo', 'alvo_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atividades');
    }
};
