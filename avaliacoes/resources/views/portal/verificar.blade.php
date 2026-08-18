@extends('layouts.portal')

@section('title', 'Verificação de código')

@section('content')
<div class="text-center mb-8">
    <h1 class="text-2xl font-bold">Verificação em duas etapas</h1>
    <p class="text-slate-500 mt-1">
        @if (! empty($emailOculto))
            Enviamos um código para {{ $emailOculto }}.
        @else
            Informe o código enviado para o seu e-mail cadastrado.
        @endif
    </p>
</div>

@if (! empty($erro))
    <div class="mb-6 rounded-lg border border-red-200 bg-red-50 text-red-800 px-4 py-3 text-sm">{{ $erro }}</div>
@endif
@if (! empty($status))
    <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 text-sm">{{ $status }}</div>
@endif

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 space-y-4">
    <form method="POST" action="{{ route('portal.verificar') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="cpf" value="{{ $cpf }}">
        <div>
            <label class="block text-sm font-medium mb-1" for="codigo">Código de 6 dígitos</label>
            <input id="codigo" name="codigo" type="text" maxlength="6" required
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-center tracking-widest font-bold uppercase">
        </div>
        <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-lg px-5 py-2.5 text-sm">
            Validar código
        </button>
    </form>

    <form method="POST" action="{{ route('portal.reenviar') }}">
        @csrf
        <input type="hidden" name="cpf" value="{{ $cpf }}">
        <button type="submit" class="w-full border border-slate-300 text-slate-700 hover:bg-slate-50 rounded-lg px-5 py-2 text-sm">
            Reenviar código
        </button>
    </form>
</div>

<div class="text-center mt-4">
    <a href="{{ route('portal.consulta') }}" class="text-sm text-slate-500 hover:underline">&larr; Voltar</a>
</div>
@endsection
