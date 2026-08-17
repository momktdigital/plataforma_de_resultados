@extends('layouts.app')

@section('title', "Importar resultados — Prova #{$prova->codigo}")

@section('content')
<a href="{{ route('provas.show', $prova) }}" class="text-sm text-slate-500 hover:underline">&larr; Prova #{{ $prova->codigo }}</a>
<h1 class="text-2xl font-bold mt-2 mb-6">Importar resultados</h1>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 max-w-2xl">
    <form method="POST" action="{{ route('provas.resultados.import.store', $prova) }}" enctype="multipart/form-data" class="space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium mb-1" for="arquivo">Arquivo (.csv, .xlsx ou .xls)</label>
            <input id="arquivo" name="arquivo" type="file" accept=".csv,.txt,.xlsx,.xls" required
                   class="w-full text-sm">
        </div>
        <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-5 py-2 text-sm">
            Importar
        </button>
    </form>
</div>

<div class="mt-6 max-w-2xl bg-blue-50 border border-blue-200 rounded-xl p-5 text-sm text-blue-900">
    <p class="font-semibold mb-2">Formato do arquivo (uma linha por resposta):</p>
    <p class="mb-2"><strong>Obrigatórias:</strong> CPF ou RA (ao menos uma das duas), Questão (número) e Resposta.</p>
    <p class="mb-2"><strong>Opcional:</strong> Período (ex.: "2026/1") — só é necessário se o mesmo aluno puder refazer esta prova em períodos diferentes; sem essa coluna, todas as respostas do aluno nesta prova são tratadas como uma tentativa única.</p>
    <p>Reimportar a mesma combinação de aluno + período + questão nesta prova atualiza a resposta em vez de duplicar.</p>
</div>
@endsection
