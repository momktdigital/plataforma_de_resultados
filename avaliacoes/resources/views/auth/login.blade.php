@extends('layouts.auth')

@section('title', 'Entrar')

@section('content')
<div class="text-center mb-8">
    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-slate-800 border border-slate-700 mb-4 shadow-lg">
        <i class="ph-fill ph-lock-key text-3xl text-primary"></i>
    </div>
    <h1 class="text-2xl font-bold text-white tracking-tight">Área Restrita</h1>
    <p class="text-slate-400 mt-2 text-sm">Painel de Administração de Avaliações</p>
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

        <form method="POST" action="{{ route('login') }}" class="space-y-6">
            @csrf
            <div>
                <label for="username" class="block text-sm font-medium text-slate-300 mb-1 ml-1">Usuário</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="ph-fill ph-user text-slate-500 text-lg"></i>
                    </div>
                    <input type="text" id="username" name="username" required autofocus value="{{ old('username') }}"
                           class="block w-full pl-10 pr-3 py-3 bg-slate-900 border border-slate-700 rounded-xl text-slate-200 placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all"
                           placeholder="admin">
                </div>
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-slate-300 mb-1 ml-1">Senha</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="ph-fill ph-lock text-slate-500 text-lg"></i>
                    </div>
                    <input type="password" id="password" name="password" required
                           class="block w-full pl-10 pr-3 py-3 bg-slate-900 border border-slate-700 rounded-xl text-slate-200 placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all"
                           placeholder="••••••••">
                </div>
            </div>

            <button type="submit"
                    class="w-full flex justify-center items-center py-3.5 px-4 border border-transparent rounded-xl shadow-md text-sm font-bold text-white bg-primary hover:bg-emerald-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-slate-800 focus:ring-primary transition-all">
                <i class="ph-bold ph-sign-in mr-2 text-lg"></i> Entrar no Sistema
            </button>
        </form>
    </div>

    <div class="bg-slate-900/50 px-8 py-4 border-t border-slate-700 text-center">
        <a href="{{ route('portal.consulta') }}" class="text-sm text-slate-500 hover:text-slate-300 transition-colors flex items-center justify-center">
            <i class="ph-bold ph-arrow-left mr-1"></i> Voltar para Consulta de Alunos
        </a>
    </div>
</div>
@endsection
