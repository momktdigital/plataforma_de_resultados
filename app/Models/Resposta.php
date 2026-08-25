<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Resposta de um respondente (identificado por CPF ou RA) a uma questão de
 * uma avaliação, num período letivo. `aluno_chave` é uma coluna gerada pelo banco
 * (COALESCE(cpf, ra)) e nunca deve ser atribuída manualmente.
 *
 * Tabela `respostas` (não `resultados`) de propósito: a aplicação legada já
 * tem uma tabela `resultados` no mesmo banco compartilhado.
 */
class Resposta extends Model
{
    use SoftDeletes;

    protected $table = 'respostas';

    protected $fillable = [
        'avaliacao_codigo',
        'aluno_id',
        'ra',
        'cpf',
        'periodo',
        'questao_numero',
        'resposta',
    ];

    protected function casts(): array
    {
        return [
            'questao_numero' => 'integer',
        ];
    }

    public function avaliacao(): BelongsTo
    {
        return $this->belongsTo(Avaliacao::class, 'avaliacao_codigo', 'codigo');
    }

    public function aluno(): BelongsTo
    {
        return $this->belongsTo(Aluno::class);
    }
}
