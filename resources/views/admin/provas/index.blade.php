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
        <div class="flex-1 min-w-[160px]">
            <label class="block text-sm font-medium mb-1" for="categoria_id">Categoria (opcional)</label>
            <select id="categoria_id" name="categoria_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="">— Sem categoria —</option>
                @foreach ($opcoesCategoria as $opcao)
                    <option value="{{ $opcao['id'] }}" {{ (int) old('categoria_id') === $opcao['id'] ? 'selected' : '' }}>
                        {{ $opcao['label'] }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="min-w-[140px]">
            <label class="block text-sm font-medium mb-1" for="data_prova">Data da prova (opcional)</label>
            <input id="data_prova" name="data_prova" type="text" placeholder="DD/MM/AAAA" value="{{ old('data_prova') }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-5 py-2 text-sm">
            Criar prova
        </button>
    </form>
</div>

<script src="https://unpkg.com/imask"></script>
<script>
    IMask(document.getElementById('data_prova'), {
        mask: Date,
        pattern: 'd/m/Y',
        blocks: {
            d: {mask: IMask.MaskedRange, from: 1, to: 31, maxLength: 2},
            m: {mask: IMask.MaskedRange, from: 1, to: 12, maxLength: 2},
            Y: {mask: IMask.MaskedRange, from: 1900, to: 2999},
        },
        format: function (date) {
            var day = String(date.getDate()).padStart(2, '0');
            var month = String(date.getMonth() + 1).padStart(2, '0');
            return [day, month, date.getFullYear()].join('/');
        },
        parse: function (str) {
            var partes = str.split('/');
            return new Date(partes[2], partes[1] - 1, partes[0]);
        },
    });
</script>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-500 text-left">
            <tr>
                <th class="px-4 py-3">Código</th>
                <th class="px-4 py-3">Nome</th>
                <th class="px-4 py-3">Categoria</th>
                <th class="px-4 py-3">Data da prova</th>
                <th class="px-4 py-3">Questões</th>
                <th class="px-4 py-3">Resultados</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($provas as $prova)
                <tr>
                    <td class="px-4 py-3 font-mono">#{{ $prova->codigo }}</td>
                    <td class="px-4 py-3">{{ $prova->nome ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $prova->categoria?->nome ?? '—' }}</td>
                    <td class="px-4 py-3 text-slate-500">{{ $prova->data_prova?->format('d/m/Y') ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $prova->questoes_count }}</td>
                    <td class="px-4 py-3">{{ $prova->resultados_count }}</td>
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
