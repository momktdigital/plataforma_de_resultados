@extends('layouts.app')

@section('title', "Resultados por aluno — Avaliação #{$avaliacao->codigo}")

@section('content')
<a href="{{ route('avaliacoes.show', $avaliacao) }}" class="text-sm text-slate-500 hover:underline">&larr; Avaliacao #{{ $avaliacao->codigo }}</a>
<h1 class="text-2xl font-bold mt-2 mb-6">Resultados por aluno</h1>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 mb-6">
    <form method="GET" action="{{ route('avaliacoes.respondentes.index', $avaliacao) }}" class="flex flex-wrap gap-3">
        <input type="text" name="search" value="{{ $search }}" placeholder="Buscar por RA ou CPF..."
               class="flex-1 min-w-[200px] rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <select name="periodo" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">Todos os períodos</option>
            @foreach ($periodosDisponiveis as $p)
                <option value="{{ $p }}" {{ $periodo === $p ? 'selected' : '' }}>{{ $p === '' ? '(sem período)' : $p }}</option>
            @endforeach
        </select>
        <button type="submit" class="bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-lg px-4 py-2 text-sm">
            Filtrar
        </button>
        @if ($search !== '' || $periodo !== '')
            <a href="{{ route('avaliacoes.respondentes.index', $avaliacao) }}" class="border border-slate-300 bg-white text-slate-700 rounded-lg px-4 py-2 text-sm hover:bg-slate-50">
                Limpar
            </a>
        @endif
    </form>
</div>

@if ($periodo !== '')
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <form method="POST" action="{{ route('avaliacoes.periodos.destroy', $avaliacao) }}"
              onsubmit="return confirm('Excluir todos os resultados do período \'{{ $periodo }}\' nesta avaliação?');">
            @csrf
            @method('DELETE')
            <input type="hidden" name="periodo" value="{{ $periodo }}">
            <button type="submit" class="text-sm text-red-600 border border-red-200 hover:bg-red-50 rounded-lg px-3 py-1.5">
                Excluir resultados do período "{{ $periodo }}"
            </button>
        </form>

        @if ($trashedNoPeriodo > 0)
            <form method="POST" action="{{ route('avaliacoes.periodos.restore', $avaliacao) }}">
                @csrf
                <input type="hidden" name="periodo" value="{{ $periodo }}">
                <button type="submit" class="text-sm text-blue-600 border border-blue-200 hover:bg-blue-50 rounded-lg px-3 py-1.5">
                    Restaurar {{ $trashedNoPeriodo }} registro(s) excluído(s) deste período
                </button>
            </form>
        @endif
    </div>
@endif

<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-500 text-left">
            <tr>
                <th class="px-4 py-3">Nome</th>
                <th class="px-4 py-3">RA</th>
                <th class="px-4 py-3">CPF</th>
                <th class="px-4 py-3">Período</th>
                <th class="px-4 py-3">Acertos</th>
                <th class="px-4 py-3">Respostas</th>
                <th class="px-4 py-3">Atualizado em</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($respondentes as $r)
                <tr>
                    <td class="px-4 py-3 font-medium">{{ $r->aluno_nome ?: '—' }}</td>
                    <td class="px-4 py-3">{{ $r->ra ?: '—' }}</td>
                    <td class="px-4 py-3">{{ $r->cpf ?: '—' }}</td>
                    <td class="px-4 py-3">{{ $r->periodo !== '' ? $r->periodo : '—' }}</td>
                    <td class="px-4 py-3">{{ $r->total !== null ? "{$r->acertos}/{$r->total}" : '—' }}</td>
                    <td class="px-4 py-3">{{ $r->total_respostas }}</td>
                    <td class="px-4 py-3 text-slate-500">{{ \Illuminate\Support\Carbon::parse($r->updated_at)->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('avaliacoes.respondentes.show', ['avaliacao' => $avaliacao, 'chave' => $r->aluno_chave, 'periodo' => $r->periodo]) }}"
                           class="text-emerald-700 font-semibold hover:underline">
                            Ver
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="px-4 py-12 text-center text-slate-400">Nenhum resultado encontrado.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $respondentes->links() }}
</div>
@endsection
