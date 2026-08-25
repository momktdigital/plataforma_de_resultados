@extends('layouts.app')

@section('title', 'Instalação — Banco de dados')

@section('content')
<div class="max-w-xl mx-auto mt-10 bg-white border border-slate-200 rounded-xl shadow-sm p-8">
    <h1 class="text-xl font-bold mb-1">Conexão com o banco de dados</h1>
    <p class="text-sm text-slate-500 mb-6">Passo 2 de 4.</p>

    <form method="POST" action="{{ route('instalar.banco.gravar') }}" class="space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium mb-1" for="host">Host</label>
            <input id="host" name="host" type="text" required value="{{ old('host', '127.0.0.1') }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1" for="porta">Porta</label>
            <input id="porta" name="porta" type="number" required value="{{ old('porta', 3306) }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1" for="banco">Nome do banco</label>
            <input id="banco" name="banco" type="text" required value="{{ old('banco') }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1" for="usuario">Usuário</label>
            <input id="usuario" name="usuario" type="text" required value="{{ old('usuario') }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1" for="senha">Senha</label>
            <input id="senha" name="senha" type="password"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg py-2.5 text-sm">
            Testar conexão e continuar
        </button>
    </form>
</div>
@endsection
