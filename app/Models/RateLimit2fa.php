<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bloqueio por IP após tentativas malsucedidas de 2FA no portal público —
 * tabela `rate_limit_2fa`, compartilhada com a aplicação legada.
 */
class RateLimit2fa extends Model
{
    protected $table = 'rate_limit_2fa';

    const CREATED_AT = null;

    const UPDATED_AT = null;

    protected $fillable = [
        'ip_address',
        'tentativas',
        'bloqueado_ate',
        'ultima_tentativa',
    ];

    protected function casts(): array
    {
        return [
            'bloqueado_ate' => 'datetime',
            'ultima_tentativa' => 'datetime',
        ];
    }
}
