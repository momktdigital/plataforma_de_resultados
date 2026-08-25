@extends('layouts.app')

@section('title', "Respondente — Avaliação #{$avaliacao->codigo}")

@section('content')
<a href="{{ route('avaliacoes.respondentes.index', $avaliacao) }}" class="text-sm text-slate-500 hover:underline">&larr; Resultados por aluno</a>
<h1 class="text-2xl font-bold mt-2 mb-1">
    {{ $respostas->first()->ra ?: $respostas->first()->cpf ?: $chave }}
</h1>
<p class="text-slate-500 mb-6">Período: {{ $periodo !== '' ? $periodo : '(sem período)' }}</p>

@if ($metricas->isNotEmpty())
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <h2 class="font-semibold mb-4">Notas finais</h2>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            @foreach ($metricas as $metrica)
                <div class="bg-slate-50 border border-slate-100 rounded-lg p-3">
                    <div class="text-xs font-bold text-slate-500 uppercase truncate" title="{{ $metrica->nome_metrica }}">{{ $metrica->nome_metrica }}</div>
                    <div class="text-xl font-black text-emerald-700">{{ $metrica->valor }}</div>
                </div>
            @endforeach
        </div>
    </div>
@endif

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
    <h2 class="font-semibold mb-4">Respostas</h2>
    <div class="grid grid-cols-5 sm:grid-cols-8 md:grid-cols-10 gap-2">
        @foreach ($respostas as $resposta)
            @php
                $correta = $gabaritos[$resposta->questao_numero] ?? null;
                $marcada = $resposta->resposta ?: '';
                $cor = 'bg-slate-400';
                if ($correta !== null && $correta !== '') {
                    $cor = $marcada === $correta ? 'bg-green-500' : ($marcada === '' ? 'bg-slate-400' : 'bg-red-500');
                }
            @endphp
            <div class="rounded overflow-hidden border border-slate-200">
                <div class="{{ $cor }} text-white text-[10px] text-center font-bold py-1">Q{{ $resposta->questao_numero }}</div>
                <div class="bg-white text-center font-bold text-sm py-1.5 {{ $marcada === '' ? 'text-slate-300' : 'text-slate-700' }}">
                    {{ $marcada !== '' ? $marcada : '-' }}
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection
