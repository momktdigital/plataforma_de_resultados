@extends('layouts.auth')

@section('title', 'Redefinir senha')

@section('content')
<div class="text-center mb-8">
    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-slate-800 border border-slate-700 mb-4 shadow-lg">
        <i class="ph-fill ph-lock-key-open text-3xl text-primary"></i>
    </div>
    <h1 class="text-2xl font-bold text-white tracking-tight">Redefinir senha</h1>
</div>

<div class="bg-slate-800 rounded-2xl shadow-2xl border border-slate-700 overflow-hidden">
    <div class="p-8">
        @if ($errors->any())
            <div class="bg-red-900/30 border border-red-800 text-red-300 p-4 mb-6 rounded-lg text-sm flex items-start gap-2">
                <i class="ph-fill ph-warning-circle text-xl mt-0.5"></i>
                <ul class="space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('senha.redefinir.salvar', $token) }}" class="space-y-6">
            @csrf
            <div>
                <label for="password" class="block text-sm font-medium text-slate-300 mb-1 ml-1">Nova senha</label>
                <input type="password" id="password" name="password" required minlength="10" autofocus
                       class="block w-full px-3 py-3 bg-slate-900 border border-slate-700 rounded-xl text-slate-200 placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all"
                       placeholder="••••••••">
            </div>
            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-slate-300 mb-1 ml-1">Confirme a nova senha</label>
                <input type="password" id="password_confirmation" name="password_confirmation" required minlength="10"
                       class="block w-full px-3 py-3 bg-slate-900 border border-slate-700 rounded-xl text-slate-200 placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all"
                       placeholder="••••••••">
            </div>

            <button type="submit"
                    class="w-full flex justify-center items-center py-3.5 px-4 border border-transparent rounded-xl shadow-md text-sm font-bold text-white bg-primary hover:bg-emerald-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-slate-800 focus:ring-primary transition-all">
                <i class="ph-bold ph-check mr-2 text-lg"></i> Salvar nova senha
            </button>
        </form>
    </div>
</div>
@endsection
