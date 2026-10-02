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

    /**
     * O código de 6 dígitos NUNCA fica em texto puro no banco (só 10^6 combinações: quem lesse a tabela
     * entraria em qualquer conta com código pendente). Guarda-se um HMAC com a chave da aplicação, amarrado ao
     * CPF — um dump do banco, sozinho, não revela o código.
     */
    public static function hashDoCodigo(string $cpf, string $codigo): string
    {
        return hash_hmac('sha256', $cpf.'|'.$codigo, (string) config('app.key'));
    }

    protected function casts(): array
    {
        return [
            'expira_em' => 'datetime',
            'ultimo_reenvio' => 'datetime',
            'criado_em' => 'datetime',
        ];
    }
}
