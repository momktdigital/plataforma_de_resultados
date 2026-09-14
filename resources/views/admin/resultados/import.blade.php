@extends('layouts.app')

@section('title', "Importar resultados — Avaliacao #{$avaliacao->codigo}")

@section('content')
<a href="{{ route('avaliacoes.show', $avaliacao) }}" class="text-sm text-slate-500 hover:underline">&larr; Avaliacao #{{ $avaliacao->codigo }}</a>
<h1 class="text-2xl font-bold mt-2 mb-6">Importar resultados</h1>

<a href="{{ asset('exemplos/resultados-exemplo.xlsx') }}"
   class="inline-flex items-center gap-2 mb-6 text-sm font-medium text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-lg px-4 py-2 hover:bg-emerald-100">
    <i class="ph ph-file-arrow-down text-lg"></i> Baixar planilha de exemplo — formato longo (.xlsx)
</a>
<a href="{{ asset('exemplos/resultados-exemplo-largo.xlsx') }}"
   class="inline-flex items-center gap-2 mb-6 ml-2 text-sm font-medium text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-lg px-4 py-2 hover:bg-emerald-100">
    <i class="ph ph-file-arrow-down text-lg"></i> Baixar planilha de exemplo — formato largo (.xlsx)
</a>

@include('admin.imports._status', ['status' => $importStatus, 'voltar' => route('avaliacoes.show', $avaliacao)])

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 max-w-2xl">
    <form id="import-form" method="POST" action="{{ route('avaliacoes.resultados.import.store', $avaliacao) }}" enctype="multipart/form-data" class="space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium mb-1" for="arquivo">Arquivo (.csv, .xlsx ou .xls)</label>
            <input id="arquivo" name="arquivo" type="file" accept=".csv,.txt,.xlsx,.xls" required
                   class="w-full text-sm">
            <p class="text-xs text-slate-500 mt-1">Aceita os dois formatos abaixo — o sistema detecta sozinho qual foi enviado.</p>
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

    var ROTULOS_FORMATO = {
        longo: 'Formato longo — uma linha por resposta',
        largo: 'Formato largo — uma coluna por questão',
    };

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

        fetch('{{ route('avaliacoes.resultados.import.preview', $avaliacao) }}', {
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

                renderizarPreview(resultado.json);
            })
            .catch(function () {
                botaoImportar.disabled = false;
                painel.innerHTML = '<p class="text-sm text-red-700">Não foi possível ler o arquivo. Tente novamente.</p>';
            });
    }

    function renderizarPreview(info) {
        var campos = info.campos;
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
            + '<div class="px-3 py-2 bg-slate-50 border-b border-slate-200 text-sm font-semibold text-slate-700">'
            + escapeHtml(ROTULOS_FORMATO[info.formato] || 'Formato detectado')
            + '</div>'
            + (info.detalhe ? '<div class="px-3 py-2 text-sm text-slate-600 border-b border-slate-200">' + escapeHtml(info.detalhe) + '</div>' : '')
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

<div class="mt-8 max-w-3xl">
    <h2 class="font-semibold text-slate-800 mb-1">Dois formatos aceitos</h2>
    <p class="text-sm text-slate-500 mb-4">
        O sistema detecta sozinho qual dos dois foi enviado — não precisa avisar. A linha 1 é sempre o cabeçalho.
    </p>

    <div class="flex flex-wrap gap-3 mb-3 text-xs font-medium">
        <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span> Obrigatória</span>
        <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-slate-400"></span> Opcional</span>
    </div>

    <h3 class="font-semibold text-slate-700 mb-1">Formato "longo" — uma linha por resposta</h3>
    <p class="text-sm text-slate-500 mb-3">
        Se o aluno respondeu 50 questões, são 50 linhas para ele, não uma coluna por questão.
    </p>

    <div class="overflow-x-auto border border-slate-200 rounded-xl shadow-sm bg-white mb-3">
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

    <ul class="mb-6 space-y-1.5 text-sm text-slate-600 list-disc list-inside">
        <li><strong>RA</strong> ou <strong>CPF</strong> — só precisa de uma das duas por linha (linha 4 do exemplo usa só CPF).</li>
        <li><strong>Resposta</strong> precisa existir como coluna, mas pode ficar vazia numa linha — significa que o aluno deixou aquela questão em branco (linha 3 do exemplo).</li>
    </ul>

    <h3 class="font-semibold text-slate-700 mb-1">Formato "largo" — uma linha por aluno</h3>
    <p class="text-sm text-slate-500 mb-3">
        Uma coluna por questão (cabeçalho "Q1", "Q2", "Q3"...) — comum em planilhas exportadas de leitora óptica.
    </p>

    <div class="overflow-x-auto border border-slate-200 rounded-xl shadow-sm bg-white mb-3">
        <table class="text-xs border-collapse w-full">
            <thead>
                <tr>
                    @foreach ([
                        ['RA', 'obrigatoria'], ['Q1', 'obrigatoria'], ['Q2', 'obrigatoria'], ['Q3', 'obrigatoria'], ['...', 'opcional'],
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
                    ['2026001', 'B', 'A', 'C', '...'],
                    ['2026002', 'D', 'A', '', '...'],
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

    <ul class="space-y-1.5 text-sm text-slate-600 list-disc list-inside">
        <li>Cada coluna de questão vale como uma resposta — célula vazia (ou "BLANK"/"Em branco") significa que o aluno não respondeu aquela questão.</li>
        <li>Uma leitora óptica que marca mais de uma alternativa pode gravar algo como "MULT" ou "(A,C)" na célula — o sistema aceita esse texto normalmente e ele já conta como erro (nunca bate com um gabarito de uma letra só).</li>
    </ul>

    <ul class="mt-4 space-y-1.5 text-sm text-slate-600 list-disc list-inside">
        <li><strong>Período</strong> só é necessário se o mesmo aluno puder refazer esta avaliação em períodos diferentes; sem essa coluna, todas as respostas do aluno nesta avaliação contam como uma tentativa única.</li>
        <li>Reimportar a mesma combinação de aluno + período + questão <strong>atualiza</strong> a resposta em vez de duplicar.</li>
        <li>Aceita <code class="bg-slate-100 px-1 rounded">.xlsx</code>, <code class="bg-slate-100 px-1 rounded">.xls</code> ou <code class="bg-slate-100 px-1 rounded">.csv</code>.</li>
    </ul>
</div>
@endsection
