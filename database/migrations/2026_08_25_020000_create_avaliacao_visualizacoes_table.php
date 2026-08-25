<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Preferência configurada por avaliação: quais visuais (gráficos/painéis do
// App\Services\Visualizacoes\VisualCatalog) aparecem para o aluno no boletim e/ou
// para o administrativo no painel BI. Uma linha por (avaliação, visual) — só
// existe linha para o que já foi configurado ao menos uma vez; sem linha, o
// visual fica oculto (padrão seguro). A disponibilidade real (se a avaliação
// tem os dados necessários pra aquele visual) nunca é lida daqui — é
// recalculada a cada acesso por VisualizacaoDisponibilidadeService, porque
// pode mudar a qualquer momento (nova importação de questões/resultados).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avaliacao_visualizacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('avaliacao_codigo')->constrained('avaliacoes', 'codigo')->cascadeOnDelete();
            $table->string('visual', 60);
            $table->boolean('visivel_aluno')->default(false);
            $table->boolean('visivel_admin')->default(false);
            $table->timestamps();

            $table->unique(['avaliacao_codigo', 'visual'], 'uk_avaliacao_visualizacao');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_visualizacoes');
    }
};
