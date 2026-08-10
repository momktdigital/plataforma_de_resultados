<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Resposta de um respondente (identificado por CPF ou RA) a uma questão de
 * uma prova. `aluno_chave` é uma coluna gerada pelo banco (COALESCE(cpf, ra))
 * e nunca deve ser atribuída manualmente.
 */
class Resultado extends Model
{
    use SoftDeletes;

    protected $table = 'resultados';

    protected $fillable = [
        'prova_codigo',
        'aluno_id',
        'ra',
        'cpf',
        'questao_numero',
        'resposta',
    ];

    protected function casts(): array
    {
        return [
            'questao_numero' => 'integer',
        ];
    }

    public function prova(): BelongsTo
    {
        return $this->belongsTo(Prova::class, 'prova_codigo', 'codigo');
    }

    public function aluno(): BelongsTo
    {
        return $this->belongsTo(Aluno::class);
    }
}
