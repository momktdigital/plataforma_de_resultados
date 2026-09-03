<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Catálogo leve das provas/questionários existentes no Avalia — populado por
// uma consulta barata (SELECT DISTINCT em dim_exams/dim_questionnaires, não
// nas tabelas de fato, que têm mais de 1M de linhas nesta IES) disparada pelo
// botão "Atualizar lista de provas disponíveis" na tela de Integração Avalia.
// Existe pra resolver o medo real de rodar "Forçar sincronização" e puxar o
// histórico inteiro de uma vez: o admin escolhe aqui quais provas entram,
// numa granularidade de PROVA INTEIRA (não por disciplina) — ver
// App\Services\Avalia\AvaliaSyncService::idsPermitidos().
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avalia_avaliacoes_disponiveis', function (Blueprint $table) {
            $table->id();
            $table->string('produto', 20); // 'avalia_pro' ou 'avalia_online'
            $table->string('id_externo', 100); // assessment_id (pro) ou questionnaire_id (online) — a prova inteira, não a chave por disciplina de `avaliacoes.id_externo`
            $table->string('nome')->nullable();
            $table->string('tipo')->nullable();
            $table->date('data_referencia')->nullable();

            // false por padrão de propósito: enquanto o admin não marcar
            // nada (ou trocar o modo para "todas"), a sincronização não trai
            // nenhuma prova — ver ConfiguracaoSistema `avalia_modo_{produto}`.
            $table->boolean('selecionada')->default(false);

            $table->timestamps();

            $table->unique(['produto', 'id_externo'], 'uk_avalia_disponiveis_produto_id_externo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avalia_avaliacoes_disponiveis');
    }
};
