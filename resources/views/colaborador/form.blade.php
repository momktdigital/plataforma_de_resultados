@extends('layouts.app')

@section('title', ($item->exists ? 'Editar atividade' : 'Nova atividade').' — Cronograma')

@section('content')
@php
    $campo = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary';
    $voltar = $item->exists ? route('colaborador.atividades.show', $item) : route('colaborador.index', ['mes' => $item->data->format('Y-m')]);
@endphp
<div class="max-w-3xl">
    <a href="{{ $voltar }}" class="text-sm text-slate-500 hover:underline">&larr; {{ $item->exists ? 'Atividade' : 'Cronograma' }}</a>
    <h1 class="text-2xl font-black mt-2 mb-6">{{ $item->exists ? 'Editar atividade' : 'Nova atividade' }}</h1>

    <form method="POST" action="{{ $item->exists ? route('colaborador.atividades.update', $item) : route('colaborador.atividades.store') }}"
          class="space-y-5 bg-white border border-slate-200 rounded-xl shadow-sm p-6">
        @csrf
        @if ($item->exists) @method('PUT') @endif

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label class="block text-sm font-medium mb-1" for="data">Data</label>
                <input id="data" name="data" type="date" required value="{{ old('data', $item->data->toDateString()) }}" class="{{ $campo }}">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" for="rotina">Rotina</label>
                <select id="rotina" name="rotina" required class="{{ $campo }}">
                    <option value="" disabled @selected(! old('rotina', $item->rotina))>Escolha…</option>
                    @foreach (\App\Models\CronogramaItem::ROTINAS as $sigla => $quem)
                        <option value="{{ $sigla }}" @selected(old('rotina', $item->rotina) === $sigla)>{{ $sigla }} — {{ $quem }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1" for="projeto">Projeto / atividade</label>
            <input id="projeto" name="projeto" type="text" required maxlength="120" value="{{ old('projeto', $item->projeto) }}" placeholder="Ex.: TIN, A2 – Módulo A, P1"
                   class="{{ $campo }}">
        </div>

        <div>
            <label class="block text-sm font-medium mb-1" for="descricao">O que vou conferir</label>
            <textarea id="descricao" name="descricao" required maxlength="500" rows="3" placeholder="Ex.: Docentes postaram as avaliações da A2 – Módulo A"
                      class="{{ $campo }}">{{ old('descricao', $item->descricao) }}</textarea>
        </div>

        <div>
            <p class="block text-sm font-medium mb-1" id="rotulo-cursos">Cursos a que se aplica</p>
            <p class="text-xs text-slate-500 mb-2">A atividade aparece no calendário do coordenador de cada curso marcado. Curso desmarcado = "não se aplica".</p>
            <div role="group" aria-labelledby="rotulo-cursos">
                @include('partials.seletor-cursos', ['nome' => 'cursos', 'opcoes' => $opcoesCurso, 'selecionados' => $cursosSelecionados, 'id' => 'cronograma-cursos'])
            </div>
            @error('cursos')
                <p class="text-sm text-red-700 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex flex-wrap gap-3">
            <button type="submit" class="rounded-lg bg-emerald-700 hover:bg-emerald-800 px-5 py-2 text-sm font-semibold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">
                {{ $item->exists ? 'Salvar alterações' : 'Cadastrar atividade' }}
            </button>
            <a href="{{ $voltar }}" class="rounded-lg border border-slate-300 bg-white px-5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">Cancelar</a>
        </div>
    </form>
</div>
@endsection
