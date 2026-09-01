<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Corrige um bug real em produção: um import de resultados com muitas linhas
// ignoradas (ex.: coluna de CPF vindo sem os zeros à esquerda por formatação
// numérica do Excel) grava o motivo de cada uma em
// import_resultados_{codigo}_ignoradas (ver ImportStatusTracker::concluir())
// — um JSON de alguns milhares de entradas passa fácil das 64KB de um TEXT, e
// o INSERT falhava com "Data too long for column 'valor'" DEPOIS que os dados
// de verdade (respostas/resultado_resumos) já tinham sido commitados —
// fazendo o import aparecer como "falhou" no admin mesmo já tendo gravado
// tudo. Mesma classe de bug de 2026_08_25_010000 (varchar apertado passando
// batido no SQLite dos testes, só estourando no MySQL estrito de produção).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracoes_sistema', function (Blueprint $table) {
            $table->mediumText('valor')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('configuracoes_sistema', function (Blueprint $table) {
            $table->text('valor')->nullable()->change();
        });
    }
};
