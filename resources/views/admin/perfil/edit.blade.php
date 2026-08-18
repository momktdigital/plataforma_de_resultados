@extends('layouts.app')

@section('title', 'Meu Perfil — Avaliações')

@section('content')
<h1 class="text-2xl font-bold mb-6">Meu Perfil</h1>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 max-w-xl">
    <h2 class="font-semibold mb-4">Alterar senha de acesso</h2>
    <p class="text-sm text-slate-500 mb-6">
        Por motivos de segurança, informe sua senha atual antes de cadastrar uma nova.
    </p>

    <form method="POST" action="{{ route('perfil.senha') }}" class="space-y-4">
        @csrf
        @method('PUT')

        <div>
            <label class="block text-sm font-medium mb-1" for="current_password">Senha atual</label>
            <input id="current_password" name="current_password" type="password" required
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>

        <div class="grid sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium mb-1" for="new_password">Nova senha</label>
                <input id="new_password" name="new_password" type="password" required minlength="4"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" for="new_password_confirmation">Confirmar nova senha</label>
                <input id="new_password_confirmation" name="new_password_confirmation" type="password" required minlength="4"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
        </div>

        <div class="flex justify-end pt-2">
            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-lg px-6 py-2.5 text-sm">
                Salvar senha
            </button>
        </div>
    </form>
</div>
@endsection
