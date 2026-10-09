@extends('layouts.app')

@section('title', 'Pendências — Cronograma')

@section('content')
@php
    $campo = 'rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary';
    $filtros = [
        'abertas' => 'Em aberto',
        'todas' => 'Todas',
        ...\App\Models\CronogramaPendencia::STATUS,
    ];
@endphp
<div class="max-w-7xl">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-black flex items-center gap-2"><i class="ph-bold ph-warning-circle text-primary" aria-hidden="true"></i> Registro de pendências</h1>
            <p class="text-sm text-slate-500 mt-1 max-w-3xl">
                Todas as pendências registradas, de todos os cursos. Para registrar uma nova ou atualizar uma existente, abra a atividade.
            </p>
        </div>
        <a href="{{ route('colaborador.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
            <i class="ph-bold ph-calendar-check text-lg" aria-hidden="true"></i> Cronograma
        </a>
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

    <form method="GET" action="{{ route('colaborador.pendencias.index') }}" class="mb-4 flex flex-wrap items-end gap-3" aria-label="Filtrar pendências">
        <div>
            <label for="filtro-status" class="mb-1 block text-xs font-semibold text-slate-600">Situação</label>
            <select id="filtro-status" name="status" class="{{ $campo }}">
                @foreach ($filtros as $valor => $rotulo)
                    <option value="{{ $valor }}" @selected($valor === $status)>{{ $rotulo }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filtro-curso" class="mb-1 block text-xs font-semibold text-slate-600">Curso</label>
            <select id="filtro-curso" name="curso" class="{{ $campo }} max-w-xs">
                <option value="">Todos os cursos</option>
                @foreach ($opcoesCurso as $c)
                    <option value="{{ $c }}" @selected($c === $curso)>{{ $c }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filtro-rotina" class="mb-1 block text-xs font-semibold text-slate-600">Rotina</label>
            <select id="filtro-rotina" name="rotina" class="{{ $campo }}">
                <option value="">Todas</option>
                @foreach (\App\Models\CronogramaItem::ROTINAS as $sigla => $quem)
                    <option value="{{ $sigla }}" @selected($sigla === $rotina)>{{ $sigla }} — {{ $quem }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="rounded-lg bg-slate-800 hover:bg-slate-900 px-4 py-2 text-sm font-semibold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">Filtrar</button>
    </form>

    <div class="bg-white border border-slate-200 rounded-xl shadow-sm">
        @include('cronograma._tabela-pendencias', ['pendencias' => $pendencias, 'rotaItem' => 'colaborador.atividades.show', 'vazio' => 'Nenhuma pendência encontrada com esse filtro.'])
        @if ($pendencias->hasPages())
            <div class="border-t border-slate-100 px-4 py-3">{{ $pendencias->links() }}</div>
        @endif
    </div>
</div>
@endsection
