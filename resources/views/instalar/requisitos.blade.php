@extends('layouts.app')

@section('title', 'Instalação — Avaliações')

@section('content')
<div class="max-w-xl mx-auto mt-10 bg-white border border-slate-200 rounded-xl shadow-sm p-8">
    <h1 class="text-xl font-bold mb-1">Instalação — Avaliações</h1>
    <p class="text-sm text-slate-500 mb-6">Passo 1 de 4 — verificação de requisitos.</p>

    <ul class="space-y-2 text-sm mb-6">
        <li class="flex items-center justify-between border-b border-slate-100 pb-2">
            <span>PHP 8.3 ou superior</span>
            <span class="{{ $phpOk ? 'text-emerald-600' : 'text-red-600' }} font-semibold">
                {{ $phpOk ? 'OK' : 'Falhou (versão ' . PHP_VERSION . ')' }}
            </span>
        </li>
        @foreach ($extensoes as $extensao => $ok)
            <li class="flex items-center justify-between border-b border-slate-100 pb-2">
                <span>Extensão PHP: {{ $extensao }}</span>
                <span class="{{ $ok ? 'text-emerald-600' : 'text-red-600' }} font-semibold">{{ $ok ? 'OK' : 'Ausente' }}</span>
            </li>
        @endforeach
        <li class="flex items-center justify-between border-b border-slate-100 pb-2">
            <span>Pasta <code>storage/</code> gravável</span>
            <span class="{{ $storageGravavel ? 'text-emerald-600' : 'text-red-600' }} font-semibold">{{ $storageGravavel ? 'OK' : 'Falhou' }}</span>
        </li>
        <li class="flex items-center justify-between border-b border-slate-100 pb-2">
            <span>Pasta <code>bootstrap/cache/</code> gravável</span>
            <span class="{{ $bootstrapCacheGravavel ? 'text-emerald-600' : 'text-red-600' }} font-semibold">{{ $bootstrapCacheGravavel ? 'OK' : 'Falhou' }}</span>
        </li>
        <li class="flex items-center justify-between pb-2">
            <span>Arquivo <code>.env</code> gravável</span>
            <span class="{{ $envGravavel ? 'text-emerald-600' : 'text-red-600' }} font-semibold">{{ $envGravavel ? 'OK' : 'Falhou' }}</span>
        </li>
    </ul>

    @php $tudoOk = $phpOk && $extensoes->every(fn ($ok) => $ok) && $storageGravavel && $bootstrapCacheGravavel && $envGravavel; @endphp

    @if ($tudoOk)
        <a href="{{ route('instalar.banco') }}"
           class="block text-center bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg py-2.5 text-sm">
            Continuar
        </a>
    @else
        <p class="text-sm text-red-600">Corrija os itens acima antes de continuar.</p>
    @endif
</div>
@endsection
