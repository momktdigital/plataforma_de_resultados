@extends('layouts.portal')

@section('title', 'Verificação de código')

@section('content')
<div class="w-full max-w-md mx-auto bg-white rounded-2xl shadow-xl border border-slate-100 p-8 sm:p-10 fade-in">
    <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-50 mb-4">
            <i class="ph ph-envelope-open text-3xl text-primary"></i>
        </div>
        <h1 class="text-2xl font-bold mb-2">Verificação de Segurança</h1>
        <p class="text-slate-500 text-sm">
            @if (! empty($emailOculto))
                Enviamos um código de 6 dígitos para o seu e-mail <br><strong class="text-slate-700">{{ $emailOculto }}</strong>.
            @else
                Informe o código enviado para o seu e-mail cadastrado.
            @endif
        </p>
    </div>

    @if (! empty($erro))
        <div class="mb-6 rounded-lg bg-red-50 p-4 border border-red-100 flex items-start gap-3">
            <i class="ph-fill ph-warning-circle text-red-500 text-xl mt-0.5"></i>
            <p class="text-sm text-red-700 font-medium">{{ $erro }}</p>
        </div>
    @endif
    @if (! empty($status))
        <div class="mb-6 rounded-lg bg-emerald-50 p-4 border border-emerald-100 flex items-start gap-3">
            <i class="ph-fill ph-check-circle text-emerald-500 text-xl mt-0.5"></i>
            <p class="text-sm text-emerald-700 font-medium">{{ $status }}</p>
        </div>
    @endif

    <form method="POST" action="{{ route('portal.verificar') }}" class="space-y-6">
        @csrf
        <input type="hidden" name="cpf" value="{{ $cpf }}">
        <div>
            <label class="block text-sm font-bold text-slate-700 mb-1 ml-1 text-center" for="codigo">Código de verificação</label>
            <input id="codigo" name="codigo" type="text" maxlength="6" required autofocus
                   placeholder="------" autocomplete="one-time-code"
                   class="block w-full px-4 py-4 bg-slate-50 border border-slate-200 rounded-xl text-3xl font-bold text-center tracking-[0.5em] text-slate-800 placeholder-slate-300 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all shadow-inner uppercase">
        </div>
        <button type="submit"
                class="w-full flex justify-center items-center py-4 px-4 border border-transparent rounded-xl shadow-md text-base font-bold text-white bg-primary hover:bg-emerald-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition-all hover:-translate-y-0.5">
            <span>Confirmar Código</span>
            <i class="ph-bold ph-check ml-2 text-lg"></i>
        </button>
    </form>

    <div class="text-center mt-6">
        <form method="POST" action="{{ route('portal.reenviar') }}" class="inline">
            @csrf
            <input type="hidden" name="cpf" value="{{ $cpf }}">
            <button type="submit" class="text-sm font-medium text-slate-500 hover:text-primary transition-colors">
                Reenviar código
            </button>
        </form>
    </div>
    <div class="text-center mt-2">
        <a href="{{ route('portal.consulta') }}" class="text-xs text-slate-400 hover:text-slate-600 underline">
            Cancelar e voltar
        </a>
    </div>
</div>
@endsection
