@extends('layouts.app')

@section('title', "Editar {$admin->username} — Administradores")

@section('content')
<a href="{{ route('administradores.index') }}" class="text-sm text-slate-500 hover:underline">&larr; Administradores</a>
<h1 class="text-2xl font-bold mt-2 mb-6">Editar administrador</h1>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 max-w-xl">
    <form method="POST" action="{{ route('administradores.update', $admin) }}" class="space-y-4">
        @csrf
        @method('PUT')
        <div>
            <label class="block text-sm font-medium mb-1" for="username">Nome de usuário</label>
            <input id="username" name="username" type="text" required value="{{ old('username', $admin->username) }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1" for="email">E-mail (opcional)</label>
            <input id="email" name="email" type="email" value="{{ old('email', $admin->email) }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <p class="text-xs text-slate-500 mt-1">Necessário pra esta conta poder usar "esqueci minha senha".</p>
        </div>
        <div>
            <label class="block text-sm font-medium mb-1" for="password">Nova senha (opcional)</label>
            <input id="password" name="password" type="password" minlength="4"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <p class="text-xs text-slate-500 mt-1">Deixe em branco para manter a senha atual.</p>
        </div>
        <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-4 py-2 text-sm">
            Salvar alterações
        </button>
    </form>
</div>
@endsection
