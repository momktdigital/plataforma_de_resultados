@extends('layouts.app')

@section('title', 'Cronograma de atividades')

@section('content')
@php
    $mesAtual = $calendario ? $calendario['mes']->format('Y-m') : today()->format('Y-m');
@endphp
<div class="max-w-7xl">
    <div class="mb-4">
        <h1 class="text-2xl font-black flex items-center gap-2"><i class="ph-bold ph-calendar-check text-primary" aria-hidden="true"></i> Cronograma de atividades</h1>
        <p class="text-sm text-slate-500 mt-1">
            As atividades da rotina de avaliações que se aplicam {{ count($meusCursos) > 1 ? 'aos seus cursos' : 'ao seu curso' }}
            ({{ implode(' · ', $meusCursos) ?: 'nenhum curso vinculado' }}). O colaborador cadastra as atividades e registra as pendências; aqui você acompanha.
        </p>
    </div>

    @if ($meusCursos === [])
        <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-xl p-4 text-sm">
            Sua conta ainda não tem curso vinculado, então não há atividades para mostrar. Procure um administrador.
        </div>
    @else
        <div class="grid grid-cols-2 gap-3 mb-4 sm:max-w-md">
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4">
                <div class="text-2xl font-black">{{ $resumo['abertas'] }}</div>
                <div class="text-xs font-medium uppercase tracking-wide text-slate-600">{{ $resumo['abertas'] === 1 ? 'Pendência em aberto' : 'Pendências em aberto' }}</div>
            </div>
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4">
                <div class="text-2xl font-black {{ $resumo['atrasadas'] > 0 ? 'text-red-700' : '' }}">{{ $resumo['atrasadas'] }}</div>
                <div class="text-xs font-medium uppercase tracking-wide text-slate-600">{{ $resumo['atrasadas'] === 1 ? 'Com prazo vencido' : 'Com prazo vencido' }}</div>
            </div>
        </div>

        @include('cronograma._barra', ['rota' => 'cronograma.index', 'visao' => $visao, 'filtros' => $filtros, 'opcoesCurso' => $meusCursos, 'mes' => $mesAtual, 'extra' => ['pendencias' => $todasAsPendencias ? 'todas' : '']])

        @if ($visao === 'lista')
            @include('cronograma._lista', ['itens' => $itens, 'rotaItem' => 'cronograma.show', 'escopo' => $cursos])
        @else
            @include('cronograma._calendario', [
                'calendario' => $calendario,
                'rotaItem' => 'cronograma.show',
                'rotaMes' => 'cronograma.index',
                'query' => [...$filtros, 'pendencias' => $todasAsPendencias ? 'todas' : ''],
                'cursosDoUsuario' => $cursos,
            ])
        @endif

        <section class="mt-8" aria-labelledby="titulo-pendencias">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <h2 id="titulo-pendencias" class="text-lg font-black flex items-center gap-2"><i class="ph-bold ph-warning-circle text-slate-600" aria-hidden="true"></i> Pendências registradas</h2>
                <nav class="flex gap-2" aria-label="Filtrar pendências">
                    @foreach ([['Em aberto', false], ['Todas, com o histórico', true]] as [$rotulo, $todas])
                        <a href="{{ route('cronograma.index', array_filter([...$filtros, 'visao' => $visao === 'lista' ? 'lista' : '', 'mes' => $visao === 'calendario' ? $mesAtual : '', 'pendencias' => $todas ? 'todas' : ''])) }}" @if ($todasAsPendencias === $todas) aria-current="true" @endif
                           class="rounded-full border px-3.5 py-1.5 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-primary {{ $todasAsPendencias === $todas ? 'bg-slate-800 border-slate-800 text-white' : 'bg-white border-slate-300 text-slate-700 hover:bg-slate-50' }}">{{ $rotulo }}</a>
                    @endforeach
                </nav>
            </div>
            <p class="mb-3 text-sm text-slate-500">Registradas pelo colaborador para o seu curso — você só visualiza. Ficam guardadas como histórico.</p>
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm">
                @include('cronograma._tabela-pendencias', [
                    'pendencias' => $pendencias,
                    'rotaItem' => 'cronograma.show',
                    'mostrarCurso' => count($meusCursos) > 1,
                    'vazio' => $todasAsPendencias ? 'Nenhuma pendência foi registrada para o seu curso.' : 'Nenhuma pendência em aberto para o seu curso.',
                ])
                @if ($pendencias->hasPages())
                    <div class="border-t border-slate-100 px-4 py-3">{{ $pendencias->links() }}</div>
                @endif
            </div>
        </section>
    @endif
</div>
@endsection
