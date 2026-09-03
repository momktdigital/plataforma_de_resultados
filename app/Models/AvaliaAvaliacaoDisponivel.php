<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Uma prova (Avalia Pro) ou questionário (Avalia Online) que existe no
 * Avalia, listado via App\Services\Avalia\AvaliaSyncService::atualizarCatalogo().
 * `selecionada` é a escolha do admin em admin/sistema/integracao-avalia —
 * só é usada quando `ConfiguracaoSistema::valor("avalia_modo_{produto}")`
 * for 'selecionadas' (o padrão 'selecionadas' com nada marcado significa
 * "sincronizar nada ainda", de propósito).
 */
class AvaliaAvaliacaoDisponivel extends Model
{
    protected $table = 'avalia_avaliacoes_disponiveis';

    protected $fillable = [
        'produto',
        'id_externo',
        'nome',
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
}
