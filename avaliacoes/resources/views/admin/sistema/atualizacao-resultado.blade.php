@extends('layouts.app')

@section('title', 'Resultado da atualização')

@section('content')
<h1 class="text-2xl font-bold mb-6">Configurações do sistema</h1>

@include('admin.sistema._subnav')

<h2 class="text-lg font-semibold mb-4">
    @if ($resultado['status'] === 'atualizado')
        Atualização concluída
    @elseif ($resultado['status'] === 'ja_atualizado')
        Nada para atualizar
    @else
        Falha na atualização
    @endif
</h2>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 max-w-2xl">
    <ul class="space-y-2 text-sm font-mono">
        @foreach ($resultado['mensagens'] as $mensagem)
            <li class="{{ str_starts_with($mensagem, 'ERRO') ? 'text-red-600' : 'text-slate-700' }}">{{ $mensagem }}</li>
        @endforeach
    </ul>

    <a href="{{ route('sistema.atualizacao.index') }}" class="inline-block mt-6 text-emerald-700 font-semibold hover:underline">
        Voltar
    </a>
</div>
@endsection
