@extends('layouts.app')

@section('title', 'Planos de ação')

@php
    use App\Models\PlanoAcao;

    $total = array_sum($contagens);
    $abas = [['todos', 'Todos', $total]];
    foreach (PlanoAcao::STATUS as $chave => $rotulo) {
        if (($contagens[$chave] ?? 0) > 0 || $filtro === $chave) {
            $abas[] = [$chave, $rotulo, $contagens[$chave] ?? 0];
        }
    }
@endphp

@section('content')
<div class="max-w-6xl">
    <div class="mb-5">
        <h1 class="text-2xl font-black flex items-center gap-2"><i class="ph-bold ph-clipboard-text text-primary" aria-hidden="true"></i> Planos de ação</h1>
        <p class="text-sm text-slate-600 mt-1 max-w-3xl">
            Do resultado do DI à ação pedagógica. Cada plano nasce de um dado do painel: procure o ícone
            <span class="inline-flex h-6 w-6 items-center justify-center rounded-md border border-slate-200 bg-white align-middle text-slate-600"><i class="ph-bold ph-clipboard-text" aria-hidden="true"></i></span>
            <span class="sr-only">(prancheta)</span>
            nos gráficos, indicadores e tabelas de <a href="{{ route('coordenador.desempenho') }}" class="font-semibold text-emerald-700 hover:underline">Desempenho</a> e da
            <a href="{{ route('coordenador.painel') }}" class="font-semibold text-emerald-700 hover:underline">Visão geral</a>.
        </p>
    </div>

    @if (($contagens[PlanoAcao::AJUSTES] ?? 0) > 0)
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 flex items-center gap-2" role="status">
            <i class="ph-bold ph-pencil-line text-lg" aria-hidden="true"></i>
            {{ $contagens[PlanoAcao::AJUSTES] === 1 ? '1 plano foi devolvido' : $contagens[PlanoAcao::AJUSTES].' planos foram devolvidos' }} pelo colaborador para ajustes.
        </div>
    @endif

    <nav class="flex flex-wrap gap-2 mb-4" aria-label="Filtrar planos por situação">
        @foreach ($abas as [$chave, $rotulo, $quantos])
            @php $ativa = $filtro === $chave; @endphp
            <a href="{{ route('coordenador.planos.index', $chave === 'todos' ? [] : ['status' => $chave]) }}" @if ($ativa) aria-current="true" @endif
               class="rounded-full border px-3.5 py-1.5 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-primary {{ $ativa ? 'bg-slate-800 border-slate-800 text-white' : 'bg-white border-slate-300 text-slate-700 hover:bg-slate-50' }}">{{ $rotulo }} ({{ $quantos }})</a>
        @endforeach
    </nav>

    @if ($planos->isEmpty())
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-8 text-center text-sm text-slate-600">
            <i class="ph-bold ph-clipboard-text text-4xl text-slate-500" aria-hidden="true"></i>
            <p class="mt-2 font-semibold text-slate-800">{{ $total === 0 ? 'Você ainda não tem planos de ação.' : 'Nenhum plano nesta situação.' }}</p>
            @if ($total === 0 && $podeCriar)
                <p class="mt-1">Abra o <a href="{{ route('coordenador.desempenho') }}" class="font-semibold text-emerald-700 hover:underline">Desempenho</a> do curso e clique no ícone de prancheta ao lado de um gráfico ou indicador para começar.</p>
            @endif
        </div>
    @else
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-x-auto">
            <table class="w-full text-sm">
                <caption class="sr-only">Planos de ação do curso</caption>
                <thead class="bg-slate-50 text-slate-500 text-left">
                    <tr>
                        <th scope="col" class="px-4 py-3">Plano</th>
                        <th scope="col" class="px-4 py-3">Situação</th>
                        <th scope="col" class="px-4 py-3">Ações</th>
                        <th scope="col" class="px-4 py-3">Atualizado</th>
                        <th scope="col" class="px-4 py-3"><span class="sr-only">Abrir</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($planos as $plano)
                        @php $p = $plano->progresso(); @endphp
                        <tr class="{{ $plano->status === PlanoAcao::AJUSTES ? 'bg-amber-50/50' : '' }}">
                            <td class="px-4 py-3 min-w-[16rem]">
                                <a href="{{ route('coordenador.planos.show', $plano) }}" class="font-semibold text-slate-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">{{ $plano->origem_rotulo }}</a>
                                <span class="block text-xs text-slate-500">{{ $plano->curso }} · {{ $plano->periodo_letivo ?: 'todos os períodos' }}@if (! empty($plano->contexto['categoria'])) · {{ $plano->contexto['categoria'] }}@endif</span>
                                @if ($plano->causa_raiz)<span class="block text-xs text-slate-600 mt-0.5 truncate max-w-md" title="{{ $plano->causa_raiz }}">Causa-raiz: {{ $plano->causa_raiz }}</span>@endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">@include('plano._status', ['status' => $plano->status])</td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if ($p['total'] === 0)
                                    <span class="text-slate-500">—</span>
                                @else
                                    <span class="font-semibold">{{ $p['concluidas'] }}/{{ $p['total'] }}</span>
                                    @if ($p['atrasadas'] > 0)<span class="ml-1 text-xs font-semibold text-red-700">{{ $p['atrasadas'] }} atrasada(s)</span>@endif
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-600 whitespace-nowrap">{{ $plano->updated_at?->format('d/m/Y') }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <a href="{{ route('coordenador.planos.show', $plano) }}" class="text-emerald-700 font-semibold hover:underline">Abrir</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $planos->links() }}</div>
    @endif
</div>
@endsection
