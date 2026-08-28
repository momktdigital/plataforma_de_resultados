<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

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

    // Chaves que saem em texto puro pra fora deste model gravam criptografadas
    // (Crypt, com a APP_KEY) — a tabela `configuracoes` é compartilhada com o
    // sistema legado, então qualquer acesso direto ao banco (ou um SQLi em
    // outro lugar do sistema) não deveria expor essas três em texto puro.
    private const CHAVES_SENSIVEIS = [
        'recaptcha_secret_key',
        'hcaptcha_secret_key',
        'smtp_pass',
    ];

    public static function valor(string $chave, ?string $padrao = null): ?string
    {
        return static::todas()[$chave] ?? $padrao;
    }

    public static function definir(string $chave, ?string $valor): void
    {
        if ($valor !== null && in_array($chave, self::CHAVES_SENSIVEIS, true)) {
            $valor = Crypt::encryptString($valor);
        }

        static::updateOrCreate(['chave' => $chave], ['valor' => $valor]);
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, string|null> */
    public static function todas(): array
    {
        $config = Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(self::CACHE_TTL_MINUTOS),
            fn () => static::query()->pluck('valor', 'chave')->all(),
        );

        foreach (self::CHAVES_SENSIVEIS as $chave) {
            if (! empty($config[$chave])) {
                $config[$chave] = self::descriptografarComFallback($config[$chave]);
            }
        }

        return $config;
    }

    // Registros gravados antes desta mudança ainda estão em texto puro — usa
    // o valor como está nesse caso (a próxima gravação passa a criptografar).
    private static function descriptografarComFallback(string $valor): string
    {
        try {
            return Crypt::decryptString($valor);
        } catch (DecryptException) {
            return $valor;
        }
    }
}
