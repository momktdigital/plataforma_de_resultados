<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A tabela `gabaritos` pertence à aplicação legada (database.sql) e já existe
// em produção — esta migration só a cria quando ausente (ambientes
// novos/testes, para o comando `legado:importar` ter o que ler), nunca altera
// uma tabela existente. `down()` é proposital: nunca apagamos gabaritos
// legados a partir daqui.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gabaritos')) {
            return;
        }

        Schema::create('gabaritos', function (Blueprint $table) {
            $table->id();
            $table->string('nome_avaliacao', 150)->unique();
            $table->json('respostas')->nullable();
            $table->string('link_comentado')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        // Intencionalmente vazio — ver comentário acima.
    }
};
