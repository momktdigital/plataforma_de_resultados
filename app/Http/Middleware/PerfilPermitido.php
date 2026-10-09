<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lista POSITIVA de perfis que passam: `perfil:administrador,coordenador`. Quem não está na lista é barrado —
 * o reitor e o colaborador são redirecionados para a tela inicial deles (as telas de curso/avaliação não são deles), qualquer outro recebe
 * 403. Nunca deduza "então é administrador" de "não é coordenador" (ver CLAUDE.md): é por isso que as rotas que o
 * coordenador alcança listam os perfis explicitamente. Roda depois de `auth:admin` e `papel-valido`.
 */
class PerfilPermitido
{
    private const PERFIS = [
        'administrador' => Admin::ROLE_ADMIN,
        'coordenador' => Admin::ROLE_COORDENADOR,
        'reitor' => Admin::ROLE_REITOR,
        'colaborador' => Admin::ROLE_COLABORADOR,
    ];

    public function handle(Request $request, Closure $next, string ...$perfis): Response
    {
        $usuario = Auth::guard('admin')->user();
        $permitidos = array_filter(array_map(fn (string $p) => self::PERFIS[$p] ?? null, $perfis));

        if ($usuario !== null && in_array($usuario->papel(), $permitidos, true)) {
            return $next($request);
        }

        if ($usuario?->ehReitor() && $request->isMethod('GET')) {
            return redirect()->route('reitor.visao');
        }

        if ($usuario?->ehColaborador() && $request->isMethod('GET')) {
            return redirect()->route('colaborador.index');
        }

        abort(403, 'Seu perfil não tem acesso a esta área.');
    }
}
