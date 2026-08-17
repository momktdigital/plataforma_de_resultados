@extends('layouts.app')

@section('title', 'Importar matrícula — Avaliações')

@section('content')
<a href="{{ route('alunos.index') }}" class="text-sm text-slate-500 hover:underline">&larr; Alunos</a>
<h1 class="text-2xl font-bold mt-2 mb-6">Importar matrícula de alunos</h1>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 max-w-2xl">
    <form method="POST" action="{{ route('alunos.importar.store') }}" enctype="multipart/form-data" class="space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium mb-1" for="arquivo">Arquivo de matrícula (.csv, .xlsx ou .xls)</label>
            <input id="arquivo" name="arquivo" type="file" accept=".csv,.txt,.xlsx,.xls" required
                   class="w-full text-sm">
        </div>
        <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-5 py-2 text-sm">
            Importar
        </button>
    </form>
</div>

<div class="mt-6 max-w-2xl bg-blue-50 border border-blue-200 rounded-xl p-5 text-sm text-blue-900">
    <p class="font-semibold mb-2">Colunas reconhecidas (cabeçalho flexível, com ou sem acento):</p>
    <p class="mb-2">
        <strong>Obrigatórias:</strong> RA (aceita Matricula/MatriculaAluno), Per. Letivo (ex.: 2026/1),
        Curso, Período (ex.: 5º) — linhas sem alguma dessas quatro são ignoradas.
    </p>
    <p class="mb-2">
        <strong>Opcionais:</strong> Cód. Perfil, Nome, Status/Situação, Turma, Dt. Nascimento, CPF, Email.
    </p>
    <p>
        A importação usa RA como identificador único: se o aluno já existir, os dados são atualizados
        (campos de identidade só são sobrescritos quando a planilha traz um valor novo; Status/Per.
        Letivo/Período/Turma sempre refletem a planilha mais recente).
    </p>
</div>
@endsection
