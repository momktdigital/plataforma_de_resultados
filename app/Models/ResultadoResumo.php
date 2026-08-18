<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Resumo pré-calculado (acertos/total/percentual) de um aluno numa prova+
 * período — mantido por App\Services\ResumoResultadoService, nunca gravado
 * diretamente pela aplicação. Existe só para o boletim do portal não
 * precisar escanear `respostas` (que cresce por aluno×prova×questão) toda
 * vez que alguém consulta.
 */
class ResultadoResumo extends Model
{
    protected $table = 'resultado_resumos';

    protected $fillable = ['prova_codigo', 'aluno_chave', 'periodo', 'ra', 'cpf', 'aluno_id', 'acertos', 'total', 'percentual'];

    protected function casts(): array
    {
        return [
            'acertos' => 'integer',
            'total' => 'integer',
            'percentual' => 'decimal:1',
        ];
    }

    public function prova(): BelongsTo
    {
        return $this->belongsTo(Prova::class, 'prova_codigo', 'codigo');
    }
}
