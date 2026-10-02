<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Falha fechada para o perfil: se `admins.role` guarda um valor que não é nem administrador nem
 * coordenador (Admin::papel() === null), a sessão é encerrada. Sem isso, a maior parte do sistema
 * trataria "não é coordenador" como "é administrador". Roda depois de `auth:admin`.
 */
class PapelValido
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = Auth::guard('admin')->user();

        if ($usuario !== null && ! $usuario->temPapelValido()) {
            Auth::guard('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'username' => 'Esta conta está sem um perfil de acesso válido. Procure um administrador.',
            ]);
        }

        return $next($request);
    }
}
