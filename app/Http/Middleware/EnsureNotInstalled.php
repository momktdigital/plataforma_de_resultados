<?php

namespace App\Http\Middleware;

use App\Support\InstallStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege as rotas do wizard (/instalar): depois que o sistema já tem um
 * administrador cadastrado, o wizard fica bloqueado — não é uma tela pra
 * ficar acessível num site em produção.
 */
class EnsureNotInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        switch (InstallStatus::estado()) {
            case InstallStatus::INSTALADO:
                return redirect()->route('login');
            case InstallStatus::INDISPONIVEL:
                abort(503, 'Sistema temporariamente indisponível. Tente novamente em instantes.');
        }

        return $next($request);
    }
}
