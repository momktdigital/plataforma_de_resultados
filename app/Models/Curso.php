<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Nomes de curso vistos na importação de matrícula de alunos — só para
 * alimentar filtros/telas de referência, não há regra de negócio aqui.
 */
class Curso extends Model
{
    protected $fillable = [
        'nome',
    ];
}
