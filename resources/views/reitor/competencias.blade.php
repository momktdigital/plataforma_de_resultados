@extends('layouts.app')

@section('title', 'Competências — Painel da reitoria')

@php
    $fmt = fn ($v, $casas = 1) => $v === null ? '—' : number_format($v, $casas, ',', '.');
    $cursos = array_values($est['cursos']);
    $total = $est['total'];
    $corte = $ctx['corte'];
    $corteTxt = $fmt($corte, 0);
    $bloom = $competencias['bloom'];
    $areas = $competencias['areas'];

    // Mesma rampa de uma cor do _viz.blade.php (Viz.sequencial): do claro ao escuro conforme o %.
    $rampa = ['#e2f4ee', '#b9e5d7', '#7ed2b9', '#3fb99b', '#12a37f', '#0a7159'];
    $corCelula = function (?float $v) use ($rampa): array {
        if ($v === null) {
            return ['bg' => '#f1f5f9', 'fg' => '#64748b'];
        }
        $i = max(0, min(count($rampa) - 1, (int) floor($v / 100 * count($rampa))));

        return ['bg' => $rampa[$i], 'fg' => $v >= 66 ? '#ffffff' : '#0f1720'];
    };

    $dados = [
        'corte' => $corte,
        'niveis' => $bloom['niveis'],
        'bloom' => [
            'total' => array_map(fn ($n) => $bloom['total'][$n['chave']]['pct'] ?? null, $bloom['niveis']),
            'proficientes' => array_map(fn ($n) => $bloom['proficientes'][$n['chave']]['pct'] ?? null, $bloom['niveis']),
            'naoProficientes' => array_map(fn ($n) => $bloom['naoProficientes'][$n['chave']]['pct'] ?? null, $bloom['niveis']),
            'cursos' => array_map(fn ($c) => [
                'nome' => $c['nome'],
                'pcts' => array_map(fn ($n) => $bloom['porCurso'][$c['chave']][$n['chave']]['pct'] ?? null, $bloom['niveis']),
            ], $cursos),
        ],
        'areas' => $areas['ranking'],
    ];
@endphp

@section('content')
@include('reitor._cabecalho', [
    'ctx' => $ctx,
    'aba' => 'competencias',
    'chips' => [
        ['valor' => count($areas['ranking']), 'rotulo' => 'Áreas'],
    ],
])

@include('reitor._filtros', ['ctx' => $ctx, 'exportar' => true])

<div class="mb-2 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
    <i class="ph-bold ph-info mr-1" aria-hidden="true"></i>
    Só entram as questões com gabarito e com o campo preenchido (nível de Bloom, área); anuladas seguem a regra de anulação do sistema.
    Células com menos de {{ \App\Services\ReitorCompetenciasService::MINIMO_RESPOSTAS }} respostas aparecem como "—".
</div>

{{-- 1 · Bloom --}}
@include('reitor._secao', ['numero' => 1, 'titulo' => 'Acerto por nível cognitivo (Bloom)', 'id' => 'secao-bloom'])
@if (! $bloom['temDados'])
    <div class="bg-slate-50 border border-slate-200 text-slate-600 rounded-xl p-6 text-sm">
        Nenhuma questão desta avaliação tem o nível de Bloom cadastrado (ou ainda não há respostas suficientes). Preencha o campo nas questões para ver este quadro.
    </div>
@else
    <section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="secao-bloom">
        <h3 class="text-xs font-bold uppercase tracking-wide text-emerald-800 mb-3">O que distingue os proficientes</h3>
        <div class="relative h-80"><canvas id="grafico-bloom-distingue" data-titulo="Acerto de proficientes e não proficientes por nível de Bloom"></canvas></div>
        <ul class="mt-3 flex flex-wrap gap-2" aria-label="Distância entre proficientes e não proficientes">
            @foreach ($bloom['niveis'] as $n)
                @php
                    $p = $bloom['proficientes'][$n['chave']]['pct'] ?? null;
                    $q = $bloom['naoProficientes'][$n['chave']]['pct'] ?? null;
                @endphp
                @if ($p !== null && $q !== null)
                    <li class="rounded-full border border-slate-300 bg-white px-3 py-1 text-xs text-slate-700"><strong>{{ $n['rotulo'] }}</strong> <span class="font-mono">+{{ $fmt($p - $q) }} pp</span></li>
                @endif
            @endforeach
        </ul>
        @include('reitor._rodape-quadro', ['id' => 'bloom-distingue', 'sobre' => $sobre['bloom_distingue'], 'leitura' => $leituras['bloom_distingue'] ?? null])
    </section>

    <section class="mt-4 bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="titulo-bloom-mapa">
        <h3 id="titulo-bloom-mapa" class="text-xs font-bold uppercase tracking-wide text-emerald-800 mb-3">% de acerto por nível da taxonomia de Bloom</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm border-separate" style="border-spacing: 2px">
                <caption class="sr-only">Percentual de acerto de cada curso em cada nível da taxonomia de Bloom.</caption>
                <thead class="text-[11px] font-bold uppercase tracking-wide text-slate-600">
                    <tr>
                        <th scope="col" class="px-3 py-2 text-left">Curso</th>
                        @foreach ($bloom['niveis'] as $n)
                            <th scope="col" class="px-3 py-2 text-center">{{ $n['rotulo'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($cursos as $c)
                        <tr>
                            <th scope="row" class="px-3 py-2 text-left font-medium text-slate-800">{{ $c['nome'] }}</th>
                            @foreach ($bloom['niveis'] as $n)
                                @php
                                    $celula = $bloom['porCurso'][$c['chave']][$n['chave']] ?? ['pct' => null, 'respostas' => 0];
                                    $cor = $corCelula($celula['pct']);
                                @endphp
                                <td class="px-3 py-2 text-center font-mono font-semibold" style="background: {{ $cor['bg'] }}; color: {{ $cor['fg'] }}" title="{{ number_format($celula['respostas'], 0, ',', '.') }} respostas">{{ $celula['pct'] === null ? '—' : $fmt($celula['pct'], 0).'%' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="font-bold">
                        <th scope="row" class="px-3 py-2 text-left text-slate-800">Total da visão</th>
                        @foreach ($bloom['niveis'] as $n)
                            @php $cor = $corCelula($bloom['total'][$n['chave']]['pct'] ?? null); @endphp
                            <td class="px-3 py-2 text-center font-mono" style="background: {{ $cor['bg'] }}; color: {{ $cor['fg'] }}">{{ ($bloom['total'][$n['chave']]['pct'] ?? null) === null ? '—' : $fmt($bloom['total'][$n['chave']]['pct'], 0).'%' }}</td>
                        @endforeach
                    </tr>
                </tfoot>
            </table>
        </div>
        @include('reitor._rodape-quadro', ['id' => 'bloom-mapa', 'sobre' => $sobre['bloom_mapa'], 'leitura' => $leituras['bloom_mapa'] ?? null])
    </section>

    <section class="mt-4 bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="titulo-radar">
        <h3 id="titulo-radar" class="text-xs font-bold uppercase tracking-wide text-emerald-800 mb-3">Perfil cognitivo de um curso frente ao conjunto</h3>
        <div class="mb-3 flex flex-wrap items-center gap-3">
            <label for="seletor-radar" class="text-xs font-bold uppercase tracking-wide text-slate-600">Curso</label>
            <select id="seletor-radar" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white">
                @foreach ($cursos as $i => $c)
                    <option value="{{ $i }}">{{ $c['nome'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="relative mx-auto h-96 max-w-xl"><canvas id="grafico-bloom-radar" data-titulo="Perfil de acerto por nível de Bloom: curso escolhido e conjunto"></canvas></div>
    </section>
@endif

{{-- 2 · Áreas --}}
@include('reitor._secao', ['numero' => 2, 'titulo' => 'Acerto por área de conhecimento', 'id' => 'secao-areas'])
@if (count($areas['ranking']) === 0)
    <div class="bg-slate-50 border border-slate-200 text-slate-600 rounded-xl p-6 text-sm">
        Nenhuma área tem respostas suficientes (ou as questões não têm o campo "área" preenchido).
    </div>
@else
    <section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="secao-areas">
        <h3 class="text-xs font-bold uppercase tracking-wide text-emerald-800 mb-3">Ranking das áreas — da mais frágil à mais forte</h3>
        <div class="relative" style="height: {{ 90 + 30 * count($areas['ranking']) }}px"><canvas id="grafico-areas" data-titulo="Percentual de acerto por área de conhecimento"></canvas></div>

        <div class="mt-4 overflow-x-auto">
            <table data-ordenavel class="w-full text-sm">
                <caption class="sr-only">Percentual de acerto por área: conjunto, proficientes, não proficientes e a distância entre eles.</caption>
                <thead class="text-left text-[11px] font-bold uppercase tracking-wide text-slate-600">
                    <tr class="border-b border-slate-200">
                        @foreach ([['Área', 'texto'], ['Respostas', 'numero'], ['Conjunto', 'numero'], ['Proficientes', 'numero'], ['Não proficientes', 'numero'], ['Distância', 'numero']] as [$rotulo, $tipo])
                            <th scope="col" data-ordem="{{ $tipo }}" class="px-3 py-2 {{ $tipo === 'numero' ? 'text-right' : '' }}">
                                <button type="button" class="inline-flex items-center gap-1 font-bold uppercase tracking-wide hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">{{ $rotulo }} <i class="ph ph-arrows-down-up text-xs" aria-hidden="true"></i></button>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($areas['ranking'] as $a)
                        <tr class="border-b border-slate-100">
                            <td data-valor="{{ $a['area'] }}" class="px-3 py-2.5 font-medium text-slate-800">{{ $a['area'] }}</td>
                            <td data-valor="{{ $a['respostas'] }}" class="px-3 py-2.5 text-right font-mono">{{ $fmt($a['respostas'], 0) }}</td>
                            <td data-valor="{{ $a['pct'] }}" class="px-3 py-2.5 text-right font-mono font-bold">{{ $fmt($a['pct']) }}%</td>
                            <td data-valor="{{ $a['proficientes'] }}" class="px-3 py-2.5 text-right font-mono">{{ $a['proficientes'] === null ? '—' : $fmt($a['proficientes']).'%' }}</td>
                            <td data-valor="{{ $a['naoProficientes'] }}" class="px-3 py-2.5 text-right font-mono">{{ $a['naoProficientes'] === null ? '—' : $fmt($a['naoProficientes']).'%' }}</td>
                            <td data-valor="{{ $a['distancia'] }}" class="px-3 py-2.5 text-right font-mono">{{ $a['distancia'] === null ? '—' : '+'.$fmt($a['distancia']).' pp' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @include('reitor._rodape-quadro', ['id' => 'areas-ranking', 'sobre' => $sobre['areas_ranking'], 'leitura' => $leituras['areas_ranking'] ?? null])
    </section>

    <section class="mt-4 bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="titulo-areas-mapa">
        <h3 id="titulo-areas-mapa" class="text-xs font-bold uppercase tracking-wide text-emerald-800 mb-3">Mapa de calor: curso × área</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm border-separate" style="border-spacing: 2px">
                <caption class="sr-only">Percentual de acerto de cada curso nas áreas de conhecimento com mais respostas.</caption>
                <thead class="text-[11px] font-bold uppercase tracking-wide text-slate-600">
                    <tr>
                        <th scope="col" class="px-3 py-2 text-left align-bottom">Curso</th>
                        @foreach ($areas['mapa'] as $area)
                            <th scope="col" class="px-2 py-2 text-center align-bottom min-w-[84px]"><span class="block max-w-[110px] mx-auto leading-tight">{{ $area }}</span></th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($cursos as $c)
                        <tr>
                            <th scope="row" class="px-3 py-2 text-left font-medium text-slate-800 whitespace-nowrap">{{ $c['nome'] }}</th>
                            @foreach ($areas['mapa'] as $area)
                                @php
                                    $valor = $areas['porCurso'][$c['chave']][$area] ?? null;
                                    $cor = $corCelula($valor);
                                @endphp
                                <td class="px-2 py-2 text-center font-mono font-semibold" style="background: {{ $cor['bg'] }}; color: {{ $cor['fg'] }}">{{ $valor === null ? '—' : $fmt($valor, 0).'%' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($areas['omitidas'] > 0)
            <p class="mt-2 text-xs text-slate-600">Mostradas as {{ count($areas['mapa']) }} áreas com mais respostas; {{ $areas['omitidas'] }} área(s) com menos respostas aparecem só no ranking acima.</p>
        @endif
        @include('reitor._rodape-quadro', ['id' => 'areas-mapa', 'sobre' => $sobre['areas_mapa'], 'leitura' => $leituras['areas_mapa'] ?? null])
    </section>
@endif

@include('reitor._base')
<script>
(function () {
    var dados = @json($dados);
    var V = window.ReitorViz;
    var rotulos = dados.niveis.map(function (n) { return n.rotulo; });

    // 1 · O que distingue os proficientes: barras agrupadas por nível de Bloom.
    V.criar('grafico-bloom-distingue', {
        type: 'bar',
        data: {
            labels: rotulos,
            datasets: [
                { label: 'Proficientes (≥ ' + V.fmt(dados.corte, 0) + '%)', data: dados.bloom.proficientes, backgroundColor: V.cores.verde, maxBarThickness: 48 },
                { label: 'Não proficientes', data: dados.bloom.naoProficientes, backgroundColor: V.cores.cinza, maxBarThickness: 48 },
            ],
        },
        options: {
            maintainAspectRatio: false,
            scales: { y: V.eixoPct(), x: { grid: { display: false } } },
            plugins: {
                legend: V.legenda('top'),
                tooltip: { callbacks: { label: function (item) { return item.dataset.label + ': ' + V.pct(item.raw); } } },
            },
        },
    });

    // Radar: um curso frente ao conjunto.
    var seletor = document.getElementById('seletor-radar');
    var radar = null;
    function desenharRadar() {
        var curso = dados.bloom.cursos[parseInt(seletor.value, 10)];
        var conjunto = { label: 'Conjunto', data: dados.bloom.total, borderColor: V.cores.cinza, backgroundColor: 'rgba(170, 178, 189, 0.25)', pointBackgroundColor: V.cores.cinza, borderDash: [5, 4] };
        var escolhido = { label: curso.nome, data: curso.pcts, borderColor: V.cores.marinho, backgroundColor: 'rgba(30, 58, 95, 0.22)', pointBackgroundColor: V.cores.marinho };
        if (radar) {
            radar.data.datasets = [conjunto, escolhido];
            radar.update();
            return;
        }
        radar = V.criar('grafico-bloom-radar', {
            type: 'radar',
            data: { labels: rotulos, datasets: [conjunto, escolhido] },
            options: {
                maintainAspectRatio: false,
                scales: { r: { min: 0, max: 100, ticks: { stepSize: 20, backdropColor: 'transparent', callback: function (v) { return v + '%'; } } } },
                plugins: { legend: V.legenda('top'), tooltip: { callbacks: { label: function (item) { return item.dataset.label + ': ' + V.pct(item.raw); } } } },
            },
        });
    }
    if (seletor) {
        seletor.addEventListener('change', desenharRadar);
        desenharRadar();
    }

    // 2 · Ranking de áreas (da mais frágil, no topo, à mais forte).
    if (dados.areas.length) {
        V.criar('grafico-areas', {
            type: 'bar',
            data: {
                labels: dados.areas.map(function (a) { return a.area; }),
                datasets: [{
                    label: 'Conjunto',
                    data: dados.areas.map(function (a) { return a.pct; }),
                    backgroundColor: dados.areas.map(function (a) { return a.pct >= dados.corte ? V.cores.verde : V.cores.marinho; }),
                    maxBarThickness: 20,
                }],
            },
            options: {
                indexAxis: 'y',
                maintainAspectRatio: false,
                layout: { padding: { right: 48 } },
                scales: { x: V.eixoPct(), y: { grid: { display: false } } },
                plugins: {
                    legend: { display: false },
                    linhaReferenciaX: { linhas: [{ valor: dados.corte, cor: V.cores.vermelho, rotulo: V.fmt(dados.corte, 0) + '%' }] },
                    rotulosValores: { dataset: 0, horizontal: true, formato: function (v) { return V.pct(v); } },
                    tooltip: { callbacks: { label: function (item) {
                        var a = dados.areas[item.dataIndex];
                        return ['Conjunto: ' + V.pct(a.pct) + ' (' + a.respostas + ' respostas)', 'Proficientes: ' + V.pct(a.proficientes), 'Não proficientes: ' + V.pct(a.naoProficientes)];
                    } } },
                },
            },
        });
    }
})();
</script>
@endsection
