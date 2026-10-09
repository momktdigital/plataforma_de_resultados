@extends('layouts.app')

@section('title', $item->projeto.' — Cronograma')

@section('content')
<div class="max-w-5xl">
    <a href="{{ route('cronograma.index', ['mes' => $item->data->format('Y-m')]) }}" class="text-sm text-slate-500 hover:underline">&larr; Cronograma</a>

    <div class="mt-2 mb-6 bg-white border border-slate-200 rounded-xl shadow-sm p-6">
        <p class="text-sm font-semibold text-slate-600">
            {{ ucfirst($item->data->locale('pt_BR')->translatedFormat('l, d \d\e F \d\e Y')) }}
            · <span class="font-bold">{{ $item->rotina }}</span> ({{ \App\Models\CronogramaItem::ROTINAS[$item->rotina] ?? '' }})
        </p>
        <h1 class="mt-1 text-2xl font-black break-words">{{ $item->projeto }}</h1>
        <p class="mt-3 text-slate-700 whitespace-pre-line">{{ $item->descricao }}</p>
    </div>

    <section class="mb-6 bg-white border border-slate-200 rounded-xl shadow-sm" aria-labelledby="titulo-situacao">
        <h2 id="titulo-situacao" class="px-6 pt-5 pb-2 text-lg font-black">{{ $cursosDoItem->count() > 1 ? 'Situação nos seus cursos' : 'Situação no seu curso' }}</h2>
        <ul class="divide-y divide-slate-100 px-6 pb-3">
            @foreach ($cursosDoItem as $c)
                <li class="flex flex-wrap items-center justify-between gap-2 py-2.5">
                    <span class="font-medium">{{ $c->curso }}</span>
                    @include('cronograma._status', ['status' => $c->status])
                </li>
            @endforeach
        </ul>
    </section>

    <section class="bg-white border border-slate-200 rounded-xl shadow-sm" aria-labelledby="titulo-pendencias-item">
        <h2 id="titulo-pendencias-item" class="px-6 pt-5 pb-1 text-lg font-black">Pendências desta atividade</h2>
        <p class="px-6 pb-3 text-sm text-slate-500">Registradas pelo colaborador. Você só visualiza; o registro fica guardado como histórico.</p>
        @include('cronograma._tabela-pendencias', [
            'pendencias' => $pendencias->each->setRelation('item', $item),
            'rotaItem' => 'cronograma.show',
            'mostrarCurso' => $cursosDoItem->count() > 1,
            'vazio' => 'Nenhuma pendência registrada nesta atividade.',
        ])
    </section>
</div>
@endsection
