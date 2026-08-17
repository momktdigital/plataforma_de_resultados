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
        if (InstallStatus::instalado()) {
            return redirect()->route('login');
        }

        return $next($request);
    }
}
