<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aviso para um coordenador (ele marca como lido). Gerado por NotificacaoCoordenadorService.
 */
class Notificacao extends Model
{
    protected $table = 'notificacoes';

    protected $fillable = ['admin_id', 'tipo', 'titulo', 'texto', 'url', 'chave', 'lida_em'];

    protected function casts(): array
    {
        return ['lida_em' => 'datetime'];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function scopeDoUsuario(Builder $query, int $adminId): Builder
    {
        return $query->where('admin_id', $adminId);
    }

    public function scopeNaoLidas(Builder $query): Builder
    {
        return $query->whereNull('lida_em');
    }

    public function estaLida(): bool
    {
        return $this->lida_em !== null;
    }

    /** Aparência na lista: ícone (Phosphor) e tom (positivo | atencao | neutro). */
    public function aparencia(): array
    {
        return match ($this->tipo) {
            'resultados' => ['icone' => 'ph-exam', 'tom' => 'positivo'],
            'atencao' => ['icone' => 'ph-warning-circle', 'tom' => 'atencao'],
            'presenca' => ['icone' => 'ph-user-minus', 'tom' => 'atencao'],
            'queda' => ['icone' => 'ph-trend-down', 'tom' => 'atencao'],
            default => ['icone' => 'ph-bell', 'tom' => 'neutro'],
        };
    }
}
