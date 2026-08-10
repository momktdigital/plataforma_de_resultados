<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma questão pode pertencer a mais de um período/disciplina/código da matriz
 * curricular — cada combinação é uma linha aqui, nunca colunas fixas.
 */
class QuestaoMatriz extends Model
{
    protected $table = 'questao_matrizes';

    protected $fillable = [
        'questao_id',
        'periodo',
        'disciplina',
        'codigo',
    ];

    public function questao(): BelongsTo
    {
        return $this->belongsTo(Questao::class);
    }
}
