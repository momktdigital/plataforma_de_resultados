<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Aluno;
use App\Models\Avaliacao;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Busca global do menu lateral — sem isso, pular direto pra um aluno ou
 * avaliação a partir de qualquer tela só dava entrando na lista específica
 * certa (e mesmo assim, só Alunos e Respondentes tinham busca local).
 */
class BuscaGlobalController extends Controller
{
    private const LIMITE_POR_TIPO = 10;

    public function index(Request $request): View
    {
        $termo = trim((string) $request->query('q', ''));

        if ($termo === '') {
            return view('admin.busca.index', ['termo' => $termo, 'alunos' => collect(), 'avaliacoes' => collect(), 'totalAlunos' => 0, 'totalAvaliacoes' => 0]);
        }

        $alunosQuery = fn () => Aluno::where(function ($query) use ($termo) {
            $query->where('nome', 'like', "%{$termo}%")
                ->orWhere('ra', 'like', "%{$termo}%")
                ->orWhere('cpf', 'like', "%{$termo}%");
        });

        $avaliacoesQuery = fn () => Avaliacao::where(function ($query) use ($termo) {
            $query->where('nome', 'like', "%{$termo}%")
                ->orWhere('tipo', 'like', "%{$termo}%")
                ->when(is_numeric($termo), fn ($query) => $query->orWhere('codigo', (int) $termo));
        });

        return view('admin.busca.index', [
            'termo' => $termo,
            'alunos' => $alunosQuery()->orderBy('nome')->limit(self::LIMITE_POR_TIPO)->get(),
            'totalAlunos' => $alunosQuery()->count(),
            'avaliacoes' => $avaliacoesQuery()->with('categoria')->latest('codigo')->limit(self::LIMITE_POR_TIPO)->get(),
            'totalAvaliacoes' => $avaliacoesQuery()->count(),
        ]);
    }
}
