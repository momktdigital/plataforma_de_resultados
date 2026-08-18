<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Colunas usadas pela importação de matrícula (Cód. Perfil, Status, Per.
// Letivo, Período, Turma) — chegaram a ser referenciadas pelo protótipo
// legado (admin/alunos_di_process.php) mas nunca tiveram uma migration em
// lugar nenhum do repositório, então a importação de matrícula sempre
// falhou em produção. Adiciona cada coluna só se ainda não existir, para
// rodar com segurança tanto em bancos legados quanto em bancos novos (onde
// a migration de `alunos` já as cria de uma vez).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alunos', function (Blueprint $table) {
            if (! Schema::hasColumn('alunos', 'cod_perfil')) {
                $table->string('cod_perfil', 50)->nullable()->after('email');
            }
            if (! Schema::hasColumn('alunos', 'status')) {
                $table->string('status', 50)->nullable()->after('cod_perfil');
            }
            if (! Schema::hasColumn('alunos', 'periodo_letivo')) {
                $table->string('periodo_letivo', 20)->nullable()->after('status');
            }
            if (! Schema::hasColumn('alunos', 'periodo')) {
                $table->string('periodo', 10)->nullable()->after('periodo_letivo');
            }
            if (! Schema::hasColumn('alunos', 'turma')) {
                $table->string('turma', 50)->nullable()->after('periodo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('alunos', function (Blueprint $table) {
            foreach (['cod_perfil', 'status', 'periodo_letivo', 'periodo', 'turma'] as $coluna) {
                if (Schema::hasColumn('alunos', $coluna)) {
                    $table->dropColumn($coluna);
                }
            }
        });
    }
};
