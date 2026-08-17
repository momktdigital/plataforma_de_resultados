@extends('layouts.app')

@section('title', 'Instalação — Migrations')

@section('content')
<div class="max-w-2xl mx-auto mt-10 bg-white border border-slate-200 rounded-xl shadow-sm p-8">
    <h1 class="text-xl font-bold mb-1">Criando as tabelas</h1>
    <p class="text-sm text-slate-500 mb-6">Passo 3 de 4.</p>

    <pre class="bg-slate-900 text-slate-100 text-xs rounded-lg p-4 overflow-x-auto mb-6 max-h-64">{{ $saida }}</pre>

    <a href="{{ route('instalar.admin') }}"
       class="block text-center bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg py-2.5 text-sm">
        Continuar
    </a>
</div>
@endsection
