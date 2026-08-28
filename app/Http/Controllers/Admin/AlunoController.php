<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AlunoRequest;
use App\Models\Aluno;
use App\Support\Ordenacao;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AlunoController extends Controller
{
    private const COLUNAS_ORDENAVEIS = ['ra', 'nome', 'cpf', 'data_nascimento', 'curso', 'periodo'];

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        [$sort, $direction] = Ordenacao::resolver($request, self::COLUNAS_ORDENAVEIS, 'nome');

        $alunos = Aluno::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('ra', 'like', "%{$search}%")
                        ->orWhere('cpf', 'like', "%{$search}%")
                        ->orWhere('nome', 'like', "%{$search}%");
                });
            })
            ->orderBy($sort, $direction)
            ->when($sort !== 'ra', fn ($query) => $query->orderBy('ra'))
            ->paginate(50)
            ->withQueryString();

        return view('admin.alunos.index', ['alunos' => $alunos, 'search' => $search, 'sort' => $sort, 'direction' => $direction]);
    }

    public function create(): View
    {
        return view('admin.alunos.form', ['aluno' => new Aluno]);
    }

    public function store(AlunoRequest $request): RedirectResponse
    {
        Aluno::create($this->comDataConvertida($request));

        return redirect()->route('alunos.index')->with('status', 'Aluno salvo com sucesso.');
    }

    public function edit(Aluno $aluno): View
    {
        return view('admin.alunos.form', ['aluno' => $aluno]);
    }

    public function update(AlunoRequest $request, Aluno $aluno): RedirectResponse
    {
        $aluno->update($this->comDataConvertida($request));

        return redirect()->route('alunos.index')->with('status', 'Aluno salvo com sucesso.');
    }

    public function destroy(Aluno $aluno): RedirectResponse
    {
        $nomeOuRa = $aluno->nome ?: $aluno->ra;
        $aluno->delete();

        return redirect()->route('alunos.index')->with('status', "Aluno {$nomeOuRa} excluído com sucesso.");
    }

    /** @return array<string, mixed> */
    private function comDataConvertida(AlunoRequest $request): array
    {
        $dados = $request->validated();
        $dados['data_nascimento'] = empty($dados['data_nascimento'])
            ? null
            : Carbon::createFromFormat('d/m/Y', $dados['data_nascimento'])->format('Y-m-d');

        return $dados;
    }
}
