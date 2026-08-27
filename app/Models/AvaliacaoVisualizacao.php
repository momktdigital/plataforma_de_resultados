<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preferência salva de exibição de um visual (ver App\Services\Visualizacoes\VisualCatalog)
 * para uma avaliação — uma linha por (avaliação, visual). Nunca é a fonte da
 * verdade sobre se o visual PODE ser exibido (isso é sempre recalculado por
 * VisualizacaoDisponibilidadeService a partir dos dados reais da avaliação);
 * é só a preferência do admin, aplicada por cima dessa disponibilidade.
 */
class AvaliacaoVisualizacao extends Model
{
    protected $table = 'avaliacao_visualizacoes';

    protected $fillable = [
        'avaliacao_codigo',
        'visual',
        'visivel_aluno',
        'visivel_admin',
    ];

    protected function casts(): array
    {
        return [
            'visivel_aluno' => 'boolean',
            'visivel_admin' => 'boolean',
        ];
    }

    public function avaliacao(): BelongsTo
    {
        return $this->belongsTo(Avaliacao::class, 'avaliacao_codigo', 'codigo');
    }
}
