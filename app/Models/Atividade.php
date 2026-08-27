<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha da trilha de auditoria — ver App\Support\AtividadeLogger, que é
 * quem de fato cria os registros. Somente leitura por aqui (não há
 * update/destroy em lugar nenhum: um log de auditoria que pode ser editado
 * não serve pra nada).
 */
class Atividade extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'admin_id',
        'admin_username',
        'acao',
        'alvo_tipo',
        'alvo_id',
        'detalhes',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'detalhes' => 'array',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
