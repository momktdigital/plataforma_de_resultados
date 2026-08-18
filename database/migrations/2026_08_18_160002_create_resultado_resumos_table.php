<?php

use App\Services\ResumoResultadoService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Tabela de resumo pré-calculado (acertos/total/percentual por aluno+prova+
// período) — ver App\Services\ResumoResultadoService para o porquê. Ao criar
// esta tabela, faz o backfill de toda prova que já tem resposta gravada, pra
// não deixar o boletim de ninguém em branco até a próxima importação.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resultado_resumos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prova_codigo')->constrained('provas', 'codigo')->cascadeOnDelete();

            $table->string('ra', 50)->nullable();
            $table->string('cpf', 20)->nullable();
            $table->string('aluno_chave', 50);

            $table->integer('aluno_id')->nullable();
            $table->foreign('aluno_id')->references('id')->on('alunos')->nullOnDelete();

            $table->string('periodo', 100)->default('');

            $table->unsignedInteger('acertos');
            $table->unsignedInteger('total');
            $table->decimal('percentual', 5, 1)->nullable();

            $table->timestamps();

            $table->unique(['prova_codigo', 'aluno_chave', 'periodo'], 'uk_resumo_prova_aluno_periodo');
            $table->index('ra');
            $table->index('cpf');
        });

        $servico = new ResumoResultadoService;

        DB::table('respostas')->select('prova_codigo')->distinct()->pluck('prova_codigo')
            ->each(fn ($provaCodigo) => $servico->recalcular($provaCodigo));
    }

    public function down(): void
    {
        Schema::dropIfExists('resultado_resumos');
    }
};
