@extends('layouts.app')

@section('title', 'Cronograma de atividades')

@section('content')
@php
    $mesAtual = $calendario ? $calendario['mes']->format('Y-m') : today()->format('Y-m');
@endphp
<div class="max-w-7xl">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-black flex items-center gap-2"><i class="ph-bold ph-calendar-check text-primary" aria-hidden="true"></i> Cronograma de atividades</h1>
            <p class="text-sm text-slate-500 mt-1 max-w-3xl">
                Cadastre as atividades e marque os cursos a que se aplicam: cada atividade aparece no calendário do coordenador desses cursos.
                As pendências ficam vinculadas à atividade e o coordenador apenas as visualiza.
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('colaborador.pendencias.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                <i class="ph-bold ph-warning-circle text-lg" aria-hidden="true"></i> Pendências
            </a>
            <a href="{{ route('colaborador.atividades.create', array_filter(['data' => $mesAtual === today()->format('Y-m') ? today()->toDateString() : $mesAtual.'-01'])) }}" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 px-4 py-2 text-sm font-semibold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">
                <i class="ph-bold ph-plus text-lg" aria-hidden="true"></i> Nova atividade
            </a>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3 mb-4 sm:max-w-md">
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4">
            <div class="text-2xl font-black">{{ $resumo['abertas'] }}</div>
            <div class="text-xs font-medium uppercase tracking-wide text-slate-600">{{ $resumo['abertas'] === 1 ? 'Pendência em aberto' : 'Pendências em aberto' }}</div>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4">
            <div class="text-2xl font-black {{ $resumo['atrasadas'] > 0 ? 'text-red-700' : '' }}">{{ $resumo['atrasadas'] }}</div>
            <div class="text-xs font-medium uppercase tracking-wide text-slate-600">Com prazo vencido</div>
        </div>
    </div>

    @include('cronograma._barra', ['rota' => 'colaborador.index', 'visao' => $visao, 'filtros' => $filtros, 'opcoesCurso' => $opcoesCurso, 'mes' => $mesAtual])

    @if ($visao === 'lista')
        @include('cronograma._lista', ['itens' => $itens, 'rotaItem' => 'colaborador.atividades.show', 'escopo' => null])
    @else
        @include('cronograma._calendario', [
            'calendario' => $calendario,
            'rotaItem' => 'colaborador.atividades.show',
            'rotaMes' => 'colaborador.index',
            'query' => $filtros,
            'cursosDoUsuario' => null,
            'rotaNovo' => 'colaborador.atividades.create',
        ])
    @endif
</div>
@endsection
