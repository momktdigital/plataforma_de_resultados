@extends('layouts.app')

@section('title', $aluno->exists ? 'Editar aluno — Avaliações' : 'Novo aluno — Avaliações')

@php
    $estados = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
@endphp

@section('content')
<a href="{{ route('alunos.index') }}" class="text-sm text-slate-500 hover:underline">&larr; Voltar para Alunos</a>
<h1 class="text-2xl font-bold mt-2 mb-6">{{ $aluno->exists ? 'Editar Aluno' : 'Novo Aluno' }}</h1>

<div class="max-w-4xl grid grid-cols-1 md:grid-cols-[220px_1fr] gap-6 items-start">
    @if ($aluno->exists)
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 text-center">
            @if ($aluno->fotoUrl())
                <img src="{{ $aluno->fotoUrl(300) }}" alt="Foto de {{ $aluno->nome ?: $aluno->ra }}"
                     class="w-32 h-32 rounded-full object-cover mx-auto border border-slate-200"
                     onerror="this.onerror=null;this.src='';this.classList.add('hidden');this.nextElementSibling.classList.remove('hidden');">
                <div class="hidden w-32 h-32 rounded-full mx-auto bg-slate-100 flex items-center justify-center text-slate-400 text-4xl font-bold">
                    {{ mb_strtoupper(mb_substr($aluno->nome ?: $aluno->ra, 0, 1)) }}
                </div>
            @else
                <div class="w-32 h-32 rounded-full mx-auto bg-slate-100 flex items-center justify-center text-slate-400 text-4xl font-bold">
                    {{ mb_strtoupper(mb_substr($aluno->nome ?: $aluno->ra, 0, 1)) }}
                </div>
            @endif
            <p class="font-bold text-slate-800 mt-3 truncate" title="{{ $aluno->nome }}">{{ $aluno->nome ?: '—' }}</p>
            <p class="text-xs text-slate-500">RA {{ $aluno->ra }}</p>
            <div class="mt-3 pt-3 border-t border-slate-100 text-left">
                <p class="text-xs font-medium text-slate-500">E-mail institucional</p>
                <p class="text-sm text-slate-700 break-all">{{ $aluno->email_institucional }}</p>
            </div>
        </div>
    @endif

    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
        <form method="POST" action="{{ $aluno->exists ? route('alunos.update', $aluno) : route('alunos.store') }}" class="space-y-6">
            @csrf
            @if ($aluno->exists)
                @method('PUT')
            @endif

            <div>
                <h2 class="text-sm font-bold text-slate-500 uppercase tracking-wide mb-3">Identificação</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1" for="ra">Registro Acadêmico (RA) *</label>
                        <input id="ra" name="ra" type="text" required value="{{ old('ra', $aluno->ra) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <p class="text-xs text-slate-500 mt-1">Identificador único no sistema de notas.</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="cod_perfil">Cód. Perfil</label>
                        <input id="cod_perfil" name="cod_perfil" type="text" value="{{ old('cod_perfil', $aluno->cod_perfil) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <p class="text-xs text-slate-500 mt-1">Usado para carregar a foto do aluno.</p>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium mb-1" for="nome">Nome completo</label>
                        <input id="nome" name="nome" type="text" value="{{ old('nome', $aluno->nome) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="cpf">CPF</label>
                        <input id="cpf" name="cpf" type="text" placeholder="000.000.000-00"
                               value="{{ old('cpf', $aluno->cpf) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <p class="text-xs text-slate-500 mt-1">Usado como login pelo aluno no portal.</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="data_nascimento">Data de Nascimento</label>
                        <input id="data_nascimento" name="data_nascimento" type="text" placeholder="DD/MM/AAAA"
                               value="{{ old('data_nascimento', $aluno->data_nascimento?->format('d/m/Y')) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <p class="text-xs text-slate-500 mt-1">Senha de acesso do aluno no portal público.</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="sexo">Sexo</label>
                        <input id="sexo" name="sexo" type="text" value="{{ old('sexo', $aluno->sexo) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="estado_civil">Estado Civil</label>
                        <input id="estado_civil" name="estado_civil" type="text" value="{{ old('estado_civil', $aluno->estado_civil) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="cor_raca">Cor/Raça</label>
                        <input id="cor_raca" name="cor_raca" type="text" value="{{ old('cor_raca', $aluno->cor_raca) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="religiao">Religião</label>
                        <input id="religiao" name="religiao" type="text" value="{{ old('religiao', $aluno->religiao) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>
                </div>
            </div>

            <div class="pt-6 border-t border-slate-100">
                <h2 class="text-sm font-bold text-slate-500 uppercase tracking-wide mb-3">Contato</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1" for="email">E-mail</label>
                        <input id="email" name="email" type="email" value="{{ old('email', $aluno->email) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="celular">Celular</label>
                        <input id="celular" name="celular" type="text" value="{{ old('celular', $aluno->celular) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="cidade">Cidade</label>
                        <input id="cidade" name="cidade" type="text" value="{{ old('cidade', $aluno->cidade) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="uf">UF</label>
                        <select id="uf" name="uf" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white">
                            <option value="">—</option>
                            @foreach ($estados as $estado)
                                <option value="{{ $estado }}" {{ old('uf', $aluno->uf) === $estado ? 'selected' : '' }}>{{ $estado }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if ($aluno->exists)
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-medium mb-1">E-mail institucional</label>
                            <input type="text" value="{{ $aluno->email_institucional }}" disabled
                                   class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500">
                            <p class="text-xs text-slate-500 mt-1">Gerado automaticamente a partir do RA — não editável.</p>
                        </div>
                    @endif
                </div>
            </div>

            <div class="pt-6 border-t border-slate-100">
                <h2 class="text-sm font-bold text-slate-500 uppercase tracking-wide mb-3">Matrícula</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1" for="status">Status</label>
                        <input id="status" name="status" type="text" value="{{ old('status', $aluno->status) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="campus">Câmpus / Polo</label>
                        <input id="campus" name="campus" type="text" value="{{ old('campus', $aluno->campus) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="curso">Curso</label>
                        <input id="curso" name="curso" type="text" value="{{ old('curso', $aluno->curso) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="matriz">Matriz</label>
                        <input id="matriz" name="matriz" type="text" value="{{ old('matriz', $aluno->matriz) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="turma">Turma</label>
                        <input id="turma" name="turma" type="text" value="{{ old('turma', $aluno->turma) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="periodo">Período</label>
                        <input id="periodo" name="periodo" type="text" value="{{ old('periodo', $aluno->periodo) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="periodo_letivo">Período Letivo</label>
                        <input id="periodo_letivo" name="periodo_letivo" type="text" value="{{ old('periodo_letivo', $aluno->periodo_letivo) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t border-slate-100">
                <a href="{{ route('alunos.index') }}" class="border border-slate-300 bg-white text-slate-700 rounded-lg px-5 py-2.5 text-sm font-medium hover:bg-slate-50">
                    Cancelar
                </a>
                <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-lg px-5 py-2.5 text-sm">
                    Salvar cadastro
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
