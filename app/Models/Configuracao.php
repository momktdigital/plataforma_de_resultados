<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

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

    // Cacheia a tabela inteira (poucas dezenas de linhas) numa entrada só —
    // a tela de consulta do portal chama valor() 4+ vezes por carregamento.
    // Invalidada em definir() a cada escrita, então o TTL é só uma rede de
    // segurança caso algo grave direto na tabela.
    private const CACHE_KEY = 'configuracoes.todas';

    private const CACHE_TTL_MINUTOS = 10;

    public static function valor(string $chave, ?string $padrao = null): ?string
    {
        return static::todas()[$chave] ?? $padrao;
    }

    public static function definir(string $chave, ?string $valor): void
    {
        static::updateOrCreate(['chave' => $chave], ['valor' => $valor]);
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, string|null> */
    public static function todas(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(self::CACHE_TTL_MINUTOS),
            fn () => static::query()->pluck('valor', 'chave')->all(),
        );
    }
}
