<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Uma avaliação (simulado, exame institucional...). O código é gerado
 * automaticamente — não existe cadastro manual de identificador.
 */
class Avaliacao extends Model
{
    use SoftDeletes;

    protected $table = 'avaliacoes';

    protected $primaryKey = 'codigo';

    protected $fillable = [
        'nome',
        'tipo',
        'link_comentado',
        'criado_por',
        'categoria_id',
        'data_avaliacao',
        'status',
    ];

    // Espelha o default da coluna no banco — sem isso, uma instância recém-
    // criada em memória (Avaliacao::create([])) fica com status null até um
    // refresh(), já que Eloquent não lê de volta o DEFAULT do SQL sozinho.
    protected $attributes = [
        'status' => 'ativa',
    ];

    protected function casts(): array
    {
        return [
            'data_avaliacao' => 'date',
        ];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function questoes(): HasMany
    {
        return $this->hasMany(Questao::class, 'avaliacao_codigo', 'codigo');
    }

    public function resultados(): HasMany
    {
        return $this->hasMany(Resposta::class, 'avaliacao_codigo', 'codigo');
    }

    public function metricas(): HasMany
    {
        return $this->hasMany(ResultadoMetrica::class, 'avaliacao_codigo', 'codigo');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'criado_por');
    }

    public function visualizacoes(): HasMany
    {
        return $this->hasMany(AvaliacaoVisualizacao::class, 'avaliacao_codigo', 'codigo');
    }
}
