<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Corrige um 500 real no import de matrícula em produção (MySQL roda em modo
// strict — ver config/database.php): a planilha traz "Turma" com nomes longos
// (ex.: "6º PERÍODO ARQUITETURA E URBANISMO SEMIPRESENCIAL 3381", 55
// caracteres), estourando o varchar(50) criado em
// 2026_08_17_190000_add_matricula_columns_to_alunos_table.php — no SQLite dos
// testes isso passa batido (sem enforcement de tamanho), por isso não foi
// pego antes. "Período" também tem um fallback que pode guardar o texto bruto
// da planilha (não só "5º"), e os campos de dados pessoais adicionados em
// 2026_08_25_000000 têm limites igualmente apertados pra texto livre de
// planilha (ex.: "Não dispõe da informação_ingressante até 2014" em
// Cor/Raça, 45 caracteres, quase estourando o varchar(50)) — alargamos todos
// por segurança.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alunos', function (Blueprint $table) {
            $table->string('turma', 255)->nullable()->change();
            $table->string('periodo', 30)->nullable()->change();
            $table->string('cor_raca', 100)->nullable()->change();
            $table->string('religiao', 100)->nullable()->change();
            $table->string('estado_civil', 60)->nullable()->change();
            $table->string('celular', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('alunos', function (Blueprint $table) {
            $table->string('turma', 50)->nullable()->change();
            $table->string('periodo', 10)->nullable()->change();
            $table->string('cor_raca', 50)->nullable()->change();
            $table->string('religiao', 50)->nullable()->change();
            $table->string('estado_civil', 30)->nullable()->change();
            $table->string('celular', 20)->nullable()->change();
        });
    }
};
