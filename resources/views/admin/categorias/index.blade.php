@extends('layouts.app')

@section('title', 'Categorias — Avaliações')

@section('content')
<h1 class="text-2xl font-bold mb-6">Categorias de prova</h1>
<p class="text-sm text-slate-500 mb-6 max-w-2xl">
    Organizam o boletim do aluno no portal público em árvore (categoria →
    subcategorias → provas). Uma prova sem categoria aparece à parte, em
    "Sem categoria".
</p>

<div class="grid lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 bg-white border border-slate-200 rounded-xl shadow-sm p-6">
        <h2 class="font-semibold mb-4">Árvore de categorias</h2>

        @if ($raizes->isEmpty())
            <p class="text-sm text-slate-400">Nenhuma categoria cadastrada ainda.</p>
        @else
            <ul>
                @foreach ($raizes as $categoria)
                    @include('admin.categorias._no', ['categoria' => $categoria, 'porPai' => $porPai])
                @endforeach
            </ul>
        @endif
    </div>

    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
        <h2 class="font-semibold mb-4">Nova categoria</h2>
        <form method="POST" action="{{ route('categorias.store') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium mb-1" for="nome">Nome</label>
                <input id="nome" name="nome" type="text" required value="{{ old('nome') }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" for="categoria_pai_id">Categoria-mãe (opcional)</label>
                <select id="categoria_pai_id" name="categoria_pai_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">— Categoria raiz —</option>
                    @foreach ($opcoesSelect as $opcao)
                        <option value="{{ $opcao['id'] }}" {{ (int) old('categoria_pai_id') === $opcao['id'] ? 'selected' : '' }}>
                            {{ $opcao['label'] }}
                        </option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-4 py-2 text-sm">
                Criar categoria
            </button>
        </form>
    </div>
</div>
@endsection
