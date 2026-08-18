<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Administrador do sistema. Mapeia a tabela `admins` já existente na
 * aplicação legada — mesmas credenciais, sem duplicar cadastro de usuários.
 */
class Admin extends Authenticatable
{
    protected $table = 'admins';

    public $timestamps = false;

    protected $fillable = [
        'username',
        'password_hash',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            // $timestamps = false (sem updated_at) já impede o Eloquent de
            // castear created_at automaticamente — sem isto, a tela de
            // Administradores quebra ao chamar ->format() numa string crua.
            'created_at' => 'datetime',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /**
     * Não há coluna `remember_token` na tabela legada — desativa "lembrar-me".
     */
    public function getRememberTokenName(): string
    {
        return '';
    }
}
