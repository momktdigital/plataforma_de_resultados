@extends('layouts.app')

@section('title', "Editar {$categoria->nome} — Categorias")

@section('content')
<a href="{{ route('categorias.index') }}" class="text-sm text-slate-500 hover:underline">&larr; Categorias</a>
<h1 class="text-2xl font-bold mt-2 mb-6">Editar categoria</h1>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 max-w-xl">
    <form method="POST" action="{{ route('categorias.update', $categoria) }}" class="space-y-4">
        @csrf
        @method('PUT')
        <div>
            <label class="block text-sm font-medium mb-1" for="nome">Nome</label>
            <input id="nome" name="nome" type="text" required value="{{ old('nome', $categoria->nome) }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1" for="categoria_pai_id">Categoria-mãe (opcional)</label>
            <select id="categoria_pai_id" name="categoria_pai_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="">— Categoria raiz —</option>
                @foreach ($opcoesSelect as $opcao)
                    <option value="{{ $opcao['id'] }}" {{ (int) old('categoria_pai_id', $categoria->categoria_pai_id) === $opcao['id'] ? 'selected' : '' }}>
                        {{ $opcao['label'] }}
                    </option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-4 py-2 text-sm">
            Salvar alterações
        </button>
    </form>
</div>
@endsection
