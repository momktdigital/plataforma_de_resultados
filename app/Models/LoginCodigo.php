<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Código de acesso por e-mail do login de coordenador pendente — ver
 * App\Services\Auth\LoginPorCodigoService. Guarda só o hash do código.
 */
class LoginCodigo extends Model
{
    protected $table = 'login_codigos';

    const CREATED_AT = 'criado_em';

    const UPDATED_AT = null;

    protected $fillable = [
        'admin_id', 'codigo_hash', 'expira_em', 'tentativas_falhas', 'vezes_reenviado', 'ultimo_reenvio',
    ];

    protected function casts(): array
    {
        return [
            'expira_em' => 'datetime',
            'ultimo_reenvio' => 'datetime',
            'criado_em' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
