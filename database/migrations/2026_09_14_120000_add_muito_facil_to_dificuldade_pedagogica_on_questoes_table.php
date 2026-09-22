<?php

use App\Support\Dificuldade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questoes', function (Blueprint $table) {
            $table->enum('dificuldade_pedagogica', Dificuldade::valores())->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('questoes', function (Blueprint $table) {
            $table->enum('dificuldade_pedagogica', ['facil', 'medio', 'dificil'])->nullable()->change();
        });
    }
};
