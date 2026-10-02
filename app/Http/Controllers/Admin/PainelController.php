<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Base das telas do painel do coordenador (visão geral, alunos, desempenho, ficha do aluno): quem é o
 * coordenador e qual recorte (curso + semestre) ele escolheu na barra de filtros.
 */
abstract class PainelController extends Controller
{
    /** O painel é sobre "o meu curso" — administrador não tem curso (volta para a lista de avaliações). */
    protected function coordenador(): ?Admin
    {
        $usuario = Auth::guard('admin')->user();

        return $usuario?->ehCoordenador() ? $usuario : null;
    }

    protected function cursoEscolhido(Request $request): string
    {
        return trim((string) $request->query('curso', ''));
    }

    /** Sem o parâmetro = semestre mais recente (null); `periodo_letivo=` vazio = "Todos". */
    protected function periodoEscolhido(Request $request): ?string
    {
        return $request->has('periodo_letivo') ? (string) $request->query('periodo_letivo', '') : null;
    }

    /** @param  array<string, mixed>  $painel */
    protected function semDados(array $painel): bool
    {
        return ! empty($painel['semCurso']) || ! empty($painel['semResultados']);
    }
}
