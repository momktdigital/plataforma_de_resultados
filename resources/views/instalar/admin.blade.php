@extends('layouts.app')

@section('title', 'Instalação — Administrador')

@section('content')
<div class="max-w-xl mx-auto mt-10 bg-white border border-slate-200 rounded-xl shadow-sm p-8">
    <h1 class="text-xl font-bold mb-1">Criar administrador</h1>
    <p class="text-sm text-slate-500 mb-6">Passo 4 de 4 — esta conta poderá entrar no painel assim que a instalação terminar.</p>

    <form method="POST" action="{{ route('instalar.admin.criar') }}" class="space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium mb-1" for="username">Usuário</label>
            <input id="username" name="username" type="text" required value="{{ old('username') }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1" for="password">Senha (mínimo 10 caracteres)</label>
            <input id="password" name="password" type="password" required minlength="10"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1" for="password_confirmation">Confirme a senha</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required minlength="10"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg py-2.5 text-sm">
            Concluir instalação
        </button>
    </form>
</div>
@endsection
