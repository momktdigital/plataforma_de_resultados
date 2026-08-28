@extends('layouts.app')

@section('title', 'Avaliações')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold">Avaliações</h1>
</div>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-8">
    <h2 class="font-semibold mb-4">Nova avaliação</h2>
    <p class="text-sm text-slate-500 mb-4">
        O código é gerado automaticamente. Nome e tipo são apenas para facilitar a identificação — nenhum campo aqui é obrigatório.
    </p>
    <form method="POST" action="{{ route('avaliacoes.store') }}" enctype="multipart/form-data" class="flex flex-wrap gap-3 items-end">
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
        <div class="flex-1 min-w-[200px]">
            <label class="block text-sm font-medium mb-1" for="gabarito_comentado_arquivo">Ou envie o arquivo (opcional)</label>
            <input id="gabarito_comentado_arquivo" name="gabarito_comentado_arquivo" type="file" accept=".pdf,.doc,.docx"
                   class="w-full text-sm">
            @error('gabarito_comentado_arquivo')
                <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
            @enderror
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
            <label class="block text-sm font-medium mb-1" for="data_avaliacao">Data da avaliação (opcional)</label>
            <input id="data_avaliacao" name="data_avaliacao" type="text" placeholder="DD/MM/AAAA" value="{{ old('data_avaliacao') }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-5 py-2 text-sm">
            Criar avaliação
        </button>
    </form>
</div>

<script src="https://unpkg.com/imask"></script>
<script>
    IMask(document.getElementById('data_avaliacao'), {
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

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 mb-6">
    <form method="GET" action="{{ route('avaliacoes.index') }}" class="flex flex-wrap gap-3">
        <label for="busca-avaliacoes" class="sr-only">Buscar avaliação por código, nome ou tipo</label>
        <input id="busca-avaliacoes" type="text" name="search" value="{{ $search }}" placeholder="Buscar por código, nome ou tipo..."
               class="flex-1 min-w-[200px] rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <label for="filtro-categoria" class="sr-only">Filtrar por categoria</label>
        <select id="filtro-categoria" name="categoria_id" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">Todas as categorias</option>
            @foreach ($opcoesCategoria as $opcao)
                <option value="{{ $opcao['id'] }}" {{ $categoriaId === $opcao['id'] ? 'selected' : '' }}>
                    {{ $opcao['label'] }}
                </option>
            @endforeach
        </select>
        <button type="submit" class="bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-lg px-4 py-2 text-sm">
            Buscar
        </button>
        @if ($search !== '' || $categoriaId !== null)
            <a href="{{ route('avaliacoes.index') }}" class="border border-slate-300 bg-white text-slate-700 rounded-lg px-4 py-2 text-sm hover:bg-slate-50">
                Limpar
            </a>
        @endif
    </form>
</div>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-500 text-left">
            <tr>
                <th class="px-4 py-3">Código</th>
                <th class="px-4 py-3">Nome</th>
                <th class="px-4 py-3">Categoria</th>
                <th class="px-4 py-3">Data da avaliação</th>
                <th class="px-4 py-3">Questões</th>
                <th class="px-4 py-3">Alunos</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($avaliacoes as $avaliacao)
                <tr>
                    <td class="px-4 py-3 font-mono">#{{ $avaliacao->codigo }}</td>
                    <td class="px-4 py-3">{{ $avaliacao->nome ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $avaliacao->categoria?->nome ?? '—' }}</td>
                    <td class="px-4 py-3 text-slate-500">{{ $avaliacao->data_avaliacao?->format('d/m/Y') ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $avaliacao->questoes_count }}</td>
                    <td class="px-4 py-3">{{ $avaliacao->alunos_count }}</td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('avaliacoes.show', $avaliacao) }}" class="text-emerald-700 font-semibold hover:underline">
                            Gerenciar
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-4 py-8 text-center text-slate-400">Nenhuma avaliação cadastrada ainda.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $avaliacoes->links() }}
</div>
@endsection
