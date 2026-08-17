<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A tabela `resultados` pertence à aplicação legada (database.sql) e já
// existe em produção — esta migration só a cria quando ausente
// (ambientes novos/testes, para o comando `legado:importar` ter o que
// ler), nunca altera uma tabela existente. `down()` é proposital: nunca
// apagamos resultados legados a partir daqui. Não confundir com a tabela
// nova `respostas` (ver 2026_08_10_180913_create_respostas_table.php).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('resultados')) {
            return;
        }

        Schema::create('resultados', function (Blueprint $table) {
            $table->id();
            $table->string('ra', 50);
            $table->string('periodo', 100);
            $table->string('nome_avaliacao', 150);
            $table->json('respostas')->nullable();
            $table->string('link_comentado')->nullable();
            $table->json('notas_finais')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['ra', 'periodo', 'nome_avaliacao'], 'uk_ra_periodo_avaliacao');
        });
    }

    public function down(): void
    {
        // Intencionalmente vazio — ver comentário acima.
    }
};
