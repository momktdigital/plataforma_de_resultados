<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um registro de acompanhamento de aluno pelo coordenador (ver a migration `create_acompanhamentos_table`). Só se
 * acrescenta: o estado atual é o último registro e o histórico nunca é reescrito.
 */
class Acompanhamento extends Model
{
    protected $table = 'acompanhamentos';

    public const UPDATED_AT = null;

    public const CONTATADO = 'contatado';

    public const EM_ACOMPANHAMENTO = 'em_acompanhamento';

    public const RESOLVIDO = 'resolvido';

    /** @var array<string, string> */
    public const STATUS = [
        self::CONTATADO => 'Contatado',
        self::EM_ACOMPANHAMENTO => 'Em acompanhamento',
        self::RESOLVIDO => 'Resolvido',
    ];

    protected $fillable = ['aluno_id', 'admin_id', 'curso', 'status', 'observacao'];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function rotulo(): string
    {
        return self::STATUS[$this->status] ?? $this->status;
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
