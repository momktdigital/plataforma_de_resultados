@extends('layouts.app')

@section('title', "Importar questões — Prova #{$prova->codigo}")

@section('content')
<a href="{{ route('provas.show', $prova) }}" class="text-sm text-slate-500 hover:underline">&larr; Prova #{{ $prova->codigo }}</a>
<h1 class="text-2xl font-bold mt-2 mb-6">Importar questões e gabarito</h1>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 max-w-2xl">
    <form method="POST" action="{{ route('provas.questoes.import.store', $prova) }}" enctype="multipart/form-data" class="space-y-4">
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
    <p class="font-semibold mb-2">Colunas reconhecidas:</p>
    <p class="mb-2"><strong>Obrigatórias:</strong> Questão (número) e Gabarito.</p>
    <p class="mb-2"><strong>Opcionais</strong> (preenchidas só quando existirem no arquivo):
        Matriz da Prova (campo A/B/C), Bloom (nível/verbo), Miller (nível),
        Dificuldade pedagógica (fácil/médio/difícil), Dificuldade TRI, DCN (campo A/B),
        Portaria INEP (campo A/B/C), PPC (campo A/B/C/D), Matriz (período/disciplina/código) —
        estas três últimas aceitam múltiplos valores separados por vírgula, ponto-e-vírgula ou "|".
    </p>
    <p>Reimportar o mesmo número de questão desta prova atualiza os dados em vez de duplicar.</p>
</div>
@endsection
