<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Código de 2FA por e-mail emitido no portal público — tabela
 * `verificacoes_email`, compartilhada com a aplicação legada.
 */
class VerificacaoEmail extends Model
{
    protected $table = 'verificacoes_email';

    const CREATED_AT = 'criado_em';

    const UPDATED_AT = null;

    protected $fillable = [
        'cpf',
        'codigo',
        'tentativas_falhas',
        'vezes_reenviado',
        'ultimo_reenvio',
        'expira_em',
    ];

    protected function casts(): array
    {
        return [
            'expira_em' => 'datetime',
            'ultimo_reenvio' => 'datetime',
            'criado_em' => 'datetime',
        ];
    }
}
