@extends('layouts.app')

@section('title', "Importar questões — Prova #{$prova->codigo}")

@section('content')
<a href="{{ route('provas.show', $prova) }}" class="text-sm text-slate-500 hover:underline">&larr; Prova #{{ $prova->codigo }}</a>
<h1 class="text-2xl font-bold mt-2 mb-6">Importar questões e gabarito</h1>

<a href="{{ asset('exemplos/questoes-exemplo.xlsx') }}"
   class="inline-flex items-center gap-2 mb-6 text-sm font-medium text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-lg px-4 py-2 hover:bg-emerald-100">
    <i class="ph ph-file-arrow-down text-lg"></i> Baixar planilha de exemplo (.xlsx)
</a>

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

<div class="mt-8 max-w-5xl">
    <h2 class="font-semibold text-slate-800 mb-1">Como sua planilha deve ficar</h2>
    <p class="text-sm text-slate-500 mb-4">
        A linha 1 é o cabeçalho — é por esses nomes (ou variações próximas) que o sistema reconhece cada coluna.
        As linhas seguintes são exemplo; as suas ficam no lugar delas.
    </p>

    <div class="flex flex-wrap gap-3 mb-3 text-xs font-medium">
        <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span> Obrigatória</span>
        <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-slate-400"></span> Opcional — um valor por questão</span>
        <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-400"></span> Opcional — aceita vários valores</span>
    </div>

    <div class="overflow-x-auto border border-slate-200 rounded-xl shadow-sm bg-white">
        <table class="text-xs border-collapse w-full">
            <thead>
                <tr>
                    @foreach ([
                        ['Questão', 'obrigatoria'], ['Gabarito', 'obrigatoria'],
                        ['Área', 'simples'], ['Tema', 'simples'], ['Habilidade', 'simples'],
                        ['Bloom (nível)', 'simples'], ['Bloom (verbo)', 'simples'], ['Miller (nível)', 'simples'],
                        ['Dificuldade Pedagógica', 'simples'], ['Dificuldade TRI', 'simples'],
                        ['Matriz (período)', 'multipla'], ['Matriz (disciplina)', 'multipla'], ['Matriz (código)', 'multipla'],
                        ['Matriz Prova A', 'multipla'], ['Matriz Prova B', 'multipla'], ['Matriz Prova C', 'multipla'],
                        ['DCN A', 'multipla'], ['DCN B', 'multipla'],
                        ['Portaria INEP A', 'multipla'], ['Portaria INEP B', 'multipla'], ['Portaria INEP C', 'multipla'],
                        ['PPC A', 'multipla'], ['PPC B', 'multipla'], ['PPC C', 'multipla'], ['PPC D', 'multipla'],
                    ] as [$rotulo, $tipo])
                        <th @class([
                                'px-2.5 py-2 text-left font-semibold whitespace-nowrap border-b border-slate-200',
                                'bg-emerald-50 text-emerald-800' => $tipo === 'obrigatoria',
                                'bg-slate-50 text-slate-600' => $tipo === 'simples',
                                'bg-amber-50 text-amber-800' => $tipo === 'multipla',
                            ])>
                            {{ $rotulo }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="text-slate-700">
                @foreach ([
                    [1, 'B', 'Clínica Médica', 'HIV/AIDS', 'E3 — Avaliação e Julgamento Ético-Profissional', 'Aplicação', 'Avaliar', 'Sabe como', 'fácil', '0,35', '1;2', 'Anatomia;Fisiologia', 'AN01;FI02', 'Item 1', 'Item 2', '', 'Art. 5º', '', 'P1', 'P2', '', 'PPC-01', 'PPC-02', '', ''],
                    [2, 'C', 'Cirurgia Geral', 'Cirurgia Bariátrica', 'E2 — Aplicação e Análise', 'Análise', 'Analisar', 'Sabe fazer', 'médio', '0,58', '3', 'Clínica Médica', 'CM04', 'Item 3', '', '', '', '', '', '', '', 'PPC-03', '', '', ''],
                    [3, 'A', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
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
        <li><strong>Área</strong>, <strong>Tema</strong> e <strong>Habilidade</strong> descrevem o conteúdo da questão — um valor só por questão, igual Bloom/Miller/Dificuldade.</li>
        <li><strong>Bloom (verbo)</strong> também aceita o cabeçalho <strong>Taxonomia</strong> — se sua planilha já chama essa coluna assim (com os verbos Lembrar/Aplicar/Analisar/Avaliar...), não precisa renomear.</li>
        <li><strong>Matriz (período/disciplina/código)</strong> aceitam vários valores na mesma célula, separados por vírgula, ponto-e-vírgula ou "|" (ver linha 1 do exemplo acima).</li>
        <li><strong>Matriz Prova, DCN, Portaria INEP e PPC</strong> guardam vários valores usando uma coluna por letra (A, B, C...) — deixe em branco as letras que não usar.</li>
        <li>Reimportar o mesmo número de questão desta prova <strong>atualiza</strong> os dados em vez de duplicar.</li>
        <li>Aceita <code class="bg-slate-100 px-1 rounded">.xlsx</code>, <code class="bg-slate-100 px-1 rounded">.xls</code> ou <code class="bg-slate-100 px-1 rounded">.csv</code>.</li>
    </ul>
</div>
@endsection
