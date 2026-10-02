@extends('layouts.auth')

@section('title', 'Código de acesso')

@section('content')
<div class="text-center mb-8">
    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-slate-800 border border-slate-700 mb-4 shadow-lg">
        <i class="ph-fill ph-envelope-simple text-3xl text-primary"></i>
    </div>
    <h1 class="text-2xl font-bold text-white tracking-tight">Digite o código</h1>
    <p class="text-slate-400 mt-2 text-sm">Enviamos um código de 6 dígitos para o seu e-mail.</p>
</div>

<div class="bg-slate-800 rounded-2xl shadow-2xl border border-slate-700 overflow-hidden">
    <div class="p-8">
        @if (session('status'))
            <div class="bg-emerald-900/30 border border-emerald-800 text-emerald-300 p-4 mb-6 rounded-lg text-sm flex items-start gap-2">
                <i class="ph-fill ph-check-circle text-xl mt-0.5"></i>
                <span>{{ session('status') }}</span>
            </div>
        @endif

        @if ($errors->any())
            <div id="erros-do-formulario" data-resumo-erros role="alert" class="bg-red-900/30 border border-red-800 text-red-300 p-4 mb-6 rounded-lg text-sm flex items-start gap-2">
                <i class="ph-fill ph-warning-circle text-xl mt-0.5" aria-hidden="true"></i>
                <ul class="space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('login.codigo.verificar') }}" class="space-y-6">
            @csrf
            <div>
                <label for="codigo" class="block text-sm font-medium text-slate-300 mb-1 ml-1">Código de acesso</label>
                <input type="text" id="codigo" name="codigo" required autofocus inputmode="numeric" autocomplete="one-time-code"
                       maxlength="6" pattern="\d{6}"
                       class="block w-full text-center tracking-[0.5em] text-2xl font-bold py-3 bg-slate-900 border border-slate-700 rounded-xl text-slate-200 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all"
                       placeholder="000000">
            </div>

            <button type="submit"
                    class="w-full flex justify-center items-center py-3.5 px-4 border border-transparent rounded-xl shadow-md text-sm font-bold text-white bg-primary hover:bg-emerald-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-slate-800 focus:ring-primary transition-all">
                <i class="ph-bold ph-sign-in mr-2 text-lg"></i> Entrar
            </button>
        </form>

        <form method="POST" action="{{ route('login.codigo.reenviar') }}" class="text-center mt-4">
            @csrf
            <button type="submit" class="text-sm text-slate-400 hover:text-slate-300 transition-colors">
                Não recebi o código &mdash; enviar de novo
            </button>
        </form>
    </div>

    <div class="bg-slate-900/50 px-8 py-4 border-t border-slate-700 text-center">
        <a href="{{ route('login', ['modo' => 'codigo']) }}" class="text-sm text-slate-400 hover:text-slate-300 transition-colors flex items-center justify-center">
            <i class="ph-bold ph-arrow-left mr-1"></i> Voltar
        </a>
    </div>
</div>
@endsection
