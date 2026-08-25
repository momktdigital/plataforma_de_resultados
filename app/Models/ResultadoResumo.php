<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Resumo pré-calculado (acertos/total/percentual) de um aluno numa avaliação+
 * período — mantido por App\Services\ResumoResultadoService, nunca gravado
 * diretamente pela aplicação. Existe só para o boletim do portal não
 * precisar escanear `respostas` (que cresce por aluno×avaliação×questão) toda
 * vez que alguém consulta.
 */
class ResultadoResumo extends Model
{
    protected $table = 'resultado_resumos';

    protected $fillable = ['avaliacao_codigo', 'aluno_chave', 'periodo', 'ra', 'cpf', 'aluno_id', 'acertos', 'total', 'percentual'];

    protected function casts(): array
    {
        return [
            'acertos' => 'integer',
            'total' => 'integer',
            'percentual' => 'decimal:1',
        ];
    }

    public function avaliacao(): BelongsTo
    {
        return $this->belongsTo(Avaliacao::class, 'avaliacao_codigo', 'codigo');
    }
}
