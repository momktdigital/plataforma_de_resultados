<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido de redefinição de senha de admin pendente — ver
 * App\Services\Auth\PasswordResetService, que gera e consome estes tokens.
 */
class RedefinicaoSenha extends Model
{
    protected $table = 'redefinicoes_senha';

    const CREATED_AT = 'criado_em';

    const UPDATED_AT = null;

    protected $fillable = [
        'admin_id',
        'token_hash',
        'expira_em',
    ];

    protected function casts(): array
    {
        return [
            'expira_em' => 'datetime',
            'criado_em' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
