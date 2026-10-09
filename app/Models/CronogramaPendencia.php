<?php

namespace App\Models;

use App\Support\NomeCurso;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pendência registrada pelo colaborador numa atividade do cronograma, para um curso (aba "Registro de Pendências" da
 * planilha). Serve de histórico e acompanhamento: o coordenador do curso só lê.
 */
class CronogramaPendencia extends Model
{
    protected $table = 'cronograma_pendencias';

    /** @var array<string, string> */
    public const STATUS = [
        CronogramaItem::PENDENTE => 'Pendente',
        CronogramaItem::EM_ACOMPANHAMENTO => 'Em acompanhamento',
        CronogramaItem::RESOLVIDO => 'Resolvido',
    ];

    protected $fillable = ['item_id', 'curso', 'data', 'pendencia', 'encaminhamento', 'prazo', 'status', 'responsavel', 'registrado_por', 'resolvida_em'];

    protected function casts(): array
    {
        return ['data' => 'date', 'prazo' => 'date', 'resolvida_em' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(CronogramaItem::class, 'item_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'registrado_por');
    }

    /** Só as pendências dos cursos informados (o recorte do coordenador). */
    public function scopeDosCursos(Builder $query, array $cursos): Builder
    {
        return $query->whereIn('curso', NomeCurso::variantes($cursos));
    }

    public function scopeAbertas(Builder $query): Builder
    {
        return $query->where('status', '!=', CronogramaItem::RESOLVIDO);
    }

    public function estaResolvida(): bool
    {
        return $this->status === CronogramaItem::RESOLVIDO;
    }

    /** Aberta e com o prazo já vencido. */
    public function estaAtrasada(): bool
    {
        return ! $this->estaResolvida() && $this->prazo !== null && $this->prazo->isBefore(today());
    }

    public function rotulo(): string
    {
        return self::STATUS[$this->status] ?? $this->status;
    }
}
