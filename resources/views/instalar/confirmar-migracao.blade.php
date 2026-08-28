@extends('layouts.app')

@section('title', 'Instalação — Criar tabelas')

@section('content')
<div class="max-w-2xl mx-auto mt-10 bg-white border border-slate-200 rounded-xl shadow-sm p-8">
    <h1 class="text-xl font-bold mb-1">Criar as tabelas do banco</h1>
    <p class="text-sm text-slate-500 mb-6">Passo 3 de 4.</p>

    <p class="text-sm text-slate-700 mb-6">
        Esta etapa roda as migrations no banco configurado no passo anterior — cria (ou atualiza)
        todas as tabelas da aplicação. Confirme para continuar.
    </p>

    <form method="POST" action="{{ route('instalar.migrar.store') }}">
        @csrf
        <button type="submit"
                class="block w-full text-center bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg py-2.5 text-sm">
            Criar tabelas
        </button>
    </form>
</div>
@endsection
