<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// As colunas `matriz_prova_a/b/c`, `dcn_a/b`, `portaria_inep_a/b/c` e
// `ppc_a/b/c/d` de `questoes` são, na prática, um grupo repetitivo (uma
// questão pode se alinhar a 0..N itens de cada referência) que foi achatado
// em colunas numeradas — o número de letras (até "c" ou até "d") foi um
// palpite, não uma regra fixa. Viram linhas aqui (questao_id, tipo, valor),
// do mesmo jeito que `questao_matrizes` já faz para período/disciplina/
// código, para não precisar de uma migration nova toda vez que alguém
// precisar de mais um item numa dessas referências.
return new class extends Migration
{
    private const COLUNA_PARA_TIPO = [
        'matriz_prova_a' => 'matriz_prova',
        'matriz_prova_b' => 'matriz_prova',
        'matriz_prova_c' => 'matriz_prova',
        'dcn_a' => 'dcn',
        'dcn_b' => 'dcn',
        'portaria_inep_a' => 'portaria_inep',
        'portaria_inep_b' => 'portaria_inep',
        'portaria_inep_c' => 'portaria_inep',
        'ppc_a' => 'ppc',
        'ppc_b' => 'ppc',
        'ppc_c' => 'ppc',
        'ppc_d' => 'ppc',
    ];

    public function up(): void
    {
        Schema::create('questao_referencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('questao_id')->constrained('questoes')->cascadeOnDelete();
            $table->string('tipo');
            $table->string('valor');
            $table->timestamps();

            $table->index(['questao_id', 'tipo']);
        });

        $agora = now();
        foreach (self::COLUNA_PARA_TIPO as $coluna => $tipo) {
            DB::table('questoes')
                ->whereNotNull($coluna)
                ->where($coluna, '!=', '')
                ->get(['id', $coluna])
                ->each(function ($questao) use ($coluna, $tipo, $agora) {
                    DB::table('questao_referencias')->insert([
                        'questao_id' => $questao->id,
                        'tipo' => $tipo,
                        'valor' => $questao->{$coluna},
                        'created_at' => $agora,
                        'updated_at' => $agora,
                    ]);
                });
        }

        Schema::table('questoes', function (Blueprint $table) {
            $table->dropColumn(array_keys(self::COLUNA_PARA_TIPO));
        });
    }

    public function down(): void
    {
        // Reversão só de estrutura — a ordem original das letras (a/b/c/d)
        // não é recuperável a partir de `questao_referencias` (que não
        // guarda posição), então os dados não voltam para as colunas.
        Schema::table('questoes', function (Blueprint $table) {
            foreach (array_keys(self::COLUNA_PARA_TIPO) as $coluna) {
                $table->string($coluna)->nullable();
            }
        });

        Schema::dropIfExists('questao_referencias');
    }
};
