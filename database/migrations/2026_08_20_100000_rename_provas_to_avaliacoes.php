<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A entidade "prova" passou a se chamar "avaliação" em toda a aplicação —
// esta migration alinha o schema ao novo nome: renomeia a tabela em si, a
// coluna `data_prova` (própria dela) e a coluna `prova_codigo` nas quatro
// tabelas que referenciam a avaliação por FK. Nenhuma linha é reescrita,
// apenas nomes — os dados existentes são preservados como estão.
//
// Cuidado ao rodar isto (de novo, do zero) num MySQL/MariaDB antigo: em
// MySQL 8+/MariaDB 10.5+ um RENAME COLUMN é só metadado (instantâneo, sem
// travar a tabela). Numa versão mais velha o MySQL/MariaDB pode não suportar
// o ALGORITHM=INSTANT pra essa operação e cair pra reconstruir a tabela
// inteira — o que trava escrita nela (aqui, `respostas`, que é a maior)
// pelo tempo da reconstrução. Confirme a versão do servidor de destino antes
// de aplicar isto num ambiente novo/de recuperação de desastre.
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('provas', 'avaliacoes');

        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->renameColumn('data_prova', 'data_avaliacao');
        });

        foreach (['questoes', 'respostas', 'resultado_metricas', 'resultado_resumos'] as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->renameColumn('prova_codigo', 'avaliacao_codigo');
            });
        }
    }

    public function down(): void
    {
        foreach (['questoes', 'respostas', 'resultado_metricas', 'resultado_resumos'] as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->renameColumn('avaliacao_codigo', 'prova_codigo');
            });
        }

        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->renameColumn('data_avaliacao', 'data_prova');
        });

        Schema::rename('avaliacoes', 'provas');
    }
};
