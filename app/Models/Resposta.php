<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Resposta de um respondente (identificado por CPF ou RA) a uma questão de
 * uma avaliação, num período letivo. `aluno_chave` é uma coluna gerada pelo banco
 * (COALESCE(cpf, ra)) e nunca deve ser atribuída manualmente.
 *
 * Tabela `respostas` (não `resultados`) de propósito: a aplicação legada já
 * tem uma tabela `resultados` no mesmo banco compartilhado.
 */
class Resposta extends Model
{
    use SoftDeletes;

    /**
     * Sentinelas de "sem resposta real" que já chegam assim na planilha
     * importada (ver ResultadoImportService/LegadoImportador — nenhum dos
     * dois normaliza isso, só faz mb_strtoupper) — não são NULL nem ''.
     * "BLANK" = aluno deixou aquela questão em branco; "-" = aluno nem fez
     * a prova (toda questão dele vem assim). Nenhuma das duas é uma
     * alternativa de verdade: nunca deve contar como "distrator" (ver
     * RelatorioAdminService::analiseAlternativas()) nem como erro (ver
     * EstatisticaErroService).
     */
    public const SENTINELAS_SEM_RESPOSTA = ['BLANK', '-'];

    protected $table = 'respostas';

    protected $fillable = [
        'avaliacao_codigo',
        'aluno_id',
        'ra',
        'cpf',
        'periodo',
        'questao_numero',
        'resposta',
    ];

    protected function casts(): array
    {
        return [
            'questao_numero' => 'integer',
        ];
    }

    public function avaliacao(): BelongsTo
    {
        return $this->belongsTo(Avaliacao::class, 'avaliacao_codigo', 'codigo');
    }

    public function aluno(): BelongsTo
    {
        return $this->belongsTo(Aluno::class);
    }

    public static function ehSemResposta(?string $valor): bool
    {
        return $valor === null || $valor === '' || in_array($valor, self::SENTINELAS_SEM_RESPOSTA, true);
    }

    /** Equivalente SQL de ehSemResposta(), pra usar dentro de um CASE WHEN. */
    public static function semRespostaSql(string $coluna): string
    {
        $lista = collect(self::SENTINELAS_SEM_RESPOSTA)->map(fn ($v) => "'{$v}'")->implode(',');

        return "({$coluna} IS NULL OR {$coluna} = '' OR {$coluna} IN ({$lista}))";
    }
}
