@extends('layouts.app')

@section('title', "Importar questões — Avaliacao #{$avaliacao->codigo}")

@section('content')
<a href="{{ route('avaliacoes.show', $avaliacao) }}" class="text-sm text-slate-500 hover:underline">&larr; Avaliacao #{{ $avaliacao->codigo }}</a>
<h1 class="text-2xl font-bold mt-2 mb-6">Importar questões e gabarito</h1>

<a href="{{ asset('exemplos/questoes-exemplo.xlsx') }}"
   class="inline-flex items-center gap-2 mb-6 text-sm font-medium text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-lg px-4 py-2 hover:bg-emerald-100">
    <i class="ph ph-file-arrow-down text-lg"></i> Baixar planilha de exemplo (.xlsx)
</a>

@include('admin.imports._status', ['status' => $importStatus, 'voltar' => route('avaliacoes.show', $avaliacao)])

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 max-w-2xl">
    <form id="import-form" method="POST" action="{{ route('avaliacoes.questoes.import.store', $avaliacao) }}" enctype="multipart/form-data" class="space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium mb-1" for="arquivo">Arquivo (.csv, .xlsx ou .xls)</label>
            <input id="arquivo" name="arquivo" type="file" accept=".csv,.txt,.xlsx,.xls" required
                   class="w-full text-sm">
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" name="dry_run" id="dry_run" value="1" checked>
            Simular (mostra o que foi identificado antes de gravar)
        </label>
        <button type="submit" id="botao-importar" @disabled($importStatus['status'] === 'processando')
                class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-5 py-2 text-sm disabled:opacity-50 disabled:cursor-not-allowed">
            Importar
        </button>
    </form>

    <div id="preview-painel" class="hidden mt-4"></div>
</div>

<script>
(function () {
    var form = document.getElementById('import-form');
    var dryRunCheckbox = document.getElementById('dry_run');
    var arquivoInput = document.getElementById('arquivo');
    var painel = document.getElementById('preview-painel');
    var botaoImportar = document.getElementById('botao-importar');

    form.addEventListener('submit', function (event) {
        if (! dryRunCheckbox.checked) {
            return; // envio normal — sem simulação, importa direto.
        }

        event.preventDefault();
        simular();
    });

    function simular() {
        if (! arquivoInput.files.length) {
            return;
        }

        painel.classList.remove('hidden');
        painel.innerHTML = '<p class="text-sm text-slate-500">Lendo arquivo&hellip;</p>';
        botaoImportar.disabled = true;

        var dados = new FormData();
        dados.append('arquivo', arquivoInput.files[0]);

        fetch('{{ route('avaliacoes.questoes.import.preview', $avaliacao) }}', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
            body: dados,
        })
            .then(function (res) {
                return res.json().then(function (json) { return { ok: res.ok, json: json }; });
            })
            .then(function (resultado) {
                botaoImportar.disabled = false;

                if (! resultado.ok) {
                    painel.innerHTML = '<p class="text-sm text-red-700">'
                        + escapeHtml(resultado.json.erro || 'Não foi possível ler o arquivo.') + '</p>';
                    return;
                }

                renderizarPreview(resultado.json.campos);
            })
            .catch(function () {
                botaoImportar.disabled = false;
                painel.innerHTML = '<p class="text-sm text-red-700">Não foi possível ler o arquivo. Tente novamente.</p>';
            });
    }

    function renderizarPreview(campos) {
        var linhas = campos.map(function (campo) {
            var status, classe;

            if (campo.identificado) {
                status = 'Identificado';
                classe = 'bg-white text-slate-700';
            } else if (campo.obrigatorio) {
                status = 'Não identificado — obrigatório';
                classe = 'bg-red-50 text-red-800';
            } else {
                status = 'Não identificado — opcional';
                classe = 'bg-amber-50 text-amber-800';
            }

            return '<tr class="' + classe + '">'
                + '<td class="px-3 py-1.5 border-b border-slate-100 font-medium">' + escapeHtml(campo.rotulo) + '</td>'
                + '<td class="px-3 py-1.5 border-b border-slate-100">' + status + '</td>'
                + '</tr>';
        }).join('');

        var faltaObrigatorio = campos.some(function (campo) { return campo.obrigatorio && ! campo.identificado; });

        painel.innerHTML =
            '<div class="border border-slate-200 rounded-lg overflow-hidden">'
            + '<div class="px-3 py-2 bg-slate-50 border-b border-slate-200 text-sm font-semibold text-slate-700">O que foi identificado no arquivo</div>'
            + '<div class="overflow-x-auto">'
            + '<table class="w-full text-sm border-collapse">'
            + '<thead><tr class="text-left text-xs uppercase text-slate-500">'
            + '<th class="px-3 py-1.5">Campo</th><th class="px-3 py-1.5">Status</th>'
            + '</tr></thead>'
            + '<tbody>' + linhas + '</tbody>'
            + '</table>'
            + '</div>'
            + '<div class="px-3 py-3 border-t border-slate-200 bg-slate-50">'
            + (faltaObrigatorio
                ? '<p class="text-sm text-red-700 mb-2">Faltam colunas obrigatórias — linhas sem elas serão ignoradas no import.</p>'
                : '')
            + '<p class="text-sm text-slate-700 mb-2">Pode continuar e importar de verdade?</p>'
            + '<div class="flex gap-2">'
            + '<button type="button" id="preview-confirmar" class="bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-lg px-4 py-1.5">Sim, importar</button>'
            + '<button type="button" id="preview-cancelar" class="bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-semibold rounded-lg px-4 py-1.5">Cancelar</button>'
            + '</div>'
            + '</div>'
            + '</div>';

        document.getElementById('preview-confirmar').addEventListener('click', function () {
            dryRunCheckbox.checked = false;
            form.submit();
        });

        document.getElementById('preview-cancelar').addEventListener('click', function () {
            painel.classList.add('hidden');
            painel.innerHTML = '';
        });
    }

    function escapeHtml(texto) {
        var div = document.createElement('div');
        div.textContent = texto;
        return div.innerHTML;
    }
})();
</script>

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
        <li>Reimportar o mesmo número de questão desta avaliação <strong>atualiza</strong> os dados em vez de duplicar.</li>
        <li>Aceita <code class="bg-slate-100 px-1 rounded">.xlsx</code>, <code class="bg-slate-100 px-1 rounded">.xls</code> ou <code class="bg-slate-100 px-1 rounded">.csv</code>.</li>
    </ul>
</div>
@endsection
