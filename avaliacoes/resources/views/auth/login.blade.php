@extends('layouts.app')

@section('title', 'Entrar — Avaliações')

@section('content')
<div class="max-w-sm mx-auto mt-16 bg-white border border-slate-200 rounded-xl shadow-sm p-8">
    <h1 class="text-xl font-bold mb-6 text-center">Avaliações</h1>

    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <div>
            <label for="username" class="block text-sm font-medium mb-1">Usuário</label>
            <input id="username" name="username" type="text" required autofocus value="{{ old('username') }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
        </div>

        <div>
            <label for="password" class="block text-sm font-medium mb-1">Senha</label>
            <input id="password" name="password" type="password" required
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
        </div>

        <button type="submit"
                class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg py-2.5 text-sm">
            Entrar
        </button>
    </form>
</div>
@endsection
