<?php

use App\Services\AvaliacaoCursoService;
use App\Services\CursoDoResultadoService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// O curso é da MATRÍCULA, não do aluno, e muda no tempo (transferência de
// curso, cancelamento, trancamento...). `alunos` continua guardando só a
// matrícula atual (um curso por RA); o histórico fica em `aluno_matriculas`, e
// cada resultado (`resultado_resumos`) guarda o curso em que o aluno estava
// QUANDO fez a prova — é ele (não o curso atual) que decide quem enxerga o
// quê. Ver App\Services\CursoDoResultadoService.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aluno_matriculas', function (Blueprint $table) {
            $table->id();
            // INT (não foreignId): alunos.id é INT no schema legado.
            $table->integer('aluno_id');
            $table->foreign('aluno_id')->references('id')->on('alunos')->cascadeOnDelete();

            $table->string('curso', 200);
            $table->string('matriz', 255)->nullable();
            $table->string('periodo', 100)->nullable();
            $table->string('turma', 255)->nullable();
            $table->string('periodo_letivo', 20)->default('');
            $table->string('status', 50)->nullable();

            // Dt. Ativação (início desta matrícula no período) e Dt. Ocorrência
            // (quando deixou de valer, nas que não estão ATIVA).
            $table->date('data_inicio')->nullable();
            $table->date('data_fim')->nullable();

            $table->timestamps();

            $table->unique(['aluno_id', 'curso', 'periodo_letivo'], 'uk_matricula_aluno_curso_periodo');
        });

        Schema::table('resultado_resumos', function (Blueprint $table) {
            $table->string('curso', 200)->nullable()->after('aluno_id');
            $table->unsignedBigInteger('matricula_id')->nullable()->after('curso');
            $table->index(['avaliacao_codigo', 'curso'], 'idx_resumo_avaliacao_curso');
        });

        Schema::table('avaliacao_cursos', function (Blueprint $table) {
            // 'auto' = deduzido dos resultados (refeito a cada importação);
            // 'manual' = marcado por um administrador (nunca é apagado sozinho).
            $table->string('origem', 10)->default('auto')->after('curso');
        });

        // Linha de base: a matrícula atual de cada aluno vira o primeiro item do
        // histórico (o resto vem ao reimportar as planilhas de matrícula antigas).
        DB::table('aluno_matriculas')->insertUsing(
            ['aluno_id', 'curso', 'matriz', 'periodo', 'turma', 'periodo_letivo', 'status', 'created_at', 'updated_at'],
            DB::table('alunos')
                ->whereNotNull('curso')->where('curso', '!=', '')
                ->selectRaw("id, curso, matriz, periodo, turma, COALESCE(periodo_letivo, ''), status, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP")
        );

        // Curso de cada resultado já importado + cursos de cada avaliação.
        $cursoDoResultado = new CursoDoResultadoService;
        $cursosDaAvaliacao = new AvaliacaoCursoService;
        DB::table('resultado_resumos')->select('avaliacao_codigo')->distinct()->pluck('avaliacao_codigo')
            ->each(function ($codigo) use ($cursoDoResultado, $cursosDaAvaliacao) {
                $cursoDoResultado->atualizarAvaliacao((int) $codigo);
                $cursosDaAvaliacao->sincronizarDosRespondentes((int) $codigo);
            });
    }

    public function down(): void
    {
        Schema::table('avaliacao_cursos', function (Blueprint $table) {
            $table->dropColumn('origem');
        });

        Schema::table('resultado_resumos', function (Blueprint $table) {
            $table->dropIndex('idx_resumo_avaliacao_curso');
            $table->dropColumn(['curso', 'matricula_id']);
        });

        Schema::dropIfExists('aluno_matriculas');
    }
};
