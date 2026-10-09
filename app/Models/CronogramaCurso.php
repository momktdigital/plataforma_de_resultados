<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um curso a que uma atividade do cronograma se aplica, com a situação dele nela. */
class CronogramaCurso extends Model
{
    protected $table = 'cronograma_item_cursos';

    public $timestamps = false;

    protected $fillable = ['item_id', 'curso', 'status'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(CronogramaItem::class, 'item_id');
    }
}
