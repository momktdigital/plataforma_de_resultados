@extends('layouts.app')

@section('title', $aluno->exists ? 'Editar aluno — Avaliações' : 'Novo aluno — Avaliações')

@section('content')
<a href="{{ route('alunos.index') }}" class="text-sm text-slate-500 hover:underline">&larr; Voltar para Alunos</a>
<h1 class="text-2xl font-bold mt-2 mb-6">{{ $aluno->exists ? 'Editar Aluno' : 'Novo Aluno' }}</h1>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 max-w-2xl">
    <form method="POST" action="{{ $aluno->exists ? route('alunos.update', $aluno) : route('alunos.store') }}" class="space-y-4">
        @csrf
        @if ($aluno->exists)
            @method('PUT')
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium mb-1" for="ra">Registro Acadêmico (RA) *</label>
                <input id="ra" name="ra" type="text" required value="{{ old('ra', $aluno->ra) }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <p class="text-xs text-slate-500 mt-1">Identificador único no sistema de notas.</p>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" for="nome">Nome completo</label>
                <input id="nome" name="nome" type="text" value="{{ old('nome', $aluno->nome) }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" for="cpf">CPF *</label>
                <input id="cpf" name="cpf" type="text" required placeholder="000.000.000-00"
                       value="{{ old('cpf', $aluno->cpf) }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <p class="text-xs text-slate-500 mt-1">Será usado como login pelo aluno.</p>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" for="data_nascimento">Data de Nascimento *</label>
                <input id="data_nascimento" name="data_nascimento" type="text" required placeholder="DD/MM/AAAA"
                       value="{{ old('data_nascimento', $aluno->data_nascimento?->format('d/m/Y')) }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <p class="text-xs text-slate-500 mt-1">Senha de acesso do aluno no portal público.</p>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" for="curso">Curso</label>
                <input id="curso" name="curso" type="text" value="{{ old('curso', $aluno->curso) }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" for="campus">Câmpus / Polo</label>
                <input id="campus" name="campus" type="text" value="{{ old('campus', $aluno->campus) }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" for="email">E-mail</label>
                <input id="email" name="email" type="email" value="{{ old('email', $aluno->email) }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
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
@endsection
