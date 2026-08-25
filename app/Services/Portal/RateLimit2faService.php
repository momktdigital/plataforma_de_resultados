<?php

namespace App\Services\Portal;

use App\Models\RateLimit2fa;
use Illuminate\Support\Carbon;

/**
 * Bloqueio por IP após tentativas malsucedidas de 2FA — equivalente a
 * includes/rate_limit_helper.php. Reescrito com Eloquent (em vez do
 * `ON DUPLICATE KEY UPDATE ... IF(...)` específico de MySQL do legado) para
 * funcionar também em SQLite (usado pelos testes).
 */
class RateLimit2faService
{
    private const MAX_TENTATIVAS = 10;

    private const BLOQUEIO_MINUTOS = 60;

    public function estaBloqueado(string $ip): bool
    {
        $registro = RateLimit2fa::where('ip_address', $ip)->first();

        return $registro !== null
            && $registro->bloqueado_ate !== null
            && $registro->bloqueado_ate->isFuture();
    }

    public function registrarFalha(string $ip): void
    {
        $registro = RateLimit2fa::firstOrCreate(
            ['ip_address' => $ip],
            ['tentativas' => 0, 'ultima_tentativa' => Carbon::now()]
        );

        $registro->tentativas++;
        $registro->ultima_tentativa = Carbon::now();

        if ($registro->tentativas >= self::MAX_TENTATIVAS) {
            $registro->bloqueado_ate = Carbon::now()->addMinutes(self::BLOQUEIO_MINUTOS);
        }

        $registro->save();
    }

    public function resetar(string $ip): void
    {
        RateLimit2fa::where('ip_address', $ip)->delete();
    }
}
