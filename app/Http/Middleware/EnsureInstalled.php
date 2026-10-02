<?php

namespace App\Http\Middleware;

use App\Support\InstallStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manda um deploy NOVO (sem banco configurado/sem administrador ainda) direto
 * para o wizard, em vez de mostrar uma tela de erro de conexão. Um sistema que
 * já esteve instalado e cujo banco caiu recebe 503 — nunca o wizard (ver InstallStatus).
 */
class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        switch (InstallStatus::estado()) {
            case InstallStatus::PENDENTE:
                return redirect()->route('instalar.inicio');
            case InstallStatus::INDISPONIVEL:
                // Já foi instalado antes e o banco não responde: é uma queda, nunca um convite ao wizard.
                abort(503, 'Sistema temporariamente indisponível. Tente novamente em instantes.');
        }

        return $next($request);
    }
}
