@extends('layouts.app')

@section('title', "Prova #{$prova->codigo} — Avaliações")

@section('content')
<div class="mb-6">
    <a href="{{ route('provas.index') }}" class="text-sm text-slate-500 hover:underline">&larr; Todas as provas</a>
    <h1 class="text-2xl font-bold mt-2">Prova #{{ $prova->codigo }} @if($prova->nome) — {{ $prova->nome }} @endif</h1>
    @if ($prova->tipo)
        <p class="text-slate-500">{{ $prova->tipo }}</p>
    @endif
    @if ($prova->link_comentado)
        <a href="{{ $prova->link_comentado }}" target="_blank" rel="noopener" class="text-sm text-emerald-700 hover:underline">
            Gabarito comentado &nearr;
        </a>
    @endif
</div>

@if (session('importIgnoradas') && count(session('importIgnoradas')))
    <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 text-sm">
        <p class="font-semibold mb-2">{{ count(session('importIgnoradas')) }} linha(s) ignorada(s):</p>
        <ul class="list-disc pl-5 space-y-0.5 max-h-48 overflow-y-auto">
            @foreach (session('importIgnoradas') as $ignorada)
                <li>Linha {{ $ignorada['linha'] }}: {{ $ignorada['motivo'] }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="grid sm:grid-cols-2 gap-6">
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
        <h2 class="font-semibold mb-1">Questões e gabarito</h2>
        <p class="text-sm text-slate-500 mb-4">{{ $prova->questoes_count }} questão(ões) cadastrada(s).</p>
        <a href="{{ route('provas.questoes.import', $prova) }}"
           class="inline-block bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-4 py-2 text-sm">
            Importar questões
        </a>
    </div>

    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
        <h2 class="font-semibold mb-1">Resultados</h2>
        <p class="text-sm text-slate-500 mb-4">
            {{ $prova->resultados_count }} resposta(s) registrada(s).
            @if ($prova->metricas_count)
                &middot; {{ $prova->metricas_count }} métrica(s) agregada(s) (ex.: notas finais).
            @endif
        </p>
        <a href="{{ route('provas.resultados.import', $prova) }}"
           class="inline-block bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-4 py-2 text-sm">
            Importar resultados
        </a>
    </div>
</div>
@endsection
