<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Questao extends Model
{
    protected $table = 'questoes';

    protected $fillable = [
        'prova_codigo',
        'numero',
        'gabarito',
        'matriz_prova_a',
        'matriz_prova_b',
        'matriz_prova_c',
        'bloom_nivel',
        'bloom_verbo',
        'miller_nivel',
        'dificuldade_pedagogica',
        'dificuldade_tri',
        'dcn_a',
        'dcn_b',
        'portaria_inep_a',
        'portaria_inep_b',
        'portaria_inep_c',
        'ppc_a',
        'ppc_b',
        'ppc_c',
        'ppc_d',
    ];

    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'dificuldade_tri' => 'decimal:4',
        ];
    }

    public function prova(): BelongsTo
    {
        return $this->belongsTo(Prova::class, 'prova_codigo', 'codigo');
    }

    public function matrizes(): HasMany
    {
        return $this->hasMany(QuestaoMatriz::class);
    }
}
