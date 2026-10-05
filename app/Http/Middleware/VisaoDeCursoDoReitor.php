<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Ver como coordenador": quando o REITOR escolheu um curso (sessão `visao_de_curso`), troca, só nesta requisição, o
 * usuário autenticado por uma cópia com perfil de coordenador daquele curso (Admin::comoCoordenadorDe). Assim as telas do
 * coordenador (painel, alunos, desempenho, comparar semestres, avaliações e Dashboard do curso) funcionam para ele sem
 * duplicar controller nem consulta — e com as MESMAS restrições do coordenador: só o curso escolhido, só leitura.
 *
 * Só vale para o reitor (administrador já enxerga tudo e perderia as telas de gestão) e só nas rotas em que este
 * middleware é aplicado (o grupo de rotas do coordenador): o painel da reitoria, o perfil e o logout seguem com o
 * usuário de verdade. Roda depois de `auth:admin` e antes de `perfil:`.
 */
class VisaoDeCursoDoReitor
{
    public const SESSAO = 'visao_de_curso';

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = Auth::guard('admin')->user();
        $curso = $request->session()->get(self::SESSAO);

        if ($usuario?->ehReitor() && is_string($curso) && $curso !== '') {
            Auth::guard('admin')->setUser($usuario->comoCoordenadorDe([$curso]));
        }

        return $next($request);
    }
}
