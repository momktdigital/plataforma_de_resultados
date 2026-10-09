<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma ação pedagógica de um plano (ver PlanoAcao): o que será feito, como, por quem, até quando e como se verifica.
 */
class PlanoAcaoAcao extends Model
{
    protected $table = 'plano_acao_acoes';

    public const NAO_INICIADA = 'nao_iniciada';

    public const EM_ANDAMENTO = 'em_andamento';

    public const CONCLUIDA = 'concluida';

    public const CANCELADA = 'cancelada';

    /** @var array<string, string> */
    public const STATUS = [
        self::NAO_INICIADA => 'Não iniciada',
        self::EM_ANDAMENTO => 'Em andamento',
        self::CONCLUIDA => 'Concluída',
        self::CANCELADA => 'Cancelada',
    ];

    protected $fillable = ['plano_id', 'ordem', 'descricao', 'execucao', 'responsavel', 'prazo', 'verificacao', 'status', 'concluida_em'];

    protected function casts(): array
    {
        return ['prazo' => 'date', 'concluida_em' => 'datetime'];
    }

    /** @return BelongsTo<PlanoAcao, $this> */
    public function plano(): BelongsTo
    {
        return $this->belongsTo(PlanoAcao::class, 'plano_id');
    }

    public function rotuloStatus(): string
    {
        return self::STATUS[$this->status] ?? (string) $this->status;
    }

    /** Ainda aberta (nem concluída nem cancelada). */
    public function estaAberta(): bool
    {
        return in_array($this->status, [self::NAO_INICIADA, self::EM_ANDAMENTO], true);
    }

    /** Aberta e com o prazo já vencido. */
    public function estaAtrasada(): bool
    {
        return $this->estaAberta() && $this->prazo !== null && $this->prazo->isBefore(today());
    }

    /** Dias até o prazo (negativo = já venceu); null sem prazo. */
    public function diasParaOPrazo(): ?int
    {
        return $this->prazo === null ? null : (int) today()->diffInDays($this->prazo, false);
    }
}
