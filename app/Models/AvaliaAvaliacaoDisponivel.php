<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma prova (Avalia Pro) ou questionário (Avalia Online) que existe no
 * Avalia, listado via App\Services\Avalia\AvaliaSyncService::atualizarCatalogo().
 * `selecionada` é a escolha do admin em admin/sistema/integracao-avalia —
 * só é usada quando `ConfiguracaoSistema::valor("avalia_modo_{produto}")`
 * for 'selecionadas' (o padrão 'selecionadas' com nada marcado significa
 * "sincronizar nada ainda", de propósito).
 *
 * `pai_id` null = prova (nível de topo, sempre existe). `pai_id` preenchido
 * = disciplina dentro daquela prova (só existe pro Avalia Pro — uma prova
 * pode cobrir dezenas de disciplinas em cursos diferentes ao mesmo tempo).
 * A seleção que realmente controla a sincronização vive sempre na folha —
 * `selecionada` numa linha de prova é só um estado herdado no JS da tela,
 * nunca lido por App\Services\Avalia\AvaliaSyncService::idsPermitidos().
 */
class AvaliaAvaliacaoDisponivel extends Model
{
    protected $table = 'avalia_avaliacoes_disponiveis';

    protected $fillable = [
        'produto',
        'pai_id',
        'id_externo',
        'nome',
        'curso',
        'tipo',
        'data_referencia',
        'selecionada',
    ];

    protected function casts(): array
    {
        return [
            'data_referencia' => 'date',
            'selecionada' => 'boolean',
        ];
    }

    public function pai(): BelongsTo
    {
        return $this->belongsTo(self::class, 'pai_id');
    }

    public function disciplinas(): HasMany
    {
        return $this->hasMany(self::class, 'pai_id');
    }
}
