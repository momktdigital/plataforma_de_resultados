<?php

namespace App\Http\Middleware;

use App\Support\PlanoAcaoModulo;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Barra as rotas do Plano de ação quando o administrador desligou o módulo (ou o liberou só para outros usuários, em teste):
 * 404, como se ele não existisse — não confirma nem que a tela existe. Roda depois de `auth:admin` (e de `visao-de-curso`, que
 * troca o usuário da requisição por uma cópia do mesmo registro, com o mesmo id).
 */
class PlanoAcaoLiberado
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(PlanoAcaoModulo::liberadoPara(Auth::guard('admin')->user()), 404);

        return $next($request);
    }
}
