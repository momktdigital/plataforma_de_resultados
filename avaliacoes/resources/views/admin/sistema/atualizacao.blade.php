@extends('layouts.app')

@section('title', 'Atualizações — Avaliações')

@section('content')
<h1 class="text-2xl font-bold mb-6">Atualizações</h1>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 max-w-2xl">
    <p class="text-sm text-slate-500 mb-1">Versão instalada</p>
    <p class="text-lg font-mono font-semibold mb-6">{{ $versaoAtual }}</p>

    @if ($disponivel)
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 mb-6">
            <p class="font-semibold text-emerald-900 mb-1">Nova versão disponível: {{ $disponivel['versao'] }}</p>
            @if ($disponivel['notas'])
                <div class="text-sm text-emerald-800 whitespace-pre-line mt-2">{{ $disponivel['notas'] }}</div>
            @endif
        </div>

        <form method="POST" action="{{ route('sistema.atualizacao.store') }}"
              onsubmit="return confirm('A aplicação ficará em manutenção durante a atualização. Um backup será gerado automaticamente antes. Continuar?');">
            @csrf
            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-5 py-2 text-sm">
                Atualizar agora
            </button>
        </form>
    @else
        <p class="text-sm text-slate-500">Nenhuma atualização disponível — você já está na versão mais recente (ou o GitHub não pôde ser consultado agora).</p>
    @endif
</div>
@endsection
