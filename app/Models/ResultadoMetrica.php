<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Métrica agregada de um respondente numa prova/período que não é resposta
 * de uma questão específica (ex.: "Nota de Redação", "Total"). `aluno_chave`
 * é gerada pelo banco (COALESCE(cpf, ra)) e nunca deve ser atribuída
 * manualmente.
 */
class ResultadoMetrica extends Model
{
    use SoftDeletes;

    protected $table = 'resultado_metricas';

    protected $fillable = [
        'prova_codigo',
        'aluno_id',
        'ra',
        'cpf',
        'periodo',
        'nome_metrica',
        'valor',
    ];

    public function prova(): BelongsTo
    {
        return $this->belongsTo(Prova::class, 'prova_codigo', 'codigo');
    }

    public function aluno(): BelongsTo
    {
        return $this->belongsTo(Aluno::class);
    }
}
