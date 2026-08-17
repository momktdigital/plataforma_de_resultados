<?php

namespace App\Http\Middleware;

use App\Support\InstallStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manda um deploy novo (sem banco configurado/sem administrador ainda) direto
 * para o wizard, em vez de mostrar uma tela de erro de conexão.
 */
class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! InstallStatus::instalado()) {
            return redirect()->route('instalar.inicio');
        }

        return $next($request);
    }
}
