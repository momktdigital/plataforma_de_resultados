<?php

namespace App\Models;

use App\Support\CacheDeAnalise;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Questao extends Model
{
    use SoftDeletes;

    protected $table = 'questoes';

    protected $fillable = [
        'avaliacao_codigo',
        'numero',
        'gabarito',
        'anulada_modo',
        'area',
        'tema',
        'habilidade',
        'bloom_nivel',
        'bloom_verbo',
        'miller_nivel',
        'dificuldade_pedagogica',
        'dificuldade_tri',
    ];

    protected static function booted(): void
    {
        // Editar/excluir/restaurar uma questão (área, tema, anulação...) muda as análises em cache da avaliação.
        $invalidar = fn (self $questao) => CacheDeAnalise::invalidar((int) $questao->avaliacao_codigo);
        static::saved($invalidar);
        static::deleted($invalidar);
        static::restored($invalidar);
    }

    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'dificuldade_tri' => 'decimal:4',
        ];
    }

    public function avaliacao(): BelongsTo
    {
        return $this->belongsTo(Avaliacao::class, 'avaliacao_codigo', 'codigo');
    }

    public function matrizes(): HasMany
    {
        return $this->hasMany(QuestaoMatriz::class);
    }

    /** Referências a matriz de avaliacao, DCN, portaria INEP e PPC — ver App\Models\QuestaoReferencia. */
    public function referencias(): HasMany
    {
        return $this->hasMany(QuestaoReferencia::class);
    }
}
