<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cadastro de alunos, mantido pela aplicação legada.
 */
class Aluno extends Model
{
    protected $table = 'alunos';

    protected $fillable = [
        'ra',
        'cpf',
        'data_nascimento',
        'nome',
        'curso',
        'campus',
        'email',
        'cod_perfil',
        'status',
        'periodo_letivo',
        'periodo',
        'turma',
    ];

    protected function casts(): array
    {
        return [
            'data_nascimento' => 'date',
        ];
    }
}
