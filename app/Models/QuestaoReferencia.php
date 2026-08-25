<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Referência de uma questão a um item externo (matriz de avaliação, DCN,
 * portaria INEP, PPC...) — `tipo` identifica a qual grupo pertence, já que
 * uma questão pode ter 0..N valores de cada tipo (ver a migration que criou
 * esta tabela para o histórico).
 */
class QuestaoReferencia extends Model
{
    protected $table = 'questao_referencias';

    protected $fillable = ['questao_id', 'tipo', 'valor'];

    public function questao(): BelongsTo
    {
        return $this->belongsTo(Questao::class);
    }
}
