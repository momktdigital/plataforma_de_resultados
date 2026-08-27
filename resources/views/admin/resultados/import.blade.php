@extends('layouts.app')

@section('title', "Importar resultados — Avaliacao #{$avaliacao->codigo}")

@section('content')
<a href="{{ route('avaliacoes.show', $avaliacao) }}" class="text-sm text-slate-500 hover:underline">&larr; Avaliacao #{{ $avaliacao->codigo }}</a>
<h1 class="text-2xl font-bold mt-2 mb-6">Importar resultados</h1>

<a href="{{ asset('exemplos/resultados-exemplo.xlsx') }}"
   class="inline-flex items-center gap-2 mb-6 text-sm font-medium text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-lg px-4 py-2 hover:bg-emerald-100">
    <i class="ph ph-file-arrow-down text-lg"></i> Baixar planilha de exemplo (.xlsx)
</a>

@include('admin.imports._status', ['status' => $importStatus, 'voltar' => route('avaliacoes.show', $avaliacao)])

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 max-w-2xl">
    <form method="POST" action="{{ route('avaliacoes.resultados.import.store', $avaliacao) }}" enctype="multipart/form-data" class="space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium mb-1" for="arquivo">Arquivo (.csv, .xlsx ou .xls)</label>
            <input id="arquivo" name="arquivo" type="file" accept=".csv,.txt,.xlsx,.xls" required
                   class="w-full text-sm">
        </div>
        <button type="submit" @disabled($importStatus['status'] === 'processando')
                class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-5 py-2 text-sm disabled:opacity-50 disabled:cursor-not-allowed">
            Importar
        </button>
    </form>
</div>

<div class="mt-8 max-w-3xl">
    <h2 class="font-semibold text-slate-800 mb-1">Como sua planilha deve ficar</h2>
    <p class="text-sm text-slate-500 mb-4">
        Formato "longo": <strong>uma linha por resposta a uma questão</strong> — se o aluno respondeu
        50 questões, são 50 linhas para ele, não uma coluna por questão. A linha 1 é o cabeçalho.
    </p>

    <div class="flex flex-wrap gap-3 mb-3 text-xs font-medium">
        <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span> Obrigatória</span>
        <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-slate-400"></span> Opcional</span>
    </div>

    <div class="overflow-x-auto border border-slate-200 rounded-xl shadow-sm bg-white">
        <table class="text-xs border-collapse w-full">
            <thead>
                <tr>
                    @foreach ([
                        ['RA', 'obrigatoria'], ['CPF', 'obrigatoria'], ['Questão', 'obrigatoria'],
                        ['Resposta', 'obrigatoria'], ['Período', 'opcional'],
                    ] as [$rotulo, $tipo])
                        <th @class([
                                'px-2.5 py-2 text-left font-semibold whitespace-nowrap border-b border-slate-200',
                                'bg-emerald-50 text-emerald-800' => $tipo === 'obrigatoria',
                                'bg-slate-50 text-slate-600' => $tipo === 'opcional',
                            ])>
                            {{ $rotulo }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="text-slate-700">
                @foreach ([
                    ['2026001', '', 1, 'B', '2026/1'],
                    ['2026001', '', 2, 'C', '2026/1'],
                    ['2026001', '', 3, '', '2026/1'],
                    ['', '11122233344', 1, 'A', '2026/1'],
                ] as $linha)
                    <tr class="odd:bg-white even:bg-slate-50/60">
                        @foreach ($linha as $valor)
                            <td class="px-2.5 py-1.5 whitespace-nowrap border-b border-slate-100 {{ $valor === '' ? 'text-slate-300' : '' }}">
                                {{ $valor === '' ? '—' : $valor }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <ul class="mt-4 space-y-1.5 text-sm text-slate-600 list-disc list-inside">
        <li><strong>RA</strong> ou <strong>CPF</strong> — só precisa de uma das duas por linha (linha 4 do exemplo usa só CPF).</li>
        <li><strong>Resposta</strong> precisa existir como coluna, mas pode ficar vazia numa linha — significa que o aluno deixou aquela questão em branco (linha 3 do exemplo).</li>
        <li><strong>Período</strong> só é necessário se o mesmo aluno puder refazer esta avaliação em períodos diferentes; sem essa coluna, todas as respostas do aluno nesta avaliação contam como uma tentativa única.</li>
        <li>Reimportar a mesma combinação de aluno + período + questão <strong>atualiza</strong> a resposta em vez de duplicar.</li>
        <li>Aceita <code class="bg-slate-100 px-1 rounded">.xlsx</code>, <code class="bg-slate-100 px-1 rounded">.xls</code> ou <code class="bg-slate-100 px-1 rounded">.csv</code>.</li>
    </ul>
</div>
@endsection
