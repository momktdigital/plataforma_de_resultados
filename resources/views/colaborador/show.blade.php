@extends('layouts.app')

@section('title', $item->projeto.' — Cronograma')

@section('content')
@php
    $campo = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary';
    $botaoPrimario = 'rounded-lg bg-emerald-700 hover:bg-emerald-800 px-4 py-2 text-sm font-semibold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2';
    $cursosDoItem = $item->cursos;
@endphp
<div class="max-w-5xl">
    <a href="{{ route('colaborador.index', ['mes' => $item->data->format('Y-m')]) }}" class="text-sm text-slate-500 hover:underline">&larr; Cronograma</a>

    <div class="mt-2 mb-6 bg-white border border-slate-200 rounded-xl shadow-sm p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-slate-600">
                    {{ ucfirst($item->data->locale('pt_BR')->translatedFormat('l, d \d\e F \d\e Y')) }}
                    · <span class="font-bold">{{ $item->rotina }}</span> ({{ \App\Models\CronogramaItem::ROTINAS[$item->rotina] ?? '' }})
                </p>
                <h1 class="mt-1 text-2xl font-black break-words">{{ $item->projeto }}</h1>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('colaborador.atividades.edit', $item) }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                    <i class="ph-bold ph-pencil-simple" aria-hidden="true"></i> Editar
                </a>
                <form method="POST" action="{{ route('colaborador.atividades.destroy', $item) }}" onsubmit="return confirm(@js('Excluir a atividade '.$item->projeto.'?'));">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                        <i class="ph-bold ph-trash" aria-hidden="true"></i> Excluir
                    </button>
                </form>
            </div>
        </div>
        <p class="mt-3 text-slate-700 whitespace-pre-line">{{ $item->descricao }}</p>
        @if ($item->criador)
            <p class="mt-3 text-xs text-slate-500">Cadastrada por {{ $item->criador->username }} em {{ $item->created_at->format('d/m/Y') }}.</p>
        @endif
    </div>

    <section class="mb-6 bg-white border border-slate-200 rounded-xl shadow-sm" aria-labelledby="titulo-situacao">
        <h2 id="titulo-situacao" class="px-6 pt-5 pb-1 text-lg font-black">Situação por curso</h2>
        <p class="px-6 pb-3 text-sm text-slate-500">O coordenador de cada curso vê esta situação no calendário dele. Curso que não aparece aqui = "não se aplica".</p>
        <form method="POST" action="{{ route('colaborador.atividades.situacao', $item) }}">
            @csrf
            @method('PUT')
            <ul class="divide-y divide-slate-100 px-6">
                @foreach ($cursosDoItem as $c)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2.5">
                        <label for="status-{{ $c->id }}" class="font-medium">{{ $c->curso }}</label>
                        <select id="status-{{ $c->id }}" name="status[{{ $c->id }}]" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                            @foreach (\App\Models\CronogramaItem::STATUS as $valor => $rotulo)
                                <option value="{{ $valor }}" @selected($c->status === $valor)>{{ $rotulo }}</option>
                            @endforeach
                        </select>
                    </li>
                @endforeach
            </ul>
            <div class="px-6 py-4"><button type="submit" class="{{ $botaoPrimario }}">Salvar situação</button></div>
        </form>
    </section>

    <section class="bg-white border border-slate-200 rounded-xl shadow-sm" aria-labelledby="titulo-pendencias-item">
        <h2 id="titulo-pendencias-item" class="px-6 pt-5 pb-1 text-lg font-black">Pendências desta atividade</h2>
        <p class="px-6 pb-4 text-sm text-slate-500">Cada registro fica guardado como histórico e o coordenador do curso o vê, sem poder alterar. Quando resolvida, mude a situação; só exclua um registro lançado por engano (a exclusão fica na auditoria).</p>

        <ul class="space-y-4 px-6">
            @forelse ($pendencias as $p)
                <li class="rounded-xl border p-4 {{ $p->estaResolvida() ? 'border-slate-200 bg-slate-50' : 'border-amber-200 bg-amber-50/40' }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="font-semibold">{{ $p->curso }} <span class="font-normal text-slate-500">· registrada em {{ $p->data->format('d/m/Y') }}{{ $p->registradoPor ? ' por '.$p->registradoPor->username : '' }}</span></p>
                        @include('cronograma._status', ['status' => $p->status])
                    </div>
                    <p class="mt-2 whitespace-pre-line">{{ $p->pendencia }}</p>
                    <dl class="mt-2 grid gap-x-6 gap-y-1 text-sm text-slate-700 sm:grid-cols-3">
                        <div><dt class="inline font-semibold">Encaminhamento:</dt> <dd class="inline whitespace-pre-line">{{ $p->encaminhamento ?: '—' }}</dd></div>
                        <div>
                            <dt class="inline font-semibold">Prazo:</dt>
                            <dd class="inline">{{ $p->prazo?->format('d/m/Y') ?? '—' }}@if ($p->estaAtrasada()) <span class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700">Atrasada</span>@endif</dd>
                        </div>
                        <div><dt class="inline font-semibold">Responsável:</dt> <dd class="inline">{{ $p->responsavel ?: '—' }}</dd></div>
                    </dl>
                    @if ($p->resolvida_em)
                        <p class="mt-1 text-xs text-slate-500">Resolvida em {{ $p->resolvida_em->format('d/m/Y') }}.</p>
                    @endif

                    <details class="mt-3">
                        <summary class="cursor-pointer text-sm font-semibold text-emerald-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">Atualizar ou excluir esta pendência</summary>
                        <form method="POST" action="{{ route('colaborador.pendencias.update', $p) }}" class="mt-3 space-y-3">
                            @csrf
                            @method('PUT')
                            @include('colaborador._campos-pendencia', ['p' => $p, 'sufixo' => $p->id, 'campo' => $campo])
                            <button type="submit" class="{{ $botaoPrimario }}">Salvar pendência</button>
                        </form>
                        <form method="POST" action="{{ route('colaborador.pendencias.destroy', $p) }}" class="mt-2" onsubmit="return confirm(@js('Excluir esta pendência de '.$p->curso.'? O registro some do histórico (fica só na auditoria).'));">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                <i class="ph-bold ph-trash" aria-hidden="true"></i> Excluir pendência
                            </button>
                        </form>
                    </details>
                </li>
            @empty
                <li class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">Nenhuma pendência registrada nesta atividade.</li>
            @endforelse
        </ul>

        <div class="m-6 mt-5 rounded-xl border border-slate-200 p-4">
            <h3 class="mb-3 font-bold">Registrar pendência</h3>
            <form method="POST" action="{{ route('colaborador.pendencias.store', $item) }}" class="space-y-3">
                @csrf
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium mb-1" for="nova-curso">Curso</label>
                        <select id="nova-curso" name="curso" required class="{{ $campo }}">
                            <option value="" disabled @selected(! old('curso'))>Escolha…</option>
                            @foreach ($cursosDoItem as $c)
                                <option value="{{ $c->curso }}" @selected(old('curso') === $c->curso)>{{ $c->curso }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1" for="nova-data">Data do registro</label>
                        <input id="nova-data" name="data" type="date" required value="{{ old('data', today()->toDateString()) }}" class="{{ $campo }}">
                    </div>
                </div>
                @include('colaborador._campos-pendencia', ['p' => null, 'sufixo' => 'nova', 'campo' => $campo])
                <button type="submit" class="{{ $botaoPrimario }}">Registrar pendência</button>
            </form>
        </div>
    </section>
</div>
@endsection
