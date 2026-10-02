<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Barra o coordenador (Admin::ehCoordenador()) em tudo que é gestão do
 * sistema. As rotas que o coordenador pode usar (painel, lista de avaliações,
 * BI, perfil) ficam FORA do grupo que aplica este middleware — ver
 * routes/web.php. Roda depois de `auth:admin`, então o usuário sempre existe.
 */
class SomenteAdministrador
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('admin')->user()?->ehCoordenador()) {
            abort(403, 'Seu perfil de coordenador não tem acesso a esta área.');
        }

        return $next($request);
    }
}
