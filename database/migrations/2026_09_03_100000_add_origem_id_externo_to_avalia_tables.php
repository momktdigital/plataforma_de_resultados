<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Rastreia de onde veio cada avaliação/questão/resposta/métrica: 'manual'
// (import por planilha ou cadastro no admin, como sempre foi) ou
// 'avalia_pro'/'avalia_online' (sincronizado do +A Data / Redshift do
// Avalia). `id_externo` guarda o identificador da entidade no lado do
// Avalia, usado pelo AvaliaSyncService para saber se já existe um registro
// aqui para atualizar (upsert) em vez de duplicar a cada sincronização.
//
// origem='manual' é o padrão de toda linha já existente e de todo cadastro
// novo feito no admin — nada muda para quem não usa a integração.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->string('origem', 20)->default('manual')->after('status');
            $table->string('id_externo', 100)->nullable()->after('origem');

            $table->unique(['origem', 'id_externo'], 'uk_avaliacoes_origem_id_externo');
        });

        Schema::table('questoes', function (Blueprint $table) {
            $table->string('origem', 20)->default('manual')->after('anulada_modo');
            $table->string('id_externo', 100)->nullable()->after('origem');

            // Único por avaliação (não globalmente): a mesma questão do
            // Avalia pode, em tese, aparecer em mais de uma avaliação daqui
            // (cada uma já é uma linha própria por causa do unique
            // (avaliacao_codigo, numero) existente).
            $table->unique(['avaliacao_codigo', 'id_externo'], 'uk_questoes_avaliacao_id_externo');
        });

        Schema::table('respostas', function (Blueprint $table) {
            // Sem unique aqui de propósito: a chave de upsert de `respostas`
            // já é (avaliacao_codigo, aluno_chave, periodo, questao_numero)
            // — id_externo/origem são só informativos, para auditoria e para
            // a trava de edição em App\Models\Avaliacao::origemBloqueiaEdicao().
            $table->string('origem', 20)->default('manual')->after('resposta');
            $table->string('id_externo', 100)->nullable()->after('origem');
        });

        Schema::table('resultado_metricas', function (Blueprint $table) {
            $table->string('origem', 20)->default('manual')->after('valor');
            $table->string('id_externo', 100)->nullable()->after('origem');
        });
    }

    public function down(): void
    {
        foreach (['avaliacoes', 'questoes', 'respostas', 'resultado_metricas'] as $tabela) {
            Schema::table($tabela, function (Blueprint $table) use ($tabela) {
                if ($tabela === 'avaliacoes') {
                    $table->dropUnique('uk_avaliacoes_origem_id_externo');
                }
                if ($tabela === 'questoes') {
                    $table->dropUnique('uk_questoes_avaliacao_id_externo');
                }

                $table->dropColumn(['origem', 'id_externo']);
            });
        }
    }
};
