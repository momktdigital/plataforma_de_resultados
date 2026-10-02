<?php

namespace App\Models;

use App\Support\NomeCurso;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Nomes de curso vistos na importação de matrícula de alunos — só para
 * alimentar filtros/telas de referência, não há regra de negócio aqui.
 */
class Curso extends Model
{
    protected $fillable = [
        'nome',
    ];

    /**
     * Nomes de curso selecionáveis (vínculo de coordenador, curso da
     * avaliação): os vistos na importação de matrícula somados aos que de
     * fato existem em `alunos.curso`, sem repetição (grafias que só diferem em
     * acento/caixa são o mesmo curso — ver NomeCurso), em ordem alfabética.
     *
     * @return array<int, string>
     */
    public static function nomesDisponiveis(): array
    {
        return NomeCurso::unicos(
            // Duas consultas (nunca UNION): `alunos` é legado e tem collation
            // diferente das tabelas novas — o MySQL recusa o UNION.
            static::query()->pluck('nome')
                ->merge(DB::table('alunos')->whereNotNull('curso')->where('curso', '!=', '')->distinct()->pluck('curso'))
                ->merge(DB::table('aluno_matriculas')->whereNotNull('curso')->where('curso', '!=', '')->distinct()->pluck('curso'))
        );
    }
}
