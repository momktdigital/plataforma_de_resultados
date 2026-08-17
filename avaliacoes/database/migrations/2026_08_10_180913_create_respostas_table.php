<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Nome da tabela: `respostas`, não `resultados` — a aplicação legada já tem
// uma tabela `resultados` no mesmo banco (ver database.sql). Cada linha aqui
// é a resposta de um respondente a UMA questão; ver `resultado_metricas`
// para notas/métricas agregadas (Total, Nota de Redação etc.).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('respostas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prova_codigo')->constrained('provas', 'codigo')->cascadeOnDelete();

            // Vínculo best-effort com o cadastro de alunos (pode não existir ainda).
            $table->foreignId('aluno_id')->nullable()->constrained('alunos')->nullOnDelete();

            // Identificação do respondente: só é obrigatório informar UM dos dois.
            $table->string('ra', 50)->nullable();
            $table->string('cpf', 20)->nullable();

            // Coluna gerada para permitir um índice único independente de qual
            // identificador (CPF ou RA) foi enviado no import.
            $table->string('aluno_chave', 50)->storedAs('COALESCE(cpf, ra)');

            // Período letivo (ex.: "2026/1"). Não é obrigatório no import, mas
            // existe para que o mesmo aluno possa refazer a mesma prova em
            // períodos diferentes sem uma resposta sobrescrever a outra.
            // NOT NULL com default '' (em vez de nullable) de propósito: em
            // índice único, MySQL/SQLite tratam NULL como "distinto de tudo",
            // o que quebraria o upsert para quem nunca informa período.
            $table->string('periodo', 100)->default('');

            $table->unsignedInteger('questao_numero');
            $table->string('resposta', 10)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['prova_codigo', 'aluno_chave', 'periodo', 'questao_numero'], 'uk_resposta_aluno_periodo_questao');
            $table->index(['prova_codigo', 'questao_numero']);
        });

        // CHECK constraint (defesa em profundidade além da validação da aplicação):
        // MySQL 8 suporta; SQLite (usado nos testes) não suporta ALTER TABLE ADD
        // CONSTRAINT, então é aplicado só quando o driver é MySQL.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE respostas ADD CONSTRAINT chk_respostas_ra_ou_cpf CHECK (ra IS NOT NULL OR cpf IS NOT NULL)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('respostas');
    }
};
