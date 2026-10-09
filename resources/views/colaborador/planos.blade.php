@extends('layouts.app')

@section('title', 'Planos de ação')

@section('content')
<div class="max-w-7xl">
    <div class="mb-5">
        <h1 class="text-2xl font-black flex items-center gap-2"><i class="ph-bold ph-clipboard-text text-primary" aria-hidden="true"></i> Planos de ação</h1>
        <p class="text-sm text-slate-600 mt-1 max-w-3xl">
            Os planos que os coordenadores enviaram a partir do painel de resultados. Analise o plano, decida (aprovar, pedir ajustes ou recusar — sempre com justificativa) e acompanhe a execução das ações aprovadas.
        </p>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
        @foreach ([
            [$painel['aguardando'], 'Aguardando análise', false],
            [$painel['emExecucao'], 'Em execução', false],
            [$painel['acoesAtrasadas'], 'Ações com prazo vencido', $painel['acoesAtrasadas'] > 0],
            [$painel['parados'], 'Planos sem atualização', $painel['parados'] > 0],
        ] as [$numero, $rotulo, $alerta])
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4">
                <div class="text-2xl font-black {{ $alerta ? 'text-red-700' : '' }}">{{ $numero }}</div>
                <div class="text-xs font-medium uppercase tracking-wide text-slate-600">{{ $rotulo }}</div>
            </div>
        @endforeach
    </div>

    @if (count($quadro) > 1 || $curso === '')
        <details class="mb-5">
            <summary class="cursor-pointer text-sm font-bold text-slate-700 mb-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">Quadro por curso</summary>
            <div class="mt-2">@include('plano._quadro', ['linhas' => $quadro])</div>
        </details>
    @endif

    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
        <nav class="flex flex-wrap gap-2" aria-label="Filtrar planos por situação">
            @foreach ($abas as $chave => $definicao)
                @php $ativa = $aba === $chave; @endphp
                <a href="{{ route('colaborador.planos.index', array_filter(['aba' => $chave, 'curso' => $curso, 'q' => $busca])) }}" @if ($ativa) aria-current="true" @endif
                   class="rounded-full border px-3.5 py-1.5 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-primary {{ $ativa ? 'bg-slate-800 border-slate-800 text-white' : 'bg-white border-slate-300 text-slate-700 hover:bg-slate-50' }}">{{ $definicao['rotulo'] }} ({{ $contagens[$chave] }})</a>
            @endforeach
        </nav>
        <form method="GET" action="{{ route('colaborador.planos.index') }}" class="flex flex-wrap items-end gap-2">
            <input type="hidden" name="aba" value="{{ $aba }}">
            <div>
                <label for="busca" class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">Buscar</label>
                <input id="busca" name="q" type="search" value="{{ $busca }}" maxlength="100" placeholder="Origem, causa-raiz, curso…" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white w-56 max-w-full">
            </div>
            <div>
                <label for="filtro-curso" class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">Curso</label>
                <select id="filtro-curso" name="curso" onchange="this.form.submit()" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white min-w-[180px] max-w-full">
                    <option value="">Todos os cursos</option>
                    @foreach ($opcoesCurso as $c)
                        <option value="{{ $c }}" @selected($curso === $c)>{{ $c }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="rounded-lg bg-slate-800 hover:bg-slate-900 px-4 py-1.5 text-sm font-semibold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">Filtrar</button>
            <a href="{{ route('colaborador.planos.exportar', array_filter(['aba' => $aba, 'curso' => $curso, 'q' => $busca])) }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"><i class="ph-bold ph-file-xls" aria-hidden="true"></i> Exportar .xlsx</a>
        </form>
    </div>

    @if ($planos->isEmpty())
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-8 text-center text-sm text-slate-600">
            <i class="ph-bold ph-tray text-4xl text-slate-500" aria-hidden="true"></i>
            <p class="mt-2 font-semibold text-slate-800">Nenhum plano {{ $aba === 'analise' ? 'aguardando análise' : 'nesta situação' }}.</p>
        </div>
    @else
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-x-auto">
            <table class="w-full text-sm">
                <caption class="sr-only">Planos de ação enviados pelos coordenadores</caption>
                <thead class="bg-slate-50 text-slate-500 text-left">
                    <tr>
                        <th scope="col" class="px-4 py-3">Plano</th>
                        <th scope="col" class="px-4 py-3">Curso</th>
                        <th scope="col" class="px-4 py-3">Situação</th>
                        <th scope="col" class="px-4 py-3">{{ $aba === 'analise' ? 'Aguardando há' : 'Ações' }}</th>
                        <th scope="col" class="px-4 py-3">Última movimentação</th>
                        <th scope="col" class="px-4 py-3"><span class="sr-only">Abrir</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($planos as $plano)
                        @php
                            $p = $plano->progresso();
                            $ultima = $plano->ultimaMovimentacao();
                            $parado = $plano->estaParado();
                        @endphp
                        <tr>
                            <td class="px-4 py-3 min-w-[16rem]">
                                <a href="{{ route('colaborador.planos.show', $plano) }}" class="font-semibold text-slate-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">{{ $plano->origem_rotulo }}</a>
                                <span class="block text-xs text-slate-500">{{ $plano->periodo_letivo ?: 'todos os períodos' }}@if (! empty($plano->contexto['categoria'])) · {{ $plano->contexto['categoria'] }}@endif · por {{ $plano->autor?->username ?? '—' }}</span>
                            </td>
                            <td class="px-4 py-3">{{ $plano->curso }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">@include('plano._status', ['status' => $plano->status])</td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if ($aba === 'analise')
                                    @php $dias = $plano->enviado_em ? (int) $plano->enviado_em->diffInDays(now()) : 0; @endphp
                                    <span class="font-semibold {{ $dias >= \App\Services\PlanoAcaoLembreteService::PRAZO_ANALISE_DIAS ? 'text-red-700' : '' }}">{{ $dias === 0 ? 'hoje' : $dias.' '.($dias === 1 ? 'dia' : 'dias') }}</span>
                                @elseif ($p['total'] === 0)
                                    <span class="text-slate-500">—</span>
                                @else
                                    <span class="font-semibold">{{ $p['concluidas'] }}/{{ $p['total'] }}</span>
                                    @if ($p['atrasadas'] > 0)<span class="ml-1 text-xs font-semibold text-red-700">{{ $p['atrasadas'] }} atrasada(s)</span>@endif
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-600 whitespace-nowrap">
                                {{ $ultima?->format('d/m/Y') }}
                                @if ($parado)<span class="block text-xs font-semibold text-amber-700">sem atualização há {{ (int) $ultima->diffInDays(now()) }} dias</span>@endif
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <a href="{{ route('colaborador.planos.show', $plano) }}" class="text-emerald-700 font-semibold hover:underline">{{ $plano->aguardandoAnalise() ? 'Analisar' : 'Abrir' }}</a>
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
