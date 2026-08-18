<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configurações chave/valor genéricas do portal público (CAPTCHA, SMTP,
 * template do e-mail de 2FA, título/logo do site) — tabela `configuracoes`,
 * cujo schema foi herdado da aplicação legada. Não confundir com
 * `ConfiguracaoSistema` (`configuracoes_sistema`), que é exclusiva deste app.
 */
class Configuracao extends Model
{
    protected $table = 'configuracoes';

    public $timestamps = false;

    protected $fillable = ['chave', 'valor', 'descricao'];

    public static function valor(string $chave, ?string $padrao = null): ?string
    {
        return static::query()->where('chave', $chave)->value('valor') ?? $padrao;
    }

    public static function definir(string $chave, ?string $valor): void
    {
        static::updateOrCreate(['chave' => $chave], ['valor' => $valor]);
    }

    /** @return array<string, string|null> */
    public static function todas(): array
    {
        return static::query()->pluck('valor', 'chave')->all();
    }
}
