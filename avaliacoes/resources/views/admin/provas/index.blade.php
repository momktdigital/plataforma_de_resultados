@extends('layouts.app')

@section('title', 'Provas — Avaliações')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold">Provas</h1>
</div>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-8">
    <h2 class="font-semibold mb-4">Nova prova</h2>
    <p class="text-sm text-slate-500 mb-4">
        O código é gerado automaticamente. Nome e tipo são apenas para facilitar a identificação — nenhum campo aqui é obrigatório.
    </p>
    <form method="POST" action="{{ route('provas.store') }}" class="flex flex-wrap gap-3 items-end">
        @csrf
        <div class="flex-1 min-w-[200px]">
            <label class="block text-sm font-medium mb-1" for="nome">Nome (opcional)</label>
            <input id="nome" name="nome" type="text" value="{{ old('nome') }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div class="flex-1 min-w-[160px]">
            <label class="block text-sm font-medium mb-1" for="tipo">Tipo (opcional)</label>
            <input id="tipo" name="tipo" type="text" value="{{ old('tipo') }}" placeholder="Ex.: Institucional, Simulado..."
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div class="flex-1 min-w-[200px]">
            <label class="block text-sm font-medium mb-1" for="link_comentado">Link do gabarito comentado (opcional)</label>
            <input id="link_comentado" name="link_comentado" type="url" value="{{ old('link_comentado') }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-5 py-2 text-sm">
            Criar prova
        </button>
    </form>
</div>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-500 text-left">
            <tr>
                <th class="px-4 py-3">Código</th>
                <th class="px-4 py-3">Nome</th>
                <th class="px-4 py-3">Tipo</th>
                <th class="px-4 py-3">Questões</th>
                <th class="px-4 py-3">Resultados</th>
                <th class="px-4 py-3">Criada em</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($provas as $prova)
                <tr>
                    <td class="px-4 py-3 font-mono">#{{ $prova->codigo }}</td>
                    <td class="px-4 py-3">{{ $prova->nome ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $prova->tipo ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $prova->questoes_count }}</td>
                    <td class="px-4 py-3">{{ $prova->resultados_count }}</td>
                    <td class="px-4 py-3 text-slate-500">{{ $prova->created_at->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('provas.show', $prova) }}" class="text-emerald-700 font-semibold hover:underline">
                            Gerenciar
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-4 py-8 text-center text-slate-400">Nenhuma prova cadastrada ainda.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $provas->links() }}
</div>
@endsection
