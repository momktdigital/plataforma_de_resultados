@extends('layouts.app')

@section('title', "Avaliação #{$avaliacao->codigo} — Avaliações")

@section('content')
<div class="mb-6 flex items-start justify-between gap-4">
    <div>
        <a href="{{ route('avaliacoes.index') }}" class="text-sm text-slate-500 hover:underline">&larr; Todas as avaliações</a>
        <h1 class="text-2xl font-bold mt-2">Avaliação #{{ $avaliacao->codigo }} @if($avaliacao->nome) — {{ $avaliacao->nome }} @endif</h1>
        @if ($avaliacao->tipo)
            <p class="text-slate-500">{{ $avaliacao->tipo }}</p>
        @endif
        @if ($avaliacao->link_comentado)
            <a href="{{ $avaliacao->link_comentado }}" target="_blank" rel="noopener" class="text-sm text-emerald-700 hover:underline">
                Gabarito comentado &nearr;
            </a>
        @endif
    </div>
    <form method="POST" action="{{ route('avaliacoes.destroy', $avaliacao) }}"
          onsubmit="return confirm('ATENÇÃO: excluir esta avaliação remove também TODAS as questões, respostas e métricas associadas. Continuar?');">
        @csrf
        @method('DELETE')
        <button type="submit" class="text-sm text-red-600 border border-red-200 hover:bg-red-50 rounded-lg px-3 py-1.5">
            Excluir avaliação
        </button>
    </form>
</div>

@if (session('importIgnoradas') && count(session('importIgnoradas')))
    <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 text-sm">
        <p class="font-semibold mb-2">{{ count(session('importIgnoradas')) }} linha(s) ignorada(s):</p>
        <ul class="list-disc pl-5 space-y-0.5 max-h-48 overflow-y-auto">
            @foreach (session('importIgnoradas') as $ignorada)
                <li>Linha {{ $ignorada['linha'] }}: {{ $ignorada['motivo'] }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="grid sm:grid-cols-2 gap-6 mb-6">
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
        <h2 class="font-semibold mb-1">Questões e gabarito</h2>
        <p class="text-sm text-slate-500 mb-4">{{ $avaliacao->questoes_count }} questão(ões) cadastrada(s).</p>
        <a href="{{ route('avaliacoes.questoes.import', $avaliacao) }}"
           class="inline-block bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-4 py-2 text-sm">
            Importar questões
        </a>
    </div>

    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
        <h2 class="font-semibold mb-1">Resultados</h2>
        <p class="text-sm text-slate-500 mb-4">
            {{ $avaliacao->resultados_count }} resposta(s) registrada(s).
            @if ($avaliacao->metricas_count)
                &middot; {{ $avaliacao->metricas_count }} métrica(s) agregada(s) (ex.: notas finais).
            @endif
        </p>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('avaliacoes.resultados.import', $avaliacao) }}"
               class="inline-block bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-4 py-2 text-sm">
                Importar resultados
            </a>
            <a href="{{ route('avaliacoes.respondentes.index', $avaliacao) }}"
               class="inline-block border border-slate-300 text-slate-700 hover:bg-slate-50 font-semibold rounded-lg px-4 py-2 text-sm">
                Ver por aluno
            </a>
            <a href="{{ route('avaliacoes.bi', $avaliacao) }}"
               class="inline-block border border-slate-300 text-slate-700 hover:bg-slate-50 font-semibold rounded-lg px-4 py-2 text-sm">
                Painel BI
            </a>
        </div>
    </div>
</div>

{{-- Editar configurações básicas --}}
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
    <h2 class="font-semibold mb-4">Editar configurações</h2>
    <form method="POST" action="{{ route('avaliacoes.update', $avaliacao) }}" class="flex flex-wrap gap-3 items-end">
        @csrf
        @method('PUT')
        <div class="flex-1 min-w-[200px]">
            <label class="block text-sm font-medium mb-1" for="nome">Nome</label>
            <input id="nome" name="nome" type="text" value="{{ old('nome', $avaliacao->nome) }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div class="flex-1 min-w-[160px]">
            <label class="block text-sm font-medium mb-1" for="tipo">Tipo</label>
            <input id="tipo" name="tipo" type="text" value="{{ old('tipo', $avaliacao->tipo) }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div class="flex-1 min-w-[200px]">
            <label class="block text-sm font-medium mb-1" for="link_comentado">Link do gabarito comentado</label>
            <input id="link_comentado" name="link_comentado" type="url" value="{{ old('link_comentado', $avaliacao->link_comentado) }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div class="flex-1 min-w-[160px]">
            <label class="block text-sm font-medium mb-1" for="categoria_id">Categoria</label>
            <select id="categoria_id" name="categoria_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="">— Sem categoria —</option>
                @foreach ($opcoesCategoria as $opcao)
                    <option value="{{ $opcao['id'] }}" {{ (int) old('categoria_id', $avaliacao->categoria_id) === $opcao['id'] ? 'selected' : '' }}>
                        {{ $opcao['label'] }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="min-w-[140px]">
            <label class="block text-sm font-medium mb-1" for="data_avaliacao">Data da avaliação</label>
            <input id="data_avaliacao" name="data_avaliacao" type="text" placeholder="DD/MM/AAAA"
                   value="{{ old('data_avaliacao', $avaliacao->data_avaliacao?->format('d/m/Y')) }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <button type="submit" class="bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-lg px-5 py-2 text-sm">
            Salvar
        </button>
    </form>
</div>

<script src="https://unpkg.com/imask"></script>
<script>
    IMask(document.getElementById('data_avaliacao'), {
        mask: Date,
        pattern: 'd/m/Y',
        blocks: {
            d: {mask: IMask.MaskedRange, from: 1, to: 31, maxLength: 2},
            m: {mask: IMask.MaskedRange, from: 1, to: 12, maxLength: 2},
            Y: {mask: IMask.MaskedRange, from: 1900, to: 2999},
        },
        format: function (date) {
            var day = String(date.getDate()).padStart(2, '0');
            var month = String(date.getMonth() + 1).padStart(2, '0');
            return [day, month, date.getFullYear()].join('/');
        },
        parse: function (str) {
            var partes = str.split('/');
            return new Date(partes[2], partes[1] - 1, partes[0]);
        },
    });
</script>

{{-- Editor manual de gabarito --}}
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
    <div class="flex items-center justify-between mb-1">
        <h2 id="editor-questao-titulo" class="font-semibold">Adicionar questão</h2>
        <button type="button" id="editor-questao-cancelar" class="hidden text-sm text-slate-500 hover:underline">Cancelar edição</button>
    </div>
    <p class="text-sm text-slate-500 mb-4">
        Adicione ou corrija uma questão sem precisar reimportar a planilha inteira — clique em "Editar" numa linha da
        tabela abaixo para carregar todos os dados dela aqui.
    </p>

    <form method="POST" action="{{ route('avaliacoes.questoes.store', $avaliacao) }}" id="form-editor-questao" class="space-y-4 mb-6">
        @csrf

        <div class="grid sm:grid-cols-2 lg:grid-cols-6 gap-4">
            <div>
                <label class="block text-sm font-medium mb-1" for="numero">Número</label>
                <input id="numero" name="numero" type="number" min="1" required
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" for="gabarito">Gabarito</label>
                <input id="gabarito" name="gabarito" type="text" maxlength="10" required
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm uppercase">
            </div>
            <div class="lg:col-span-2">
                <label class="block text-sm font-medium mb-1" for="area">Área</label>
                <input id="area" name="area" type="text" maxlength="255" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div class="lg:col-span-2">
                <label class="block text-sm font-medium mb-1" for="tema">Tema</label>
                <input id="tema" name="tema" type="text" maxlength="255" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div class="lg:col-span-3">
                <label class="block text-sm font-medium mb-1" for="habilidade">Habilidade</label>
                <input id="habilidade" name="habilidade" type="text" maxlength="255" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" for="bloom_nivel">Bloom (nível)</label>
                <input id="bloom_nivel" name="bloom_nivel" type="text" maxlength="255" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" for="bloom_verbo">Bloom (verbo)</label>
                <input id="bloom_verbo" name="bloom_verbo" type="text" maxlength="255" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" for="miller_nivel">Miller (nível)</label>
                <input id="miller_nivel" name="miller_nivel" type="text" maxlength="255" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" for="dificuldade_pedagogica">Dificuldade Pedagógica</label>
                <select id="dificuldade_pedagogica" name="dificuldade_pedagogica" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">—</option>
                    <option value="facil">Fácil</option>
                    <option value="medio">Médio</option>
                    <option value="dificil">Difícil</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" for="dificuldade_tri">Dificuldade TRI</label>
                <input id="dificuldade_tri" name="dificuldade_tri" type="number" step="0.0001" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
        </div>

        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 mb-2">Referências externas</p>
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                @include('admin.avaliacoes._tag_input', ['name' => 'matriz_prova', 'label' => 'Matriz Prova'])
                @include('admin.avaliacoes._tag_input', ['name' => 'dcn', 'label' => 'DCN'])
                @include('admin.avaliacoes._tag_input', ['name' => 'portaria_inep', 'label' => 'Portaria INEP'])
                @include('admin.avaliacoes._tag_input', ['name' => 'ppc', 'label' => 'PPC'])
            </div>
        </div>

        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 mb-2">Matriz curricular</p>
            <p class="text-xs text-slate-400 mb-2">Informe na mesma ordem nas três listas — a 1ª entrada de cada uma forma um item da matriz, a 2ª forma outro, e assim por diante.</p>
            <div class="grid sm:grid-cols-3 gap-4">
                @include('admin.avaliacoes._tag_input', ['name' => 'matriz_periodo', 'label' => 'Período', 'placeholder' => 'Ex.: 1'])
                @include('admin.avaliacoes._tag_input', ['name' => 'matriz_disciplina', 'label' => 'Disciplina'])
                @include('admin.avaliacoes._tag_input', ['name' => 'matriz_codigo', 'label' => 'Código'])
            </div>
        </div>

        <button type="submit" id="editor-questao-submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-5 py-2 text-sm">
            Salvar questão
        </button>
    </form>

    @if ($questoes->isEmpty())
        <p class="text-sm text-slate-400">Nenhuma questão cadastrada ainda.</p>
    @else
        <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
            <p class="text-xs text-slate-400">Role a tabela para o lado para ver todas as colunas.</p>
            <div class="flex gap-2 text-xs font-medium">
                <a href="{{ route('avaliacoes.questoes.export.xlsx', $avaliacao) }}" class="inline-flex items-center gap-1 border border-slate-300 text-slate-700 hover:bg-slate-50 rounded-lg px-3 py-1.5">
                    <i class="ph ph-file-xls"></i> Exportar .xlsx
                </a>
                <a href="{{ route('avaliacoes.questoes.export.csv', $avaliacao) }}" class="inline-flex items-center gap-1 border border-slate-300 text-slate-700 hover:bg-slate-50 rounded-lg px-3 py-1.5">
                    <i class="ph ph-file-csv"></i> Exportar .csv
                </a>
                <a href="{{ route('avaliacoes.questoes.export.pdf', $avaliacao) }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 border border-slate-300 text-slate-700 hover:bg-slate-50 rounded-lg px-3 py-1.5">
                    <i class="ph ph-file-pdf"></i> Exportar .pdf
                </a>
            </div>
        </div>
        <div class="overflow-x-auto border border-slate-200 rounded-xl">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-left">
                    <tr>
                        <th class="px-3 py-2 whitespace-nowrap">Nº</th>
                        <th class="px-3 py-2 whitespace-nowrap">Gabarito</th>
                        <th class="px-3 py-2 whitespace-nowrap">Área</th>
                        <th class="px-3 py-2 whitespace-nowrap">Tema</th>
                        <th class="px-3 py-2 whitespace-nowrap">Habilidade</th>
                        <th class="px-3 py-2 whitespace-nowrap">Bloom (nível)</th>
                        <th class="px-3 py-2 whitespace-nowrap">Bloom (verbo)</th>
                        <th class="px-3 py-2 whitespace-nowrap">Miller</th>
                        <th class="px-3 py-2 whitespace-nowrap">Dif. Pedagógica</th>
                        <th class="px-3 py-2 whitespace-nowrap">Dif. TRI</th>
                        <th class="px-3 py-2 whitespace-nowrap">Matriz Prova</th>
                        <th class="px-3 py-2 whitespace-nowrap">DCN</th>
                        <th class="px-3 py-2 whitespace-nowrap">Portaria INEP</th>
                        <th class="px-3 py-2 whitespace-nowrap">PPC</th>
                        <th class="px-3 py-2 whitespace-nowrap">Matriz curricular</th>
                        <th class="px-3 py-2 whitespace-nowrap">Status</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($questoes as $questao)
                        @php
                            $referenciasPorTipo = $questao->referencias->groupBy('tipo')->map(fn ($grupo) => $grupo->pluck('valor')->values()->all());
                            $dadosQuestao = [
                                'numero' => $questao->numero,
                                'gabarito' => $questao->gabarito,
                                'area' => $questao->area,
                                'tema' => $questao->tema,
                                'habilidade' => $questao->habilidade,
                                'bloom_nivel' => $questao->bloom_nivel,
                                'bloom_verbo' => $questao->bloom_verbo,
                                'miller_nivel' => $questao->miller_nivel,
                                'dificuldade_pedagogica' => $questao->dificuldade_pedagogica,
                                'dificuldade_tri' => $questao->dificuldade_tri,
                                'matriz_prova' => $referenciasPorTipo->get('matriz_prova', []),
                                'dcn' => $referenciasPorTipo->get('dcn', []),
                                'portaria_inep' => $referenciasPorTipo->get('portaria_inep', []),
                                'ppc' => $referenciasPorTipo->get('ppc', []),
                                'matriz_periodo' => $questao->matrizes->pluck('periodo')->all(),
                                'matriz_disciplina' => $questao->matrizes->pluck('disciplina')->all(),
                                'matriz_codigo' => $questao->matrizes->pluck('codigo')->all(),
                            ];
                            $matrizCurricular = $questao->matrizes
                                ->map(fn ($m) => collect([$m->periodo, $m->disciplina, $m->codigo])->filter()->implode(' · '))
                                ->implode('; ');
                        @endphp
                        <tr class="{{ $questao->trashed() ? 'opacity-50' : '' }}">
                            <td class="px-3 py-2 font-mono whitespace-nowrap">{{ $questao->numero }}</td>
                            <td class="px-3 py-2 font-bold whitespace-nowrap">{{ $questao->gabarito ?: '—' }}</td>
                            <td class="px-3 py-2 text-slate-500 whitespace-nowrap">{{ $questao->area ?: '—' }}</td>
                            <td class="px-3 py-2 text-slate-500 whitespace-nowrap">{{ $questao->tema ?: '—' }}</td>
                            <td class="px-3 py-2 text-slate-500 whitespace-nowrap">{{ $questao->habilidade ?: '—' }}</td>
                            <td class="px-3 py-2 text-slate-500 whitespace-nowrap">{{ $questao->bloom_nivel ?: '—' }}</td>
                            <td class="px-3 py-2 text-slate-500 whitespace-nowrap">{{ $questao->bloom_verbo ?: '—' }}</td>
                            <td class="px-3 py-2 text-slate-500 whitespace-nowrap">{{ $questao->miller_nivel ?: '—' }}</td>
                            <td class="px-3 py-2 text-slate-500 whitespace-nowrap">{{ $questao->dificuldade_pedagogica ?: '—' }}</td>
                            <td class="px-3 py-2 text-slate-500 whitespace-nowrap">{{ $questao->dificuldade_tri ?? '—' }}</td>
                            <td class="px-3 py-2 text-slate-500 whitespace-nowrap">{{ $referenciasPorTipo->get('matriz_prova') ? implode('; ', $referenciasPorTipo->get('matriz_prova')) : '—' }}</td>
                            <td class="px-3 py-2 text-slate-500 whitespace-nowrap">{{ $referenciasPorTipo->get('dcn') ? implode('; ', $referenciasPorTipo->get('dcn')) : '—' }}</td>
                            <td class="px-3 py-2 text-slate-500 whitespace-nowrap">{{ $referenciasPorTipo->get('portaria_inep') ? implode('; ', $referenciasPorTipo->get('portaria_inep')) : '—' }}</td>
                            <td class="px-3 py-2 text-slate-500 whitespace-nowrap">{{ $referenciasPorTipo->get('ppc') ? implode('; ', $referenciasPorTipo->get('ppc')) : '—' }}</td>
                            <td class="px-3 py-2 text-slate-500 whitespace-nowrap">{{ $matrizCurricular ?: '—' }}</td>
                            <td class="px-3 py-2 text-slate-400 whitespace-nowrap">{{ $questao->trashed() ? 'Excluída' : '' }}</td>
                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                @if ($questao->trashed())
                                    <form method="POST" action="{{ route('avaliacoes.questoes.restore', [$avaliacao, $questao->id]) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-blue-600 hover:underline">Restaurar</button>
                                    </form>
                                @else
                                    <button type="button" class="questao-editar-btn text-emerald-700 hover:underline mr-3" data-questao='@json($dadosQuestao)'>Editar</button>
                                    <form method="POST" action="{{ route('avaliacoes.questoes.destroy', [$avaliacao, $questao]) }}" class="inline"
                                          onsubmit="return confirm('Excluir a questão {{ $questao->numero }}?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-500 hover:text-red-700">Excluir</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<script src="{{ asset('assets/js/tag-input.js') }}"></script>
<script>
(function () {
    var form = document.getElementById('form-editor-questao');
    var titulo = document.getElementById('editor-questao-titulo');
    var btnSubmit = document.getElementById('editor-questao-submit');
    var btnCancelar = document.getElementById('editor-questao-cancelar');

    var camposSimples = [
        'numero', 'gabarito', 'area', 'tema', 'habilidade',
        'bloom_nivel', 'bloom_verbo', 'miller_nivel',
        'dificuldade_pedagogica', 'dificuldade_tri',
    ];
    var camposChips = ['matriz_prova', 'dcn', 'portaria_inep', 'ppc', 'matriz_periodo', 'matriz_disciplina', 'matriz_codigo'];

    function resetarFormulario() {
        form.reset();
        TagInput.clearAll();
        titulo.textContent = 'Adicionar questão';
        btnSubmit.textContent = 'Salvar questão';
        btnCancelar.classList.add('hidden');
    }

    document.querySelectorAll('.questao-editar-btn').forEach(function (botao) {
        botao.addEventListener('click', function () {
            var dados = JSON.parse(botao.dataset.questao);

            camposSimples.forEach(function (campo) {
                var el = document.getElementById(campo);
                if (el) {
                    el.value = dados[campo] === null || dados[campo] === undefined ? '' : dados[campo];
                }
            });

            camposChips.forEach(function (campo) {
                TagInput.setValues(campo, dados[campo]);
            });

            titulo.textContent = 'Editando a questão ' + dados.numero;
            btnSubmit.textContent = 'Salvar alterações';
            btnCancelar.classList.remove('hidden');

            form.scrollIntoView({behavior: 'smooth', block: 'start'});
        });
    });

    btnCancelar.addEventListener('click', resetarFormulario);
})();
</script>

{{-- Questões críticas --}}
@if (! empty($estatisticasErro))
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
        <h2 class="font-semibold mb-1">Questões críticas</h2>
        <p class="text-sm text-slate-500 mb-4">Maiores índices de erro entre os respondentes desta avaliação.</p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-left">
                    <tr>
                        <th class="px-3 py-2">Questão</th>
                        <th class="px-3 py-2">Acertos</th>
                        <th class="px-3 py-2">Erros</th>
                        <th class="px-3 py-2">Em branco</th>
                        <th class="px-3 py-2">Taxa de erro</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($estatisticasErro as $stat)
                        <tr>
                            <td class="px-3 py-2 font-mono">Q{{ $stat['numero'] }}</td>
                            <td class="px-3 py-2">{{ $stat['acertos'] }}</td>
                            <td class="px-3 py-2">{{ $stat['erros'] }}</td>
                            <td class="px-3 py-2">{{ $stat['em_branco'] }}</td>
                            <td class="px-3 py-2 font-bold text-red-600">{{ $stat['taxa_erro'] }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
