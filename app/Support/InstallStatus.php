<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Estado da instalação, com três respostas — e a terceira é o que impede o
 * wizard (/instalar) de ser reaberto por um visitante qualquer:
 *
 *  - `instalado`: banco respondendo, tabela `admins` existe e tem pelo menos
 *    um usuário. Funciona também para um deploy que aponta para o banco
 *    compartilhado já populado pela aplicação legada. Ao confirmar isso, grava
 *    um MARCADOR em disco (ver `marcador()`).
 *  - `pendente`: instalação nova de verdade (nunca houve marcador) — só então o
 *    wizard abre.
 *  - `indisponivel`: o sistema JÁ foi instalado antes (o marcador existe), mas
 *    agora o banco não responde ou está sem administrador. É uma queda, não uma
 *    instalação nova: o wizard NÃO abre, o site responde 503. Antes, qualquer
 *    exceção contava como "não instalado", e numa falha momentânea do MySQL um
 *    visitante conseguia apontar o app para um banco dele e reescrever o `.env`.
 *
 * Para reinstalar de propósito, apague o marcador (`storage/app/instalado.lock`).
 * O caminho vem de `config('app.instalado_marcador')`; vazio desliga o marcador
 * (é o que os testes fazem para não gravar no `storage/` real).
 */
class InstallStatus
{
    public const INSTALADO = 'instalado';

    public const PENDENTE = 'pendente';

    public const INDISPONIVEL = 'indisponivel';

    /** Só memoiza "instalado": quem ainda não instalou precisa reavaliar a cada passo do wizard. */
    private static bool $instaladoMemo = false;

    public static function estado(): string
    {
        if (self::$instaladoMemo) {
            return self::INSTALADO;
        }

        try {
            $temAdmin = Schema::hasTable('admins') && DB::table('admins')->exists();
        } catch (Throwable) {
            return self::jaFoiInstalado() ? self::INDISPONIVEL : self::PENDENTE;
        }

        if ($temAdmin) {
            self::gravarMarcador();
            self::$instaladoMemo = true;

            return self::INSTALADO;
        }

        // Banco respondeu mas sem administrador: instalação nova, a menos que já tenha existido uma.
        return self::jaFoiInstalado() ? self::INDISPONIVEL : self::PENDENTE;
    }

    public static function instalado(): bool
    {
        return self::estado() === self::INSTALADO;
    }

    public static function bancoConecta(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Chamado ao fim do wizard (e na primeira confirmação de "instalado"). */
    public static function gravarMarcador(): void
    {
        $caminho = self::marcador();

        if ($caminho === null || is_file($caminho)) {
            return;
        }

        // Falhar ao gravar (disco/permissão) não pode derrubar a requisição — só deixa de proteger.
        @file_put_contents($caminho, 'instalado em '.date('c')."\n");
    }

    public static function limpar(): void
    {
        self::$instaladoMemo = false;
    }

    private static function jaFoiInstalado(): bool
    {
        $caminho = self::marcador();

        return $caminho !== null && is_file($caminho);
    }

    private static function marcador(): ?string
    {
        $caminho = config('app.instalado_marcador');

        return is_string($caminho) && $caminho !== '' ? $caminho : null;
    }
}
