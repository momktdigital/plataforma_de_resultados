<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Configurações operacionais deste módulo (ex.: repositório do atualizador,
 * quantos backups manter) — chave/valor, editáveis pelo admin em
 * "Configurações" sem precisar de acesso ao servidor. Um valor não definido
 * aqui cai no padrão de `config/sistema.php` (variáveis de ambiente).
 */
class ConfiguracaoSistema extends Model
{
    protected $table = 'configuracoes_sistema';

    protected $fillable = ['chave', 'valor'];

    public static function valor(string $chave, ?string $padrao = null): ?string
    {
        try {
            return static::query()->where('chave', $chave)->value('valor') ?? $padrao;
        } catch (Throwable) {
            // Tabela pode não existir ainda (ex.: antes da primeira migração).
            return $padrao;
        }
    }

    public static function definir(string $chave, ?string $valor): void
    {
        static::updateOrCreate(['chave' => $chave], ['valor' => $valor]);
    }
}
