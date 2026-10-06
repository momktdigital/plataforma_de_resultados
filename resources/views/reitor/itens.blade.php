@extends('layouts.app')

@section('title', 'Análise dos itens — Painel da reitoria')

@php
    use App\Services\ReitorItensService as Itens;

    $fmt = fn ($v, $casas = 1) => $v === null ? '—' : number_format($v, $casas, ',', '.');
    $total = $analise['total'];
    $contagem = $analise['contagem'];
    $criticos = $analise['criticos'];
    $diagnosticos = Itens::DIAGNOSTICOS;
    $classeTom = fn (string $d) => match ($diagnosticos[$d][2]) {
        'ruim' => 'bg-red-50 text-red-800 border-red-200',
        default => 'bg-amber-50 text-amber-800 border-amber-200',
    };
    $limiteLinhas = 300;
    $areas = collect($analise['areas'])->pluck('area')->all();

    $pontos = array_map(fn ($i) => [
        'x' => $i['dificuldade'],
        'y' => $i['discriminacao'],
        'avaliacao' => $i['avaliacaoNome'],
        'numero' => $i['numero'],
        'diag' => $i['diagnostico'],
    ], array_values(array_filter($analise['itens'], fn ($i) => $i['discriminacao'] !== null)));

    $dados = [
        'pontos' => $pontos,
        'diagnosticos' => array_map(fn ($d) => $d[0], $diagnosticos),
        'acertoMuitoBaixo' => Itens::ACERTO_MUITO_BAIXO,
        'dMinimo' => \App\Support\Psicometria::D_MINIMO_ACEITAVEL,
        'areas' => array_map(fn ($a) => ['area' => $a['area'], 'pct' => $a['pctARevisar'], 'itens' => $a['itens'], 'aRevisar' => $a['aRevisar']], $analise['areas']),
    ];
@endphp

@section('content')
@include('reitor._cabecalho', [
    'ctx' => $ctx,
    'aba' => 'itens',
    'chips' => [
        ['valor' => $fmt($total['itens'], 0), 'rotulo' => 'Itens'],
        ['valor' => $fmt($total['aRevisar'], 0), 'rotulo' => 'A revisar'],
    ],
])

@include('reitor._filtros', ['ctx' => $ctx, 'exportar' => true])

<div class="mb-4 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
    <i class="ph-bold ph-info mr-1" aria-hidden="true"></i>
    Cada avaliação cadastra as próprias questões, então o <strong>item é sempre (avaliação × número)</strong>; a leitura institucional vem de somar por área, por avaliação e pelo diagnóstico.
    Só entram itens com {{ $analise['minimoRespostas'] }} respostas ou mais e avaliações com 10 respondentes ou mais; questões anuladas ficam fora.
    @if ($analise['limitado'])
        <strong>Foram analisadas as {{ $analise['analisadas'] }} avaliações com mais participantes</strong> do recorte — escolha uma categoria mais específica para ver as demais.
    @endif
</div>

@if ($total['itens'] === 0)
    <div class="bg-slate-50 border border-slate-200 text-slate-600 rounded-xl p-6 text-sm">
        Nenhum item analisável neste recorte (faltam questões com gabarito ou respondentes suficientes).
    </div>
@else
<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5 mb-2">
    <div class="rounded-2xl border border-slate-200 border-t-4 border-t-[#1e3a5f] bg-white p-5 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wide text-slate-600">Itens analisados</p>
        <p class="mt-2 font-mono text-4xl font-black text-[#1e3a5f]">{{ $fmt($total['itens'], 0) }}</p>
        <p class="mt-1 text-xs text-slate-600">em {{ $total['avaliacoes'] }} {{ $total['avaliacoes'] === 1 ? 'avaliação' : 'avaliações' }}</p>
    </div>
    <div class="rounded-2xl border border-amber-200 border-t-4 border-t-amber-500 bg-amber-50 p-5 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wide text-amber-900">Itens a revisar</p>
        <p class="mt-2 font-mono text-4xl font-black text-amber-800">{{ $fmt($total['pctARevisar']) }}<span class="text-2xl">%</span></p>
        <p class="mt-1 text-xs text-slate-700">{{ $fmt($total['aRevisar'], 0) }} itens com algum diagnóstico</p>
    </div>
    <div class="rounded-2xl border border-red-200 border-t-4 border-t-red-700 bg-red-50 p-5 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wide text-red-900">Gabarito suspeito</p>
        <p class="mt-2 font-mono text-4xl font-black text-red-800">{{ $contagem['gabarito'] }}</p>
        <p class="mt-1 text-xs text-slate-700">quem vai melhor marca outra alternativa</p>
    </div>
    <div class="rounded-2xl border border-slate-200 border-t-4 border-t-violet-500 bg-white p-5 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wide text-slate-600">Problema da questão × lacuna de formação</p>
        <p class="mt-2 font-mono text-4xl font-black text-[#1e3a5f]">{{ $contagem['questao'] }}<span class="text-2xl text-slate-500"> × {{ $contagem['formacao'] }}</span></p>
        <p class="mt-1 text-xs text-slate-600">difícil e não discrimina × difícil e discrimina</p>
    </div>
    <div class="rounded-2xl border border-slate-200 border-t-4 border-t-emerald-500 bg-white p-5 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wide text-slate-600">Confiabilidade média (KR-20)</p>
        <p class="mt-2 font-mono text-4xl font-black text-[#1e3a5f]">{{ $total['kr20Medio'] === null ? '—' : number_format($total['kr20Medio'], 2, ',', '.') }}</p>
        <p class="mt-1 text-xs text-slate-600">média das avaliações do recorte</p>
    </div>
</div>

{{-- 1 · Mapa dificuldade × discriminação --}}
@include('reitor._secao', ['numero' => 1, 'titulo' => 'Mapa dos itens: acerto × discriminação', 'id' => 'secao-itens-mapa'])
<section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="secao-itens-mapa">
    <p class="text-sm text-slate-600 mb-3">Cada ponto é um item. Abaixo da linha horizontal o item não discrimina; à esquerda da vertical, o acerto é muito baixo. Pontos coloridos têm diagnóstico.</p>
    <div class="relative h-[28rem]"><canvas id="grafico-itens-mapa" data-titulo="Itens por acerto e discriminação"></canvas></div>
    @include('reitor._rodape-quadro', ['id' => 'itens-mapa', 'sobre' => $sobre['itens_mapa'], 'leitura' => $leituras['itens_mapa'] ?? null])
</section>

{{-- 2 · Itens a revisar --}}
@include('reitor._secao', ['numero' => 2, 'titulo' => 'Itens que merecem revisão', 'id' => 'secao-itens-lista'])
<section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="secao-itens-lista">
    <div class="mb-3 flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1" for="filtro-diagnostico">Diagnóstico</label>
            <select id="filtro-diagnostico" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white">
                <option value="">Todos ({{ count($criticos) }})</option>
                @foreach ($diagnosticos as $chave => $d)
                    <option value="{{ $chave }}">{{ $d[0] }} ({{ $contagem[$chave] }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1" for="filtro-area-item">Área</label>
            <select id="filtro-area-item" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white max-w-[16rem]">
                <option value="">Todas</option>
                @foreach ($areas as $area)
                    <option value="{{ $area }}">{{ $area }}</option>
                @endforeach
            </select>
        </div>
        <div class="w-full max-w-xs">
            <label class="sr-only" for="filtro-tabela-itens">Buscar item</label>
            <input id="filtro-tabela-itens" type="search" placeholder="Buscar avaliação, tema..." data-filtro-tabela="tabela-itens" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
        </div>
    </div>

    <div class="overflow-x-auto">
        <table id="tabela-itens" data-ordenavel class="w-full text-sm">
            <caption class="sr-only">Itens com diagnóstico: avaliação, número, área, tema, acerto, discriminação, alternativa mais marcada e explicação.</caption>
            <thead class="text-left text-[11px] font-bold uppercase tracking-wide text-slate-600">
                <tr class="border-b border-slate-200">
                    @foreach ([['Avaliação', 'texto'], ['Nº', 'numero'], ['Área / tema', 'texto'], ['Acerto', 'numero'], ['Discrim.', 'numero'], ['Diagnóstico', 'texto'], ['Marcação', 'texto']] as [$rotulo, $tipo])
                        <th scope="col" data-ordem="{{ $tipo }}" class="px-3 py-2 {{ $tipo === 'numero' ? 'text-right' : '' }}">
                            <button type="button" class="inline-flex items-center gap-1 font-bold uppercase tracking-wide hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">{{ $rotulo }} <i class="ph ph-arrows-down-up text-xs" aria-hidden="true"></i></button>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach (array_slice($criticos, 0, $limiteLinhas) as $i)
                    @php $d = $diagnosticos[$i['diagnostico']]; @endphp
                    <tr class="border-b border-slate-100 align-top" data-diagnostico="{{ $i['diagnostico'] }}" data-area="{{ $i['area'] }}" data-nome="{{ $i['avaliacaoNome'].' '.$i['area'].' '.$i['tema'] }}">
                        <td data-valor="{{ $i['avaliacaoNome'] }}" class="px-3 py-2.5 font-medium text-slate-800">
                            @if (! empty($i['cursoChave']))
                                @include('reitor._nome-curso', ['chave' => $i['cursoChave'], 'nome' => $i['avaliacaoNome'], 'destino' => 'bi', 'extra' => ['avaliacao' => $i['avaliacao']]])
                            @else
                                {{ $i['avaliacaoNome'] }}
                            @endif
                        </td>
                        <td data-valor="{{ $i['numero'] }}" class="px-3 py-2.5 text-right font-mono">{{ $i['numero'] }}</td>
                        <td data-valor="{{ $i['area'] }}" class="px-3 py-2.5">{{ $i['area'] ?: '—' }}@if ($i['tema'])<span class="block text-xs text-slate-600">{{ $i['tema'] }}</span>@endif</td>
                        <td data-valor="{{ $i['dificuldade'] }}" class="px-3 py-2.5 text-right font-mono">{{ $fmt($i['dificuldade']) }}%</td>
                        <td data-valor="{{ $i['discriminacao'] }}" class="px-3 py-2.5 text-right font-mono">{{ $i['discriminacao'] === null ? '—' : number_format($i['discriminacao'], 2, ',', '.') }}</td>
                        <td data-valor="{{ $d[1] }}" class="px-3 py-2.5 min-w-[16rem]">
                            <span class="inline-block rounded border px-2 py-0.5 text-xs font-semibold {{ $classeTom($i['diagnostico']) }}">{{ $d[0] }}</span>
                            <span class="mt-1 block text-xs text-slate-700">{{ $i['explicacao'] }}</span>
                            @if (count($i['porCurso']) >= 2)
                                <span class="mt-1 block text-xs text-slate-600">Acerto por curso: de {{ $fmt(min($i['porCurso'])) }}% a {{ $fmt(max($i['porCurso'])) }}% ({{ count($i['porCurso']) }} cursos)</span>
                            @endif
                        </td>
                        <td data-valor="{{ $i['distratorPct'] }}" class="px-3 py-2.5 text-xs text-slate-700 whitespace-nowrap">
                            gab. {{ $i['gabarito'] }}: {{ $fmt($i['gabaritoPct']) }}%
                            @if ($i['distrator'])<span class="block">mais marcada: {{ $i['distrator'] }} ({{ $fmt($i['distratorPct']) }}%)</span>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if (count($criticos) > $limiteLinhas)
        <p class="mt-2 text-xs text-slate-600">Mostrados os {{ $limiteLinhas }} itens mais urgentes de {{ count($criticos) }}.</p>
    @endif
    @if (count($criticos) === 0)
        <p class="mt-3 text-sm text-emerald-800 bg-emerald-50 border border-emerald-100 rounded-lg p-3">Nenhum item com diagnóstico neste recorte. Bom sinal.</p>
    @endif
    @include('reitor._rodape-quadro', ['id' => 'itens-lista', 'sobre' => $sobre['itens_lista'], 'leitura' => $leituras['itens_lista'] ?? null])
</section>

{{-- 3 · Por área --}}
@include('reitor._secao', ['numero' => 3, 'titulo' => 'Onde estão os itens problemáticos: por área', 'id' => 'secao-itens-areas'])
<section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="secao-itens-areas">
    <div class="relative" style="height: {{ 90 + 30 * count($analise['areas']) }}px"><canvas id="grafico-itens-areas" data-titulo="Percentual de itens a revisar por área"></canvas></div>
    @include('reitor._rodape-quadro', ['id' => 'itens-areas', 'sobre' => $sobre['itens_areas'], 'leitura' => $leituras['itens_areas'] ?? null])
</section>

{{-- 4 · Por avaliação --}}
@include('reitor._secao', ['numero' => 4, 'titulo' => 'Por avaliação (curso): confiabilidade e itens a revisar', 'id' => 'secao-itens-avaliacoes'])
<section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="secao-itens-avaliacoes">
    <div class="overflow-x-auto">
        <table data-ordenavel class="w-full text-sm">
            <caption class="sr-only">Para cada avaliação: cursos, respondentes, itens, confiabilidade KR-20 e itens a revisar.</caption>
            <thead class="text-left text-[11px] font-bold uppercase tracking-wide text-slate-600">
                <tr class="border-b border-slate-200">
                    @foreach ([['Avaliação', 'texto'], ['Cursos', 'texto'], ['Respondentes', 'numero'], ['Itens', 'numero'], ['KR-20', 'numero'], ['A revisar', 'numero']] as [$rotulo, $tipo])
                        <th scope="col" data-ordem="{{ $tipo }}" class="px-3 py-2 {{ $tipo === 'numero' ? 'text-right' : '' }}">
                            <button type="button" class="inline-flex items-center gap-1 font-bold uppercase tracking-wide hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">{{ $rotulo }} <i class="ph ph-arrows-down-up text-xs" aria-hidden="true"></i></button>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($analise['avaliacoes'] as $a)
                    <tr class="border-b border-slate-100">
                        <td data-valor="{{ $a['nome'] }}" class="px-3 py-2.5 font-medium text-slate-800">{{ $a['nome'] }}</td>
                        <td data-valor="{{ implode(', ', $a['cursos']) }}" class="px-3 py-2.5 text-slate-700">{{ implode(', ', array_slice($a['cursos'], 0, 3)) }}{{ count($a['cursos']) > 3 ? ' +'.(count($a['cursos']) - 3) : '' }}</td>
                        <td data-valor="{{ $a['respondentes'] }}" class="px-3 py-2.5 text-right font-mono">{{ $fmt($a['respondentes'], 0) }}</td>
                        <td data-valor="{{ $a['itens'] }}" class="px-3 py-2.5 text-right font-mono">{{ $a['itens'] }}</td>
                        <td data-valor="{{ $a['kr20'] }}" class="px-3 py-2.5 text-right font-mono">{{ $a['kr20'] === null ? '—' : number_format($a['kr20'], 2, ',', '.') }}</td>
                        <td data-valor="{{ $a['aRevisar'] }}" class="px-3 py-2.5 text-right font-mono font-bold">{{ $a['aRevisar'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @include('reitor._rodape-quadro', ['id' => 'itens-avaliacoes', 'sobre' => $sobre['itens_avaliacoes'], 'leitura' => null])
</section>

@include('reitor._base')
<script>
(function () {
    var dados = @json($dados);
    var V = window.ReitorViz;
    var cor = { gabarito: '#c1121f', questao: '#e8710a', formacao: '#7c3aed', fraco: '#e8a317', todos_cursos: '#0284c7' };

    // 1 · Mapa dificuldade × discriminação.
    var pontos = dados.pontos;
    var sem = pontos.filter(function (p) { return !p.diag; });
    var com = pontos.filter(function (p) { return p.diag; });
    var conjuntos = [{
        label: 'Sem diagnóstico', data: sem, backgroundColor: 'rgba(138, 147, 156, 0.45)', pointRadius: 3, pointHoverRadius: 6,
    }];
    Object.keys(dados.diagnosticos).forEach(function (chave) {
        var pts = com.filter(function (p) { return p.diag === chave; });
        if (pts.length) conjuntos.push({ label: dados.diagnosticos[chave], data: pts, backgroundColor: cor[chave], pointRadius: 5, pointHoverRadius: 8 });
    });
    V.criar('grafico-itens-mapa', {
        type: 'scatter',
        data: { datasets: conjuntos },
        options: {
            maintainAspectRatio: false,
            scales: {
                x: { min: 0, max: 100, title: { display: true, text: 'Acerto (%)' }, ticks: { callback: function (v) { return v + '%'; } } },
                y: { min: -0.5, max: 1, title: { display: true, text: 'Discriminação (D)' } },
            },
            plugins: {
                legend: V.legenda('top'),
                linhaReferencia: { linhas: [{ valor: dados.dMinimo, cor: V.cores.vermelho, rotulo: 'D mínimo ' + V.fmt(dados.dMinimo, 2) }] },
                linhaReferenciaX: { linhas: [{ valor: dados.acertoMuitoBaixo, cor: V.cores.ambar, rotulo: 'acerto muito baixo' }] },
                tooltip: { callbacks: {
                    title: function (itens) { var p = itens[0].raw; return p.avaliacao + ' · questão ' + p.numero; },
                    label: function (item) { return 'Acerto ' + V.pct(item.raw.x) + ' · D ' + V.fmt(item.raw.y, 2); },
                } },
            },
        },
    });

    // 2 · Filtros da tabela (diagnóstico e área; a busca é a genérica).
    var tabela = document.getElementById('tabela-itens');
    var selDiag = document.getElementById('filtro-diagnostico');
    var selArea = document.getElementById('filtro-area-item');
    function filtrar() {
        Array.prototype.forEach.call(tabela.tBodies[0].rows, function (linha) {
            var ok = (!selDiag.value || linha.getAttribute('data-diagnostico') === selDiag.value)
                && (!selArea.value || linha.getAttribute('data-area') === selArea.value);
            linha.setAttribute('data-filtrada', ok ? '0' : '1');
            linha.hidden = !ok;
        });
    }
    selDiag.addEventListener('change', filtrar);
    selArea.addEventListener('change', filtrar);

    // 3 · Percentual de itens a revisar por área.
    V.criar('grafico-itens-areas', {
        type: 'bar',
        data: {
            labels: dados.areas.map(function (a) { return a.area; }),
            datasets: [{ label: '% de itens a revisar', data: dados.areas.map(function (a) { return a.pct; }), backgroundColor: V.cores.ambar, maxBarThickness: 20 }],
        },
        options: {
            indexAxis: 'y',
            maintainAspectRatio: false,
            layout: { padding: { right: 56 } },
            scales: { x: { min: 0, max: 100, ticks: { callback: function (v) { return v + '%'; } } }, y: { grid: { display: false } } },
            plugins: {
                legend: { display: false },
                rotulosValores: { dataset: 0, horizontal: true, formato: function (v) { return V.pct(v); } },
                tooltip: { callbacks: { label: function (item) { var a = dados.areas[item.dataIndex]; return a.aRevisar + ' de ' + a.itens + ' itens (' + V.pct(a.pct) + ')'; } } },
            },
        },
    });
})();
</script>
@endif
@endsection
