<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\VisaoDeCursoDoReitor;
use App\Models\Curso;
use App\Support\AtividadeLogger;
use App\Support\NomeCurso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * O reitor abre a visão do COORDENADOR de um curso (para analisar um curso a fundo) e volta para o painel da reitoria.
 * Escolher o curso só grava o nome na sessão; quem aplica a visão é o middleware VisaoDeCursoDoReitor. A abertura fica na
 * auditoria — essa visão traz dado de alunos do curso (nome, RA, notas), ao contrário do painel agregado.
 */
class ReitorCursoController extends Controller
{
    public function abrir(Request $request): RedirectResponse
    {
        $chave = NomeCurso::chave((string) $request->query('curso', ''));
        $nome = collect(Curso::nomesDisponiveis())->first(fn ($n) => NomeCurso::chave($n) === $chave);
        abort_if($chave === '' || $nome === null, 404);

        $request->session()->put(VisaoDeCursoDoReitor::SESSAO, $nome);
        AtividadeLogger::registrar('reitor.visao_de_curso', 'Admin', Auth::guard('admin')->id(), ['curso' => $nome]);

        return redirect()->route('coordenador.painel');
    }

    public function sair(Request $request): RedirectResponse
    {
        $request->session()->forget(VisaoDeCursoDoReitor::SESSAO);

        return redirect()->route('reitor.visao');
    }
}
