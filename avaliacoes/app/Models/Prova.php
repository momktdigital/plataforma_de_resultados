<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Uma avaliação (prova, simulado, exame institucional...). O código é gerado
 * automaticamente — não existe cadastro manual de identificador.
 */
class Prova extends Model
{
    use SoftDeletes;

    protected $table = 'provas';

    protected $primaryKey = 'codigo';

    protected $fillable = [
        'nome',
        'tipo',
        'link_comentado',
        'criado_por',
        'categoria_id',
        'data_prova',
    ];

    protected function casts(): array
    {
        return [
            'data_prova' => 'date',
        ];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function questoes(): HasMany
    {
        return $this->hasMany(Questao::class, 'prova_codigo', 'codigo');
    }

    public function resultados(): HasMany
    {
        return $this->hasMany(Resposta::class, 'prova_codigo', 'codigo');
    }

    public function metricas(): HasMany
    {
        return $this->hasMany(ResultadoMetrica::class, 'prova_codigo', 'codigo');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'criado_por');
    }
}
