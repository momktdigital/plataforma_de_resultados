<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Coordenadores: usuários de acesso limitado aos cursos a que estão vinculados.
//
// `admins.role` ('superadmin' | 'coordinator') e `admins.curso` já existem na
// tabela legada (database.sql) — só são criadas aqui quando ausentes
// (ambientes novos/testes). `admins.curso` guarda apenas o PRIMEIRO curso do
// coordenador, por compatibilidade com o legado (que só conhece um); a lista
// completa mora em `admin_cursos`.
//
// Cursos são guardados pelo NOME (igual a `alunos.curso`, texto livre vindo
// da planilha de matrícula), não por id: a tabela `cursos` é só referência.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            if (! Schema::hasColumn('admins', 'role')) {
                $table->string('role', 20)->default('superadmin');
            }
            if (! Schema::hasColumn('admins', 'curso')) {
                $table->string('curso', 200)->nullable();
            }
        });

        Schema::create('admin_cursos', function (Blueprint $table) {
            // INT (não foreignId) — mesmo motivo de avaliacoes.criado_por: admins.id é INT legado.
            $table->integer('admin_id');
            $table->foreign('admin_id')->references('id')->on('admins')->cascadeOnDelete();
            $table->string('curso', 200);

            $table->primary(['admin_id', 'curso']);
        });

        Schema::create('avaliacao_cursos', function (Blueprint $table) {
            $table->unsignedBigInteger('avaliacao_codigo');
            $table->foreign('avaliacao_codigo')->references('codigo')->on('avaliacoes')->cascadeOnDelete();
            $table->string('curso', 200);

            $table->primary(['avaliacao_codigo', 'curso']);
            $table->index('curso');
        });

        // Acesso excepcional: coordenador que enxerga a avaliação mesmo sem curso em comum.
        Schema::create('avaliacao_usuarios', function (Blueprint $table) {
            $table->unsignedBigInteger('avaliacao_codigo');
            $table->foreign('avaliacao_codigo')->references('codigo')->on('avaliacoes')->cascadeOnDelete();
            $table->integer('admin_id');
            $table->foreign('admin_id')->references('id')->on('admins')->cascadeOnDelete();

            $table->primary(['avaliacao_codigo', 'admin_id']);
        });

        // Backfill: cursos dos respondentes já importados.
        $servico = new \App\Services\AvaliacaoCursoService;
        DB::table('resultado_resumos')->select('avaliacao_codigo')->distinct()->pluck('avaliacao_codigo')
            ->each(fn ($codigo) => $servico->sincronizarDosRespondentes((int) $codigo));
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_usuarios');
        Schema::dropIfExists('avaliacao_cursos');
        Schema::dropIfExists('admin_cursos');
        // `role`/`curso` de admins pertencem ao legado: nunca removidas daqui.
    }
};
