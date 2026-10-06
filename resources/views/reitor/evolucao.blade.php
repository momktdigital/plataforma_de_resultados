@extends('layouts.app')

@section('title', 'Evolução — Painel da reitoria')

@php
    $fmt = fn ($v, $casas = 1) => $v === null ? '—' : number_format($v, $casas, ',', '.');
    $cursos = array_values($est['cursos']);
    $meta = $ctx['meta'];
    $corte = $ctx['corte'];
    $corteTxt = $fmt($corte, 0);
    $semestres = $evolucao['semestres'];
    $limiar = \App\Services\ReitorEvolucaoService::VARIACAO_RELEVANTE;

    $atualIndice = collect($semestres)->search(fn ($s) => $s['ehSelecionada']);
    $atualIndice = $atualIndice === false ? count($semestres) - 1 : $atualIndice;
    $atual = $semestres[$atualIndice] ?? null;

    // Semestre de referência: o pedido (?referencia=) se for anterior ao atual; senão o imediatamente anterior.
    $anteriores = array_slice($semestres, 0, max(0, $atualIndice));
    $pedido = (string) request()->query('referencia', '');
    $referencia = collect($anteriores)->firstWhere('periodoLetivo', $pedido) ?? (count($anteriores) ? $anteriores[count($anteriores) - 1] : null);

    $kpis = [
        ['Proficientes (≥ '.$corteTxt.'%)', 'proficienciaPct', 'border-t-emerald-500', 'bg-emerald-50 border-emerald-200', 'text-emerald-700'],
        ['% de acerto médio', 'media', 'border-t-violet-500', 'bg-white border-slate-200', 'text-[#1e3a5f]'],
        ['Participação', 'participacao', 'border-t-[#1e3a5f]', 'bg-white border-slate-200', 'text-[#1e3a5f]'],
    ];
    $delta = fn ($a, $b) => $a !== null && $b !== null ? round($a - $b, 1) : null;
    $setaTxt = fn (?float $d) => $d === null ? '—' : ($d > 0 ? '▲ +' : ($d < 0 ? '▼ −' : '= ')).$fmt(abs($d)).' pp';
    $classeDelta = fn (?float $d) => $d === null ? 'text-slate-500' : (abs($d) < $limiar ? 'text-slate-600' : ($d > 0 ? 'text-emerald-700' : 'text-red-700'));

    $porCurso = [];
    if ($atual !== null) {
        foreach ($cursos as $c) {
            $agora = $atual['cursos'][$c['chave']] ?? null;
            $antes = $referencia['cursos'][$c['chave']] ?? null;
            $porCurso[] = [
                'chave' => $c['chave'],
                'nome' => $c['nome'],
                'agora' => $agora,
                'antes' => $antes,
                'dProf' => $delta($agora['proficienciaPct'] ?? null, $antes['proficienciaPct'] ?? null),
                'dMedia' => $delta($agora['media'] ?? null, $antes['media'] ?? null),
                'dPart' => $delta($agora['participacao'] ?? null, $antes['participacao'] ?? null),
            ];
        }
    }

    $dados = [
        'meta' => $meta,
        'rotulos' => array_column($semestres, 'periodoLetivo'),
        'selecionada' => $atualIndice,
        'total' => [
            'prof' => array_map(fn ($s) => $s['total']['proficienciaPct'], $semestres),
            'media' => array_map(fn ($s) => $s['total']['media'], $semestres),
            'part' => array_map(fn ($s) => $s['total']['participacao'], $semestres),
            'n' => array_map(fn ($s) => $s['total']['fizeram'], $semestres),
        ],
        'cursos' => array_map(fn ($c) => [
            'chave' => $c['chave'],
            'nome' => $c['nome'],
            'cor' => $ctx['cores'][$c['chave']] ?? '#64748b',
            'prof' => array_map(fn ($s) => $s['cursos'][$c['chave']]['proficienciaPct'] ?? null, $semestres),
            'media' => array_map(fn ($s) => $s['cursos'][$c['chave']]['media'] ?? null, $semestres),
            'part' => array_map(fn ($s) => $s['cursos'][$c['chave']]['participacao'] ?? null, $semestres),
            'n' => array_map(fn ($s) => $s['cursos'][$c['chave']]['n'] ?? 0, $semestres),
        ], $cursos),
        'variacao' => array_values(array_filter(array_map(fn ($l) => $l['dProf'] === null ? null : ['chave' => $l['chave'], 'nome' => $l['nome'], 'delta' => $l['dProf'], 'de' => $l['antes']['proficienciaPct'], 'para' => $l['agora']['proficienciaPct']], $porCurso))),
        'referencia' => $referencia['periodoLetivo'] ?? null,
        'atual' => $atual['periodoLetivo'] ?? null,
        'limiar' => $limiar,
    ];
@endphp

@section('content')
@include('reitor._cabecalho', [
    'ctx' => $ctx,
    'aba' => 'evolucao',
    'chips' => [
        ['valor' => count($semestres), 'rotulo' => 'Semestres'],
    ],
])

@include('reitor._filtros', ['ctx' => $ctx, 'exportar' => true])

<div class="mb-2 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
    <i class="ph-bold ph-info mr-1" aria-hidden="true"></i>
    Série da categoria <strong>{{ $evolucao['categoria'] }}</strong>:
    @if (! $ctx['avaliacao']['avulsa'])
        em cada período letivo valem as avaliações da categoria escolhida (a mesma prova nos vários cursos).
    @else
        em cada período letivo vale uma avaliação (a escolhida, no semestre dela; nos demais, a de mais participantes). Para ver a instituição inteira, escolha só a categoria no filtro.
    @endif
    Cada ponto é uma foto do semestre — não acompanha os mesmos estudantes.
</div>

@if (count($semestres) < 2 || $atual === null)
    <div class="mt-4 bg-slate-50 border border-slate-200 text-slate-600 rounded-xl p-6 text-sm">
        <p class="font-semibold text-slate-700 mb-1">A evolução aparece quando houver ao menos dois períodos letivos.</p>
        Só há {{ count($semestres) === 1 ? 'um período letivo' : 'resultados' }} da categoria "{{ $evolucao['categoria'] }}" com os cursos selecionados.
        Importe a avaliação equivalente de outro semestre (ou escolha, no filtro, uma avaliação que tenha categoria) para acompanhar a evolução.
    </div>
@else
    <div class="grid gap-4 md:grid-cols-3 mb-2 mt-4">
        @foreach ($kpis as [$rotulo, $campo, $topo, $fundo, $cor])
            @php $d = $referencia ? $delta($atual['total'][$campo], $referencia['total'][$campo]) : null; @endphp
            <div class="rounded-2xl border {{ $fundo }} border-t-4 {{ $topo }} p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wide text-slate-600">{{ $rotulo }}</p>
                <p class="mt-2 font-mono text-4xl font-black {{ $cor }}">{{ $fmt($atual['total'][$campo]) }}<span class="text-2xl">%</span></p>
                <p class="mt-1 text-xs text-slate-600">{{ $atual['periodoLetivo'] }}@if ($referencia) &middot; {{ $referencia['periodoLetivo'] }}: {{ $fmt($referencia['total'][$campo]) }}%@endif</p>
                @if ($referencia)<p class="mt-1 text-xs font-semibold {{ $classeDelta($d) }}">{{ $setaTxt($d) }}</p>@endif
            </div>
        @endforeach
    </div>

    {{-- 1 · Série institucional --}}
    @include('reitor._secao', ['numero' => 1, 'titulo' => 'A instituição ao longo dos semestres', 'id' => 'secao-evolucao-inst'])
    <section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="secao-evolucao-inst">
        <div class="relative h-80"><canvas id="grafico-evolucao-inst" data-titulo="Proficiência, média e participação da instituição por período letivo"></canvas></div>
        @include('reitor._rodape-quadro', ['id' => 'evolucao-inst', 'sobre' => $sobre['evolucao_institucional'], 'leitura' => $leituras['evolucao_institucional'] ?? null])
    </section>

    {{-- 2 · Variação por curso --}}
    @include('reitor._secao', ['numero' => 2, 'titulo' => 'O que mudou em cada curso', 'id' => 'secao-evolucao-cursos'])
    <section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="secao-evolucao-cursos">
        <form method="GET" action="{{ url()->current() }}" class="mb-4 flex flex-wrap items-end gap-3 print:hidden">
            @foreach (array_filter($ctx['filtro'], fn ($v) => $v !== '') as $nomeFiltro => $valorFiltro)
                <input type="hidden" name="{{ $nomeFiltro }}" value="{{ $valorFiltro }}">
            @endforeach
            @if ($ctx['filtrando'])
                @foreach ($ctx['cursosSelecionados'] as $chave)
                    <input type="hidden" name="cursos[]" value="{{ $chave }}">
                @endforeach
            @endif
            <div>
                <label for="seletor-referencia" class="block text-xs font-bold uppercase tracking-wide text-slate-600 mb-1">{{ $atual['periodoLetivo'] }} comparado com</label>
                <select id="seletor-referencia" name="referencia" onchange="this.form.submit()" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white min-w-[140px]">
                    @foreach (array_reverse($anteriores) as $s)
                        <option value="{{ $s['periodoLetivo'] }}" @selected($referencia && $s['periodoLetivo'] === $referencia['periodoLetivo'])>{{ $s['periodoLetivo'] }}</option>
                    @endforeach
                </select>
            </div>
            <noscript><button type="submit" class="bg-slate-800 text-white font-semibold rounded-lg px-4 py-2 text-sm">Comparar</button></noscript>
        </form>

        @if ($referencia === null)
            <p class="text-sm text-slate-600">Não há período anterior para comparar.</p>
        @else
            <div class="grid gap-6 xl:grid-cols-2">
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-wide text-emerald-800 mb-3">Variação de proficientes: {{ $referencia['periodoLetivo'] }} → {{ $atual['periodoLetivo'] }} (pp)</h3>
                    <div class="relative" style="height: {{ 90 + 34 * max(1, count($dados['variacao'])) }}px"><canvas id="grafico-variacao" data-titulo="Variação de proficientes por curso entre os dois períodos"></canvas></div>
                </div>
                <div class="overflow-x-auto">
                    <table data-ordenavel class="w-full text-sm">
                        <caption class="sr-only">Para cada curso: proficientes, média e participação nos dois períodos e a variação em pontos percentuais.</caption>
                        <thead class="text-left text-[11px] font-bold uppercase tracking-wide text-slate-600">
                            <tr class="border-b border-slate-200">
                                @foreach ([['Curso', 'texto'], ['Proficientes', 'numero'], ['Média', 'numero'], ['Participação', 'numero']] as [$rotulo, $tipo])
                                    <th scope="col" data-ordem="{{ $tipo }}" class="px-3 py-2 {{ $tipo === 'numero' ? 'text-right' : '' }}">
                                        <button type="button" class="inline-flex items-center gap-1 font-bold uppercase tracking-wide hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">{{ $rotulo }} <i class="ph ph-arrows-down-up text-xs" aria-hidden="true"></i></button>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($porCurso as $l)
                                <tr class="border-b border-slate-100">
                                    <td data-valor="{{ $l['nome'] }}" class="px-3 py-2.5 font-medium text-slate-800">@include('reitor._nome-curso', ['chave' => $l['chave'], 'nome' => $l['nome']])</td>
                                    @foreach ([['proficienciaPct', 'dProf'], ['media', 'dMedia'], ['participacao', 'dPart']] as [$campo, $dCampo])
                                        <td data-valor="{{ $l[$dCampo] }}" class="px-3 py-2.5 text-right">
                                            <span class="block font-mono">{{ $fmt($l['antes'][$campo] ?? null) }}% → <strong>{{ $fmt($l['agora'][$campo] ?? null) }}%</strong></span>
                                            <span class="block font-mono text-xs font-semibold {{ $classeDelta($l[$dCampo]) }}">{{ $setaTxt($l[$dCampo]) }}</span>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="bg-slate-50 font-bold text-slate-800">
                                <td class="px-3 py-2.5">Total da visão</td>
                                @foreach (['proficienciaPct', 'media', 'participacao'] as $campo)
                                    @php $d = $delta($atual['total'][$campo], $referencia['total'][$campo]); @endphp
                                    <td class="px-3 py-2.5 text-right">
                                        <span class="block font-mono">{{ $fmt($referencia['total'][$campo]) }}% → {{ $fmt($atual['total'][$campo]) }}%</span>
                                        <span class="block font-mono text-xs {{ $classeDelta($d) }}">{{ $setaTxt($d) }}</span>
                                    </td>
                                @endforeach
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        @endif
        @include('reitor._rodape-quadro', ['id' => 'evolucao-cursos', 'sobre' => $sobre['evolucao_cursos'], 'leitura' => $leituras['evolucao_cursos'] ?? null])
    </section>

    {{-- 3 · Curso × semestre --}}
    @include('reitor._secao', ['numero' => 3, 'titulo' => 'Cada curso, semestre a semestre', 'id' => 'secao-curso-semestre'])
    <section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="secao-curso-semestre">
        <div class="mb-3 flex flex-wrap items-center gap-2" role="group" aria-label="Indicador mostrado">
            @foreach ([['prof', 'Proficientes'], ['media', 'Média de acerto'], ['part', 'Participação']] as $i => [$valor, $rotulo])
                <button type="button" data-metrica-evolucao="{{ $valor }}" aria-pressed="{{ $i === 0 ? 'true' : 'false' }}"
                        class="rounded-full border px-4 py-1.5 text-sm font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-primary {{ $i === 0 ? 'border-[#1e3a5f] bg-[#1e3a5f] text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">{{ $rotulo }}</button>
            @endforeach
        </div>
        <div class="relative h-96"><canvas id="grafico-evolucao-cursos" data-titulo="Indicador de cada curso por período letivo"></canvas></div>
        <div class="mt-4 overflow-x-auto"><table id="tabela-curso-semestre" class="w-full text-sm border-separate" style="border-spacing: 2px"></table></div>
    </section>
@endif

@include('reitor._base')
@if ($atual !== null && count($semestres) >= 2)
<script>
(function () {
    var dados = @json($dados);
    var V = window.ReitorViz;

    // 1 · Instituição: proficiência, média e participação por semestre (o semestre em foco com ponto maior).
    var raio = dados.rotulos.map(function (r, i) { return i === dados.selecionada ? 8 : 5; });
    function linha(rotulo, valores, cor, tracejado) {
        return { label: rotulo, data: valores, borderColor: cor, backgroundColor: cor, pointBackgroundColor: cor, pointRadius: raio, borderDash: tracejado || [], tension: 0.3 };
    }
    V.criar('grafico-evolucao-inst', {
        type: 'line',
        data: {
            labels: dados.rotulos,
            datasets: [
                linha('Proficientes (%)', dados.total.prof, V.cores.verde),
                linha('Média de acerto (%)', dados.total.media, V.cores.marinho),
                linha('Participação (%)', dados.total.part, V.cores.ambar, [6, 4]),
            ],
        },
        options: {
            maintainAspectRatio: false,
            scales: { y: V.eixoPct(), x: { title: { display: true, text: 'Período letivo' }, grid: { display: false } } },
            plugins: {
                legend: V.legenda('top'),
                linhaReferencia: { linhas: [{ valor: dados.meta, cor: V.cores.ambar, rotulo: 'Meta de participação ' + V.fmt(dados.meta, 0) + '%' }] },
                tooltip: { callbacks: {
                    label: function (item) { return item.dataset.label + ': ' + V.pct(item.raw); },
                    afterBody: function (itens) { return 'Participantes: ' + dados.total.n[itens[0].dataIndex]; },
                } },
            },
        },
    });

    // 2 · Variação de proficientes por curso (barras divergentes; cinza = dentro do ruído).
    if (dados.variacao.length && document.getElementById('grafico-variacao')) {
        var ordenada = dados.variacao.slice().sort(function (a, b) { return b.delta - a.delta; });
        V.criar('grafico-variacao', {
            type: 'bar',
            data: {
                labels: ordenada.map(function (v) { return v.nome; }),
                datasets: [{
                    label: 'Variação (pp)',
                    data: ordenada.map(function (v) { return v.delta; }),
                    backgroundColor: ordenada.map(function (v) { return Math.abs(v.delta) < dados.limiar ? V.cores.cinza : (v.delta > 0 ? V.cores.verde : V.cores.vermelho); }),
                    maxBarThickness: 22,
                }],
            },
            options: {
                indexAxis: 'y',
                maintainAspectRatio: false,
                layout: { padding: { right: 60 } },
                scales: { x: { title: { display: true, text: 'pontos percentuais' } }, y: { grid: { display: false } } },
                plugins: {
                    legend: { display: false },
                    rotulosValores: { dataset: 0, horizontal: true, formato: function (v) { return V.pp(v); } },
                    tooltip: { callbacks: { label: function (item) {
                        var v = ordenada[item.dataIndex];
                        return V.pp(v.delta) + ' (' + V.pct(v.de) + ' → ' + V.pct(v.para) + ')';
                    } } },
                },
            },
        });
    }

    // Drill-down (reitor): clicar numa barra de variação abre a análise do curso.
    if (dados.variacao.length && document.getElementById('grafico-variacao')) {
        V.habilitarDrill(Chart.getChart('grafico-variacao'), function (el) { return ordenada[el.index] ? { chave: ordenada[el.index].chave } : null; });
    }

    // 3 · Cada curso, semestre a semestre: linhas + tabela, com troca de indicador.
    var metrica = 'prof';
    var grafico = null;
    var titulos = { prof: '% de proficientes', media: '% de acerto médio', part: '% de participação' };
    function conjunto() {
        return dados.cursos.map(function (c) {
            return { label: c.nome, data: c[metrica], borderColor: c.cor, backgroundColor: c.cor, pointBackgroundColor: c.cor, pointRadius: raio, tension: 0.3 };
        });
    }
    function desenharGrafico() {
        if (grafico) {
            grafico.data.datasets = conjunto();
            grafico.options.scales.y.title.text = titulos[metrica];
            grafico.update();
            return;
        }
        grafico = V.criar('grafico-evolucao-cursos', {
            type: 'line',
            data: { labels: dados.rotulos, datasets: conjunto() },
            options: {
                maintainAspectRatio: false,
                interaction: { mode: 'nearest', intersect: true },
                scales: { y: V.eixoPct({ title: { display: true, text: titulos[metrica] } }), x: { title: { display: true, text: 'Período letivo' }, grid: { display: false } } },
                plugins: { legend: V.legenda('top'), tooltip: { callbacks: { label: function (item) { return item.dataset.label + ': ' + V.pct(item.raw); } } } },
            },
        });
    }
    function desenharTabela() {
        var html = '<thead class="text-[11px] font-bold uppercase tracking-wide text-slate-600"><tr><th scope="col" class="px-3 py-2 text-left">Curso</th>'
            + dados.rotulos.map(function (r) { return '<th scope="col" class="px-3 py-2 text-center">' + V.esc(r) + '</th>'; }).join('') + '</tr></thead><tbody>';
        dados.cursos.forEach(function (c) {
            html += '<tr><th scope="row" class="px-3 py-2 text-left font-medium text-slate-800">' + V.esc(c.nome) + '</th>';
            c[metrica].forEach(function (v) {
                if (v === null || v === undefined) {
                    html += '<td class="px-3 py-2 text-center" style="background:#f1f5f9;color:#64748b" aria-label="sem dados">·</td>';
                } else {
                    html += '<td class="px-3 py-2 text-center font-mono font-semibold" style="background:' + Viz.corSequencial(v) + ';color:' + Viz.tintaSobreSequencial(v) + '">' + V.fmt(v, 0) + '%</td>';
                }
            });
            html += '</tr>';
        });
        document.getElementById('tabela-curso-semestre').innerHTML = html + '</tbody>';
    }
    var botoes = document.querySelectorAll('[data-metrica-evolucao]');
    botoes.forEach(function (botao) {
        botao.addEventListener('click', function () {
            metrica = botao.getAttribute('data-metrica-evolucao');
            botoes.forEach(function (b) {
                var ativo = b === botao;
                b.setAttribute('aria-pressed', ativo ? 'true' : 'false');
                b.className = 'rounded-full border px-4 py-1.5 text-sm font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-primary '
                    + (ativo ? 'border-[#1e3a5f] bg-[#1e3a5f] text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50');
            });
            desenharGrafico();
            desenharTabela();
        });
    });
    desenharGrafico();
    // clicar num ponto abre a análise do curso naquele semestre
    V.habilitarDrill(grafico, function (el) {
        var curso = dados.cursos[el.datasetIndex];
        return curso ? { chave: curso.chave, extra: { periodo_letivo: dados.rotulos[el.index] } } : null;
    });
    desenharTabela();
})();
</script>
@endif
@endsection
