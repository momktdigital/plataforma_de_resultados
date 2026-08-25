<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provas', function (Blueprint $table) {
            $table->id('codigo');
            $table->string('nome')->nullable();
            $table->string('tipo')->nullable();
            $table->string('link_comentado')->nullable();

            // `integer` (não `foreignId`/BIGINT UNSIGNED) de propósito: precisa
            // bater com o tipo de `admins.id` (INT) para o MySQL aceitar a FK.
            $table->integer('criado_por')->nullable();
            $table->foreign('criado_por')->references('id')->on('admins')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provas');
    }
};
