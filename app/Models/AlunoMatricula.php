<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma matrícula do aluno num curso, num período letivo — o histórico que
 * `alunos` (só a matrícula atual) não guarda. Preenchida pela importação de
 * matrícula (MatriculaImportService); nunca apagada por uma nova importação.
 *
 * Chave natural: aluno + curso + período letivo. Um aluno transferido de
 * curso no meio do semestre tem DUAS linhas no mesmo período letivo (a do
 * curso antigo, com status de saída e data de ocorrência, e a do novo).
 */
class AlunoMatricula extends Model
{
    protected $table = 'aluno_matriculas';

    protected $fillable = [
        'aluno_id', 'curso', 'matriz', 'periodo', 'turma', 'periodo_letivo', 'status', 'data_inicio', 'data_fim',
    ];

    protected function casts(): array
    {
        return [
            'data_inicio' => 'date',
            'data_fim' => 'date',
        ];
    }

    public function aluno(): BelongsTo
    {
        return $this->belongsTo(Aluno::class);
    }

    /** Sem coluna de status na planilha, a matrícula conta como ativa. */
    public static function estaAtiva(?string $status): bool
    {
        return $status === null || trim($status) === '' || mb_strtoupper(trim($status), 'UTF-8') === 'ATIVA';
    }
}
