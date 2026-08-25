@extends('layouts.app')

@section('title', 'Instalação concluída')

@section('content')
<div class="max-w-xl mx-auto mt-10 bg-white border border-slate-200 rounded-xl shadow-sm p-8 text-center">
    <h1 class="text-xl font-bold mb-2 text-emerald-700">Instalação concluída</h1>
    <p class="text-sm text-slate-500 mb-6">O sistema está pronto para uso.</p>
    <a href="{{ route('login') }}"
       class="inline-block bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-6 py-2.5 text-sm">
        Ir para o login
    </a>
</div>
@endsection
