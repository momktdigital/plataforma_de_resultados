@extends('layouts.app')

@section('title', "Busca: {$termo} — Avaliações")

@section('content')
<h1 class="text-2xl font-bold mb-6">Busca</h1>

<form method="GET" action="{{ route('busca.index') }}" class="mb-8 max-w-xl">
    <div class="flex gap-3">
        <input type="text" name="q" value="{{ $termo }}" autofocus placeholder="Nome, RA, CPF, código ou tipo de avaliação..."
               class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <button type="submit" class="bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-lg px-4 py-2 text-sm">
            Buscar
        </button>
    </div>
</form>

@if ($termo === '')
    <p class="text-sm text-slate-400">Digite um nome, RA, CPF, código ou tipo de avaliação para buscar.</p>
@else
    <div class="grid md:grid-cols-2 gap-6">
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
            <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
                <h2 class="font-semibold">Alunos</h2>
                <span class="text-xs text-slate-400">{{ $totalAlunos }} resultado(s)</span>
            </div>
            @if ($alunos->isEmpty())
                <p class="px-4 py-6 text-sm text-slate-400">Nenhum aluno encontrado.</p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($alunos as $aluno)
                        <li class="px-4 py-3 text-sm">
                            <a href="{{ route('alunos.edit', $aluno) }}" class="font-medium text-emerald-700 hover:underline">
                                {{ $aluno->nome ?: '(sem nome)' }}
                            </a>
                            <span class="text-slate-400">— RA {{ $aluno->ra }}</span>
                        </li>
                    @endforeach
                </ul>
                @if ($totalAlunos > $alunos->count())
                    <div class="px-4 py-3 border-t border-slate-100">
                        <a href="{{ route('alunos.index', ['search' => $termo]) }}" class="text-xs text-emerald-700 hover:underline">
                            Ver todos os {{ $totalAlunos }} resultados de alunos &rarr;
                        </a>
                    </div>
                @endif
            @endif
        </div>

        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
            <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
                <h2 class="font-semibold">Avaliações</h2>
                <span class="text-xs text-slate-400">{{ $totalAvaliacoes }} resultado(s)</span>
            </div>
            @if ($avaliacoes->isEmpty())
                <p class="px-4 py-6 text-sm text-slate-400">Nenhuma avaliação encontrada.</p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($avaliacoes as $avaliacao)
                        <li class="px-4 py-3 text-sm">
                            <a href="{{ route('avaliacoes.show', $avaliacao) }}" class="font-medium text-emerald-700 hover:underline">
                                #{{ $avaliacao->codigo }} — {{ $avaliacao->nome ?: '(sem nome)' }}
                            </a>
                            @if ($avaliacao->categoria)
                                <span class="text-slate-400">— {{ $avaliacao->categoria->nome }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if ($totalAvaliacoes > $avaliacoes->count())
                    <div class="px-4 py-3 border-t border-slate-100">
                        <a href="{{ route('avaliacoes.index', ['search' => $termo]) }}" class="text-xs text-emerald-700 hover:underline">
                            Ver todos os {{ $totalAvaliacoes }} resultados de avaliações &rarr;
                        </a>
                    </div>
                @endif
            @endif
        </div>
    </div>
@endif
@endsection
