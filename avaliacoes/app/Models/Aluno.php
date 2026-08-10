<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cadastro de alunos, mantido pela aplicação legada. Usado aqui somente para
 * resolver CPF/RA no import de resultados — este módulo não cria nem edita
 * matrículas.
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
    ];

    protected function casts(): array
    {
        return [
            'data_nascimento' => 'date',
        ];
    }
}
