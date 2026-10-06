@extends('layouts.app')

@section('title', 'Desempenho — Painel da reitoria')

@php
    $fmt = fn ($v, $casas = 1) => $v === null ? '—' : number_format($v, $casas, ',', '.');
    $cursos = array_values($est['cursos']);
    $total = $est['total'];
    $corte = $ctx['corte'];
    $corteTxt = $fmt($corte, 0);

    $dados = [
        'corte' => $corte,
        'faixas' => array_map(fn ($f) => $f['rotulo'], $faixas),
        'total' => [
            'nome' => 'Total da visão',
            'n' => $total['n'],
            'media' => $total['media'],
            'mediana' => $total['mediana'],
            'faixas' => array_column($total['faixas'], 'pct'),
            'distribuicao' => $total['distribuicao'],
        ],
        'cursos' => array_map(fn ($c) => [
            'chave' => $c['chave'],
            'nome' => $c['nome'],
            'cor' => $ctx['cores'][$c['chave']] ?? '#64748b',
            'n' => $c['n'],
            'media' => $c['media'],
            'mediana' => $c['mediana'],
            'q1' => $c['q1'],
            'q3' => $c['q3'],
            'p10' => $c['p10'],
            'p90' => $c['p90'],
            'faixas' => array_column($c['faixas'], 'pct'),
            'distribuicao' => $c['distribuicao'],
        ], $cursos),
    ];
@endphp

@section('content')
@include('reitor._cabecalho', [
    'ctx' => $ctx,
    'aba' => 'desempenho',
    'chips' => [
        ['valor' => $fmt($total['media']).'%', 'rotulo' => 'Média'],
        ['valor' => $fmt($total['mediana']).'%', 'rotulo' => 'Mediana'],
    ],
])

@include('reitor._filtros', ['ctx' => $ctx, 'exportar' => true])

<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 mb-2">
    <div class="rounded-2xl border border-slate-200 border-t-4 border-t-[#1e3a5f] bg-white p-5 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wide text-slate-600">Média do conjunto</p>
        <p class="mt-2 font-mono text-4xl font-black text-[#1e3a5f]">{{ $fmt($total['media']) }}<span class="text-2xl">%</span></p>
        <p class="mt-1 text-xs text-slate-600">{{ $fmt($total['n'], 0) }} estudantes com nota</p>
    </div>
    <div class="rounded-2xl border border-slate-200 border-t-4 border-t-amber-500 bg-white p-5 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wide text-slate-600">Mediana</p>
        <p class="mt-2 font-mono text-4xl font-black text-[#1e3a5f]">{{ $fmt($total['mediana']) }}<span class="text-2xl">%</span></p>
        <p class="mt-1 text-xs text-slate-600">metade dos estudantes fica abaixo disso</p>
    </div>
    <div class="rounded-2xl border border-slate-200 border-t-4 border-t-violet-500 bg-white p-5 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wide text-slate-600">Metade central</p>
        <p class="mt-2 font-mono text-3xl font-black text-[#1e3a5f]">{{ $fmt($total['q1'], 0) }}–{{ $fmt($total['q3'], 0) }}<span class="text-xl">%</span></p>
        <p class="mt-1 text-xs text-slate-600">do 1º ao 3º quartil (amplitude de {{ $total['q1'] !== null ? $fmt($total['q3'] - $total['q1']) : '—' }} pp)</p>
    </div>
    <div class="rounded-2xl border border-emerald-200 border-t-4 border-t-emerald-500 bg-emerald-50 p-5 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wide text-emerald-800">Acima do critério (≥ {{ $corteTxt }}%)</p>
        <p class="mt-2 font-mono text-4xl font-black text-emerald-700">{{ $fmt($total['proficienciaPct']) }}<span class="text-2xl">%</span></p>
        <p class="mt-1 text-xs text-slate-600">{{ $fmt($total['proficientes'], 0) }} de {{ $fmt($total['n'], 0) }} estudantes</p>
    </div>
</div>

{{-- 1 · Média e mediana --}}
@include('reitor._secao', ['numero' => 1, 'titulo' => 'Percentual de acerto — média e mediana', 'id' => 'secao-media'])
<section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="secao-media">
    <div class="relative h-80"><canvas id="grafico-media" data-titulo="Média e mediana do percentual de acerto por curso"></canvas></div>
    @include('reitor._rodape-quadro', ['id' => 'media', 'sobre' => $sobre['media'], 'leitura' => $leituras['media'] ?? null])
</section>

{{-- 2 · Faixas --}}
@include('reitor._secao', ['numero' => 2, 'titulo' => 'Distribuição dos estudantes por faixa de acerto', 'id' => 'secao-faixas'])
<section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="secao-faixas">
    <div class="relative" style="height: {{ 130 + 34 * (count($cursos) + 1) }}px"><canvas id="grafico-faixas" data-titulo="Distribuição dos estudantes por faixa de acerto"></canvas></div>
    @include('reitor._rodape-quadro', ['id' => 'faixas', 'sobre' => $sobre['faixas'], 'leitura' => $leituras['faixas'] ?? null])
</section>

{{-- 3 · Dispersão --}}
@include('reitor._secao', ['numero' => 3, 'titulo' => 'Dispersão: quartis e amplitude de cada curso', 'id' => 'secao-dispersao'])
<section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="secao-dispersao">
    <div class="relative h-96"><canvas id="grafico-dispersao" data-titulo="Quartis e amplitude do percentual de acerto por curso"></canvas></div>
    @include('reitor._rodape-quadro', ['id' => 'dispersao', 'sobre' => $sobre['dispersao'], 'leitura' => $leituras['dispersao'] ?? null])
</section>

{{-- 4 · Formato da distribuição --}}
@include('reitor._secao', ['numero' => 4, 'titulo' => 'O formato do desempenho: histograma do conjunto', 'id' => 'secao-histograma'])
<section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="secao-histograma">
    <div class="mb-3 flex flex-wrap items-center gap-3">
        <label for="seletor-histograma" class="text-xs font-bold uppercase tracking-wide text-slate-600">Comparar com o curso</label>
        <select id="seletor-histograma" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white">
            <option value="">— nenhum —</option>
            @foreach ($cursos as $i => $c)
                <option value="{{ $i }}">{{ $c['nome'] }}</option>
            @endforeach
        </select>
    </div>
    <div class="relative h-80"><canvas id="grafico-histograma" data-titulo="Histograma do percentual de acerto do conjunto"></canvas></div>
    <p class="mt-2 text-xs text-slate-600">Cada barra reúne 5 pontos percentuais de acerto; a altura é a parte dos estudantes nessa faixa. A linha vermelha marca o critério de proficiência ({{ $corteTxt }}%).</p>
</section>

{{-- 5 · Tabela --}}
@include('reitor._secao', ['numero' => 5, 'titulo' => 'Resumo estatístico por curso', 'id' => 'secao-tabela'])
<section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="secao-tabela">
    <div class="overflow-x-auto">
        <table data-ordenavel class="w-full text-sm">
            <caption class="sr-only">Estudantes com nota, média, mediana, quartis, percentis, mínimo, máximo e proporção de proficientes por curso.</caption>
            <thead class="text-left text-[11px] font-bold uppercase tracking-wide text-slate-600">
                <tr class="border-b border-slate-200">
                    @foreach ([['Curso', 'texto'], ['N', 'numero'], ['Média', 'numero'], ['Mediana', 'numero'], ['1º quartil', 'numero'], ['3º quartil', 'numero'], ['P10', 'numero'], ['P90', 'numero'], ['Mínimo', 'numero'], ['Máximo', 'numero'], ['Proficientes', 'numero']] as [$rotulo, $tipo])
                        <th scope="col" data-ordem="{{ $tipo }}" class="px-3 py-2 {{ $tipo === 'numero' ? 'text-right' : '' }}">
                            <button type="button" class="inline-flex items-center gap-1 font-bold uppercase tracking-wide hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">{{ $rotulo }} <i class="ph ph-arrows-down-up text-xs" aria-hidden="true"></i></button>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($cursos as $c)
                    <tr class="border-b border-slate-100">
                        <td data-valor="{{ $c['nome'] }}" class="px-3 py-2.5 font-medium text-slate-800">@include('reitor._nome-curso', ['chave' => $c['chave'], 'nome' => $c['nome']])</td>
                        @foreach ([$c['n'], $c['media'], $c['mediana'], $c['q1'], $c['q3'], $c['p10'], $c['p90'], $c['minimo'], $c['maximo']] as $i => $valor)
                            <td data-valor="{{ $valor }}" class="px-3 py-2.5 text-right font-mono">{{ $i === 0 ? $fmt($valor, 0) : $fmt($valor) }}{{ $i === 0 ? '' : '%' }}</td>
                        @endforeach
                        <td data-valor="{{ $c['proficienciaPct'] }}" class="px-3 py-2.5 text-right font-mono font-bold">{{ $fmt($c['proficienciaPct']) }}%</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="bg-slate-50 font-bold text-slate-800">
                    <td class="px-3 py-2.5">Total da visão</td>
                    @foreach ([$total['n'], $total['media'], $total['mediana'], $total['q1'], $total['q3'], $total['p10'], $total['p90'], $total['minimo'], $total['maximo']] as $i => $valor)
                        <td class="px-3 py-2.5 text-right font-mono">{{ $i === 0 ? $fmt($valor, 0) : $fmt($valor) }}{{ $i === 0 ? '' : '%' }}</td>
                    @endforeach
                    <td class="px-3 py-2.5 text-right font-mono">{{ $fmt($total['proficienciaPct']) }}%</td>
                </tr>
            </tfoot>
        </table>
    </div>
</section>

@include('reitor._base')
<script>
(function () {
    var dados = @json($dados);
    var V = window.ReitorViz;
    var cursos = dados.cursos;
    var total = dados.total;

    // 1 · Média (barras) e mediana (losango), do maior para o menor, com a referência do critério.
    var porMedia = cursos.slice().sort(function (a, b) { return (b.media === null ? -1 : b.media) - (a.media === null ? -1 : a.media); });
    V.criar('grafico-media', {
        type: 'bar',
        data: {
            labels: porMedia.map(function (c) { return c.nome; }),
            datasets: [
                {
                    type: 'line',
                    label: 'Mediana',
                    data: porMedia.map(function (c) { return c.mediana; }),
                    showLine: false,
                    pointStyle: 'rectRot',
                    pointRadius: 7,
                    pointHoverRadius: 9,
                    pointBackgroundColor: V.cores.ambar,
                    pointBorderColor: '#ffffff',
                    order: 0,
                },
                {
                    label: 'Média',
                    data: porMedia.map(function (c) { return c.media; }),
                    backgroundColor: V.cores.marinho,
                    maxBarThickness: 64,
                    order: 1,
                },
            ],
        },
        options: {
            maintainAspectRatio: false,
            scales: { y: V.eixoPct(), x: { grid: { display: false } } },
            plugins: {
                legend: V.legenda('top'),
                linhaReferencia: { linhas: [{ valor: dados.corte, cor: V.cores.vermelho, rotulo: 'Referência ' + V.fmt(dados.corte, 0) + '%' }] },
                rotulosValores: { dataset: 1, formato: function (v) { return V.pct(v); } },
                tooltip: { callbacks: { label: function (item) { return item.dataset.label + ': ' + V.pct(item.raw); } } },
            },
        },
    });

    // 2 · Faixas de acerto: barras horizontais empilhadas até 100%, do curso com mais estudantes nas faixas altas ao menor.
    var coresFaixas = ['#b5001f', '#e07b2f', '#efb23d', '#42dcc0', '#00a67e'];
    var ordenados = cursos.slice().sort(function (a, b) {
        function alto(c) { return (c.faixas[3] || 0) + (c.faixas[4] || 0); }
        return alto(b) - alto(a);
    });
    var linhasFaixas = ordenados.map(function (c) { return { rotulo: c.nome + ' (n=' + c.n + ')', faixas: c.faixas, n: c.n }; })
        .concat([{ rotulo: 'TOTAL DA VISÃO (n=' + total.n + ')', faixas: total.faixas, n: total.n }]);
    V.criar('grafico-faixas', {
        type: 'bar',
        data: {
            labels: linhasFaixas.map(function (l) { return l.rotulo; }),
            datasets: dados.faixas.map(function (rotulo, i) {
                return {
                    label: rotulo,
                    data: linhasFaixas.map(function (l) { return l.faixas[i]; }),
                    backgroundColor: coresFaixas[i],
                    borderRadius: 0,
                    borderSkipped: false,
                };
            }),
        },
        options: {
            indexAxis: 'y',
            maintainAspectRatio: false,
            scales: {
                x: { stacked: true, min: 0, max: 100, ticks: { callback: function (v) { return v + '%'; } } },
                y: { stacked: true, grid: { display: false } },
            },
            plugins: {
                legend: V.legenda('top'),
                tooltip: { callbacks: { label: function (item) { return item.dataset.label + ': ' + V.pct(item.raw); } } },
            },
        },
    });

    // 3 · Dispersão: caixa (1º–3º quartil), haste (P10–P90) e losango (mediana).
    var porMediana = cursos.slice().sort(function (a, b) { return (b.mediana === null ? -1 : b.mediana) - (a.mediana === null ? -1 : a.mediana); });
    V.criar('grafico-dispersao', {
        type: 'bar',
        data: {
            labels: porMediana.map(function (c) { return c.nome; }),
            datasets: [
                {
                    type: 'line', label: 'Mediana', showLine: false, pointStyle: 'rectRot', pointRadius: 7, pointHoverRadius: 9,
                    pointBackgroundColor: V.cores.ambar, pointBorderColor: '#ffffff',
                    data: porMediana.map(function (c) { return c.mediana; }), order: 0,
                },
                {
                    label: '1º a 3º quartil', backgroundColor: V.cores.marinho, borderRadius: 3, borderSkipped: false, maxBarThickness: 30, grouped: false, order: 1,
                    data: porMediana.map(function (c) { return c.q1 === null ? null : [c.q1, c.q3]; }),
                },
                {
                    label: 'P10 a P90', backgroundColor: 'rgba(30, 58, 95, 0.28)', borderRadius: 3, borderSkipped: false, maxBarThickness: 6, grouped: false, order: 2,
                    data: porMediana.map(function (c) { return c.p10 === null ? null : [c.p10, c.p90]; }),
                },
            ],
        },
        options: {
            maintainAspectRatio: false,
            scales: { y: V.eixoPct(), x: { grid: { display: false } } },
            plugins: {
                legend: V.legenda('top'),
                linhaReferencia: { linhas: [{ valor: dados.corte, cor: V.cores.vermelho, rotulo: 'Critério ' + V.fmt(dados.corte, 0) + '%' }] },
                tooltip: { callbacks: { label: function (item) {
                    var c = porMediana[item.dataIndex];
                    if (item.dataset.label === 'Mediana') return 'Mediana: ' + V.pct(c.mediana);
                    if (item.dataset.label === 'P10 a P90') return 'P10 a P90: ' + V.pct(c.p10) + ' a ' + V.pct(c.p90);
                    return '1º a 3º quartil: ' + V.pct(c.q1) + ' a ' + V.pct(c.q3);
                } } },
            },
        },
    });

    // Drill-down (reitor): clicar abre a análise do curso.
    V.habilitarDrill(Chart.getChart('grafico-media'), function (el) { return porMedia[el.index] ? { chave: porMedia[el.index].chave } : null; });
    V.habilitarDrill(Chart.getChart('grafico-faixas'), function (el) { return ordenados[el.index] ? { chave: ordenados[el.index].chave } : null; });
    V.habilitarDrill(Chart.getChart('grafico-dispersao'), function (el) { return porMediana[el.index] ? { chave: porMediana[el.index].chave } : null; });

    // 4 · Histograma do conjunto (+ curso à escolha), em % dos estudantes de cada um.
    function pcts(distribuicao, n) { return distribuicao.map(function (q) { return n > 0 ? Math.round(q / n * 1000) / 10 : 0; }); }
    var rotulosBaldes = [];
    for (var i = 0; i < 20; i++) { rotulosBaldes.push((i * 5) + '–' + (i === 19 ? 100 : i * 5 + 4)); }
    var corteIndice = dados.corte / 5 - 0.5;
    var histograma = V.criar('grafico-histograma', {
        type: 'bar',
        data: {
            labels: rotulosBaldes,
            datasets: [{ label: 'Total da visão', data: pcts(total.distribuicao, total.n), backgroundColor: V.cores.marinho, categoryPercentage: 0.95, barPercentage: 0.95, order: 1 }],
        },
        options: {
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, title: { display: true, text: '% dos estudantes' }, ticks: { callback: function (v) { return v + '%'; } } },
                x: { title: { display: true, text: '% de acerto' }, grid: { display: false } },
            },
            plugins: {
                legend: V.legenda('top'),
                linhaReferenciaX: { linhas: [{ valor: corteIndice, cor: V.cores.vermelho, rotulo: 'Critério' }] },
                tooltip: { callbacks: {
                    title: function (itens) { return 'Acerto de ' + itens[0].label + '%'; },
                    label: function (item) { return item.dataset.label + ': ' + V.pct(item.raw); },
                } },
            },
        },
    });
    var seletor = document.getElementById('seletor-histograma');
    if (seletor && histograma) {
        seletor.addEventListener('change', function () {
            if (histograma.data.datasets.length > 1) histograma.data.datasets.pop();
            if (seletor.value !== '') {
                var c = cursos[parseInt(seletor.value, 10)];
                histograma.data.datasets.push({
                    type: 'line', label: c.nome, data: pcts(c.distribuicao, c.n), borderColor: V.cores.verde, backgroundColor: V.cores.verde,
                    pointBackgroundColor: V.cores.verde, tension: 0.3, order: 0,
                });
            }
            histograma.update();
        });
    }
})();
</script>
@endsection
