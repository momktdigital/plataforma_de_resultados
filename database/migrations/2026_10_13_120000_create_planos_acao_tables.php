<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Plano de ação do coordenador: nasce de um dado do painel (um gráfico, um número, uma linha de tabela), percorre o
// roteiro "dado → causa → ação" (leitura, Ishikawa + 5 Porquês, ações) e é enviado ao colaborador para aprovação; depois
// de aprovado, é acompanhado até o encerramento.
//
// - planos_acao: o plano. Guarda a FOTO dos indicadores no momento da criação (linha de base: participação, meta de
//   participação, proficiência) e a do dado que originou o plano (`contexto`) — o colaborador, que não enxerga o painel
//   de resultados, analisa o plano só com o que está aqui. Só dado AGREGADO: nunca nome, RA ou CPF de aluno.
// - plano_acao_acoes: as ações pedagógicas (um plano pode ter várias, cada uma com responsável, prazo e verificação).
// - plano_acao_eventos: LOG de tudo que acontece (envio, decisão com justificativa, andamento, prazo reprogramado,
//   comentário...). Só se acrescenta: o estado atual está no plano, a história aqui.
//
// admin_id/decidido_por são INT (não foreignId): `admins.id` é INT no schema legado e o MySQL exige o mesmo tipo dos dois
// lados de uma FK (o SQLite não pega esse erro).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planos_acao', function (Blueprint $table) {
            $table->id();
            $table->integer('admin_id')->nullable();
            $table->foreign('admin_id')->references('id')->on('admins')->nullOnDelete();

            // Onde o plano foi iniciado.
            $table->string('curso', 200);
            $table->string('periodo_letivo', 20)->default('');
            $table->unsignedBigInteger('categoria_id')->nullable();
            $table->unsignedBigInteger('avaliacao_codigo')->nullable();
            $table->string('origem_visual', 40);
            $table->string('origem_item', 255)->nullable();
            $table->string('origem_rotulo', 300);
            $table->json('contexto')->nullable();

            // 1. Ponto de partida: a foto dos indicadores e a meta pactuada.
            $table->decimal('participacao_atual', 5, 1)->nullable();
            $table->decimal('meta_participacao', 5, 1)->nullable();
            $table->decimal('proficiencia_atual', 5, 1)->nullable();
            $table->decimal('meta_proficiencia', 5, 1)->nullable();
            $table->date('data_proxima_avaliacao')->nullable();

            // 2. Leitura do dado.
            $table->text('recorte')->nullable();
            $table->text('resultado')->nullable();
            $table->text('fragilidades')->nullable();
            $table->text('evidencias')->nullable();

            // 3. Causas.
            $table->json('causas')->nullable();
            $table->text('causa_priorizada')->nullable();
            $table->unsignedTinyInteger('nota_impacto')->nullable();
            $table->unsignedTinyInteger('nota_evidencia')->nullable();
            $table->unsignedTinyInteger('nota_governabilidade')->nullable();
            $table->json('porques')->nullable();
            $table->text('causa_raiz')->nullable();

            // Fluxo.
            $table->string('status', 20)->default('rascunho');
            $table->unsignedSmallInteger('envios')->default(0);
            $table->timestamp('enviado_em')->nullable();
            $table->timestamp('decidido_em')->nullable();
            $table->integer('decidido_por')->nullable();
            $table->foreign('decidido_por')->references('id')->on('admins')->nullOnDelete();
            $table->timestamp('encerrado_em')->nullable();
            $table->text('conclusao')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('curso');
            $table->index(['admin_id', 'status']);
        });

        Schema::create('plano_acao_acoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plano_id')->constrained('planos_acao')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordem')->default(1);
            $table->text('descricao');
            $table->text('execucao')->nullable();
            $table->string('responsavel', 150)->nullable();
            $table->date('prazo')->nullable();
            $table->text('verificacao')->nullable();
            $table->string('status', 20)->default('nao_iniciada');
            $table->timestamp('concluida_em')->nullable();
            $table->timestamps();

            $table->index('plano_id');
            $table->index('prazo');
        });

        Schema::create('plano_acao_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plano_id')->constrained('planos_acao')->cascadeOnDelete();
            $table->unsignedBigInteger('acao_id')->nullable();
            $table->integer('admin_id')->nullable();
            $table->foreign('admin_id')->references('id')->on('admins')->nullOnDelete();
            $table->string('tipo', 30);
            $table->text('texto')->nullable();
            $table->json('dados')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['plano_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plano_acao_eventos');
        Schema::dropIfExists('plano_acao_acoes');
        Schema::dropIfExists('planos_acao');
    }
};
