@extends('layouts.app')

@section('title', 'Alunos — Avaliações')

@section('content')
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
    <h1 class="text-2xl font-bold">Alunos</h1>
    <div class="flex gap-2">
        <a href="{{ route('alunos.importar') }}" class="border border-slate-300 bg-white text-slate-700 font-semibold rounded-lg px-4 py-2 text-sm hover:bg-slate-50">
            Importar matrícula
        </a>
        <a href="{{ route('alunos.create') }}" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-4 py-2 text-sm">
            Novo aluno
        </a>
    </div>
</div>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 mb-6">
    <form method="GET" action="{{ route('alunos.index') }}" class="flex gap-3">
        <label for="busca-alunos" class="sr-only">Buscar aluno por nome, RA ou CPF</label>
        <input id="busca-alunos" type="text" name="search" value="{{ $search }}" placeholder="Buscar por Nome, RA ou CPF..."
               class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <button type="submit" class="bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-lg px-4 py-2 text-sm">
            Buscar
        </button>
        @if ($search !== '')
            <a href="{{ route('alunos.index') }}" class="border border-slate-300 bg-white text-slate-700 rounded-lg px-4 py-2 text-sm hover:bg-slate-50">
                Limpar
            </a>
        @endif
    </form>
</div>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-500 text-left">
            <tr>
                <th class="px-4 py-3">RA</th>
                <th class="px-4 py-3">Nome</th>
                <th class="px-4 py-3">CPF</th>
                <th class="px-4 py-3">Nascimento</th>
                <th class="px-4 py-3">Curso</th>
                <th class="px-4 py-3">Período</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($alunos as $aluno)
                <tr>
                    <td class="px-4 py-3 font-medium">{{ $aluno->ra }}</td>
                    <td class="px-4 py-3">{{ $aluno->nome ?: '—' }}</td>
                    <td class="px-4 py-3">
                        @php($cpf = $aluno->cpf)
                        {{ $cpf && strlen($cpf) === 11 ? substr($cpf, 0, 3).'.'.substr($cpf, 3, 3).'.'.substr($cpf, 6, 3).'-'.substr($cpf, 9, 2) : ($cpf ?: '—') }}
                    </td>
                    <td class="px-4 py-3">{{ $aluno->data_nascimento?->format('d/m/Y') ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $aluno->curso ?: '—' }}</td>
                    <td class="px-4 py-3">{{ $aluno->periodo ?: '—' }}</td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <a href="{{ route('alunos.edit', $aluno) }}" class="text-blue-600 hover:underline mr-3">Editar</a>
                        <form method="POST" action="{{ route('alunos.destroy', $aluno) }}" class="inline-block"
                              onsubmit="return confirm('Tem certeza que deseja excluir o aluno {{ $aluno->nome ?: $aluno->ra }}? Isso não remove os resultados dele, apenas o cadastro de acesso.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-red-500 hover:text-red-700">Excluir</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-4 py-12 text-center text-slate-400">
                        Nenhum aluno encontrado. Importe uma planilha de matrícula ou cadastre manualmente.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $alunos->links() }}
</div>
@endsection
