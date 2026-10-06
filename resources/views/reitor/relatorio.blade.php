@extends('layouts.app')

@section('title', 'Relatório institucional — Painel da reitoria')

@php
    $fmt = fn ($v, $casas = 1) => $v === null ? '—' : number_format($v, $casas, ',', '.');
    $cursos = array_values($est['cursos']);
    $total = $est['total'];
    $corte = $ctx['corte'];
    $meta = $ctx['meta'];
    $bloom = $competencias['bloom'];
    $ranking = $competencias['areas']['ranking'];
    $semestres = $evolucao['semestres'];
    $periodoTxt = $ctx['avaliacao']['todosPeriodos'] ? 'Todos os períodos letivos' : ($ctx['avaliacao']['periodoLetivo'] !== '' ? 'Período letivo '.$ctx['avaliacao']['periodoLetivo'] : 'Sem período letivo');
    $mostraEvolucao = count($semestres) >= 2;

    $texto = fn (string $chave) => $leituras[$chave]['texto'] ?? null;

    $dados = [
        'titulo' => 'Relatório institucional',
        'recorte' => $ctx['avaliacao']['nome'],
        'periodo' => $periodoTxt,
        'cursos' => count($cursos),
        'geradoEm' => now()->format('d/m/Y'),
        'geradoPor' => $usuario->username,
        'corte' => $corte,
        'meta' => $meta,
        'mistura' => $ctx['avaliacao']['mistura'],
        'kpis' => [
            ['rotulo' => 'Proficiência institucional (≥ '.$fmt($corte, 0).'%)', 'valor' => $fmt($total['proficienciaPct']).'%', 'detalhe' => $fmt($total['proficientes'], 0).' de '.$fmt($total['n'], 0).' estudantes'],
            ['rotulo' => '% de acerto médio', 'valor' => $fmt($total['media']).'%', 'detalhe' => 'mediana '.$fmt($total['mediana']).'%'],
            ['rotulo' => 'Participação', 'valor' => $fmt($total['participacao']).'%', 'detalhe' => $fmt($total['fizeram'], 0).' de '.$fmt($total['previstos'], 0).' previstos'],
            ['rotulo' => 'Meta de participação', 'valor' => $fmt($meta, 0).'%', 'detalhe' => $total['alunosAMais'] > 0 ? 'faltam '.$fmt($total['alunosAMais'], 0).' estudantes' : 'meta atingida'],
        ],
        'alertas' => array_map(fn ($a) => $a['texto'], $alertas),
        'leituras' => [
            'participacao' => $texto('participacao'),
            'proficiencia' => $texto('proficiencia'),
            'media' => $texto('media'),
            'faixas' => $texto('faixas'),
            'trajetoria' => $texto('trajetoria_acerto'),
            'bloom' => $texto('bloom_distingue'),
            'areas' => $texto('areas_ranking'),
            'evolucao' => $texto('evolucao_institucional'),
        ],
        'tabela' => array_map(fn ($c) => [$c['nome'], $fmt($c['previstos'], 0), $fmt($c['fizeram'], 0), $fmt($c['participacao']).'%', $fmt($c['proficienciaPct']).'%', $fmt($c['media']).'%', $fmt($c['mediana']).'%'], $cursos),
        'cursosGraficos' => array_map(fn ($c) => [
            'nome' => $c['nome'], 'prof' => $c['proficienciaPct'], 'media' => $c['media'], 'mediana' => $c['mediana'],
            'faixas' => array_column($c['faixas'], 'pct'),
            'periodos' => array_map(fn ($p) => ['ordinal' => $p['ordinal'], 'media' => $p['media'], 'n' => $p['n']], array_values($c['periodos'])),
            'cor' => $ctx['cores'][$c['chave']] ?? '#64748b',
        ], $cursos),
        'totalProf' => $total['proficienciaPct'],
        'faixasRotulos' => array_map(fn ($f) => $f['rotulo'], $faixas),
        'totalFaixas' => array_column($total['faixas'], 'pct'),
        'ordinais' => $est['periodos'],
        'totalPeriodos' => array_map(fn ($p) => ['ordinal' => $p['ordinal'], 'media' => $p['media'], 'n' => $p['n']], array_values($total['periodos'])),
        'minimoPeriodo' => $minimoPeriodo,
        'bloom' => $bloom['temDados'] ? [
            'rotulos' => array_column($bloom['niveis'], 'rotulo'),
            'prof' => array_map(fn ($n) => $bloom['proficientes'][$n['chave']]['pct'] ?? null, $bloom['niveis']),
            'nao' => array_map(fn ($n) => $bloom['naoProficientes'][$n['chave']]['pct'] ?? null, $bloom['niveis']),
        ] : null,
        'areas' => array_map(fn ($a) => ['area' => $a['area'], 'pct' => $a['pct']], array_slice($ranking, 0, 12)),
        'evolucao' => $mostraEvolucao ? [
            'rotulos' => array_column($semestres, 'periodoLetivo'),
            'prof' => array_map(fn ($s) => $s['total']['proficienciaPct'], $semestres),
            'media' => array_map(fn ($s) => $s['total']['media'], $semestres),
            'part' => array_map(fn ($s) => $s['total']['participacao'], $semestres),
        ] : null,
    ];

    $secao = 'mt-8';
@endphp

@section('content')
<style>
    /* Relatório: cada seção começa em página nova ao imprimir; as figuras não se partem. */
    @media print {
        .rel-quebra { break-before: page; }
        .rel-figura, table, .rel-bloco { break-inside: avoid; }
        .rel-capa { min-height: 90vh; }
        @page { size: A4 portrait; margin: 14mm; }
    }
</style>

<div class="mb-6 flex flex-wrap items-center gap-3 print:hidden">
    <a href="{{ route('reitor.visao', array_filter($ctx['filtro'], fn ($v) => $v !== '') + ($ctx['filtrando'] ? ['cursos' => $ctx['cursosSelecionados']] : [])) }}" class="inline-flex items-center gap-2 text-sm font-semibold text-emerald-800 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">
        <i class="ph-bold ph-arrow-left" aria-hidden="true"></i> Voltar ao painel
    </a>
    <div class="ml-auto flex flex-wrap gap-2">
        <button type="button" id="botao-imprimir" class="inline-flex items-center gap-2 rounded-lg bg-[#1e3a5f] px-4 py-2 text-sm font-semibold text-white hover:bg-[#17304f] focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
            <i class="ph-bold ph-printer" aria-hidden="true"></i> Imprimir / salvar como PDF
        </button>
        <button type="button" id="botao-pptx" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
            <i class="ph-bold ph-presentation-chart" aria-hidden="true"></i> <span>Baixar PowerPoint (.pptx)</span>
        </button>
    </div>
</div>
<p class="mb-6 text-sm text-slate-600 print:hidden">
    Esta é a prévia do relatório do recorte atual ({{ $ctx['avaliacao']['nome'] }} · {{ $periodoTxt }} · {{ count($cursos) }} cursos). Para o PDF, use "Imprimir" e escolha <em>Salvar como PDF</em> (papel A4, retrato; ative "gráficos de fundo" se o navegador perguntar).
    Para mudar o recorte, volte ao painel e use os filtros — o relatório acompanha.
</p>

<article class="mx-auto max-w-4xl bg-white print:max-w-none print:bg-transparent" aria-label="Relatório institucional">
    {{-- Capa --}}
    <section class="rel-capa rounded-2xl p-10 text-white" style="background: linear-gradient(135deg, #1e3a5f 0%, #17506a 55%, #0b6b53 100%); -webkit-print-color-adjust: exact; print-color-adjust: exact;">
        <p class="text-sm font-semibold uppercase tracking-widest text-white/80">Painel da reitoria</p>
        <h1 class="mt-4 text-4xl font-black">Relatório institucional</h1>
        <p class="mt-6 text-xl font-semibold">{{ $ctx['avaliacao']['nome'] }}</p>
        <p class="mt-1 text-white/85">{{ $periodoTxt }} &middot; {{ count($cursos) }} {{ count($cursos) === 1 ? 'curso' : 'cursos' }} &middot; {{ $fmt($total['fizeram'], 0) }} estudantes</p>
        <dl class="mt-16 grid max-w-md grid-cols-2 gap-x-6 gap-y-2 text-sm text-white/90">
            <dt class="font-semibold">Critério de proficiência</dt><dd>≥ {{ $fmt($corte, 0) }}% de acerto</dd>
            <dt class="font-semibold">Meta de participação</dt><dd>{{ $fmt($meta, 0) }}%</dd>
            <dt class="font-semibold">Gerado em</dt><dd>{{ now()->format('d/m/Y') }}</dd>
            <dt class="font-semibold">Gerado por</dt><dd>{{ $usuario->username }}</dd>
        </dl>
        <p class="mt-10 max-w-xl text-xs text-white/75">
            Critério interno da instituição, sem validação contra a proficiência do ENADE/ENAMED. Indicadores agregados: não há dado nominal de estudantes neste documento.
        </p>
    </section>

    @if ($ctx['avaliacao']['mistura'])
        <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 rel-bloco">
            Atenção: este recorte reúne avaliações de categorias diferentes, que não são comparáveis em desempenho. A participação vale; para proficiência, média e evolução, gere o relatório de uma categoria.
        </p>
    @endif

    {{-- 1 · Resumo --}}
    <section class="rel-quebra {{ $secao }}" aria-labelledby="rel-resumo">
        <h2 id="rel-resumo" class="text-lg font-black text-[#1e3a5f]">1. Resumo</h2>
        <div class="mt-4 grid grid-cols-2 gap-3 rel-bloco">
            @foreach ($dados['kpis'] as $k)
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-[11px] font-bold uppercase tracking-wide text-slate-600">{{ $k['rotulo'] }}</p>
                    <p class="mt-1 font-mono text-3xl font-black text-[#1e3a5f]">{{ $k['valor'] }}</p>
                    <p class="text-xs text-slate-600">{{ $k['detalhe'] }}</p>
                </div>
            @endforeach
        </div>
        @if ($alertas !== [])
            <h3 class="mt-6 text-sm font-bold uppercase tracking-wide text-[#1e3a5f]">Pontos de atenção</h3>
            <ul class="mt-2 space-y-2 text-sm text-slate-800">
                @foreach ($alertas as $a)
                    <li class="flex gap-2 rel-bloco"><span aria-hidden="true">•</span><span>{{ $a['texto'] }}</span></li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- 2 · Participação --}}
    <section class="rel-quebra {{ $secao }}" aria-labelledby="rel-participacao">
        <h2 id="rel-participacao" class="text-lg font-black text-[#1e3a5f]">2. Participação por curso</h2>
        @if ($texto('participacao'))<p class="mt-2 text-sm text-slate-800">{{ $texto('participacao') }}</p>@endif
        <table class="mt-4 w-full text-sm">
            <caption class="sr-only">Previstos, quem fez, participação, proficiência, média e mediana por curso.</caption>
            <thead class="text-left text-[11px] font-bold uppercase tracking-wide text-slate-600">
                <tr class="border-b border-slate-300">
                    <th scope="col" class="py-2 pr-2">Curso</th><th scope="col" class="py-2 px-2 text-right">Previstos</th><th scope="col" class="py-2 px-2 text-right">Fizeram</th>
                    <th scope="col" class="py-2 px-2 text-right">Participação</th><th scope="col" class="py-2 px-2 text-right">Proficientes</th><th scope="col" class="py-2 px-2 text-right">Média</th><th scope="col" class="py-2 pl-2 text-right">Mediana</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($dados['tabela'] as $linha)
                    <tr class="border-b border-slate-100">
                        <th scope="row" class="py-1.5 pr-2 text-left font-medium text-slate-800">{{ $linha[0] }}</th>
                        @foreach (array_slice($linha, 1) as $celula)
                            <td class="py-1.5 px-2 text-right font-mono">{{ $celula }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="font-bold text-slate-800">
                    <td class="py-2 pr-2">Total da visão</td>
                    <td class="py-2 px-2 text-right font-mono">{{ $fmt($total['previstos'], 0) }}</td><td class="py-2 px-2 text-right font-mono">{{ $fmt($total['fizeram'], 0) }}</td>
                    <td class="py-2 px-2 text-right font-mono">{{ $fmt($total['participacao']) }}%</td><td class="py-2 px-2 text-right font-mono">{{ $fmt($total['proficienciaPct']) }}%</td>
                    <td class="py-2 px-2 text-right font-mono">{{ $fmt($total['media']) }}%</td><td class="py-2 pl-2 text-right font-mono">{{ $fmt($total['mediana']) }}%</td>
                </tr>
            </tfoot>
        </table>
    </section>

    {{-- 3 · Proficiência --}}
    <section class="rel-quebra {{ $secao }}" aria-labelledby="rel-proficiencia">
        <h2 id="rel-proficiencia" class="text-lg font-black text-[#1e3a5f]">3. Proficiência institucional</h2>
        <p class="mt-1 text-xs text-slate-600">Estudantes que acertaram {{ $fmt($corte, 0) }}% ou mais. Critério interno, sem validação contra exames externos.</p>
        @if ($texto('proficiencia'))<p class="mt-2 text-sm text-slate-800">{{ $texto('proficiencia') }}</p>@endif
        <figure class="rel-figura mt-4"><div class="relative h-72"><canvas id="rel-grafico-prof" data-titulo="Estudantes proficientes por curso"></canvas></div></figure>
    </section>

    {{-- 4 · Desempenho --}}
    <section class="rel-quebra {{ $secao }}" aria-labelledby="rel-desempenho">
        <h2 id="rel-desempenho" class="text-lg font-black text-[#1e3a5f]">4. Desempenho na prova</h2>
        @if ($texto('media'))<p class="mt-2 text-sm text-slate-800">{{ $texto('media') }}</p>@endif
        <figure class="rel-figura mt-4"><div class="relative h-64"><canvas id="rel-grafico-media" data-titulo="Média e mediana por curso"></canvas></div></figure>
        @if ($texto('faixas'))<p class="mt-6 text-sm text-slate-800">{{ $texto('faixas') }}</p>@endif
        <figure class="rel-figura mt-4"><div class="relative" style="height: {{ 110 + 28 * (count($cursos) + 1) }}px"><canvas id="rel-grafico-faixas" data-titulo="Distribuição por faixa de acerto"></canvas></div></figure>
    </section>

    {{-- 5 · Trajetória --}}
    <section class="rel-quebra {{ $secao }}" aria-labelledby="rel-trajetoria">
        <h2 id="rel-trajetoria" class="text-lg font-black text-[#1e3a5f]">5. Trajetória ao longo do curso</h2>
        <p class="mt-1 text-xs text-slate-600">Cada período do curso é uma turma diferente fotografada no mesmo semestre. Períodos com menos de {{ $minimoPeriodo }} participantes não aparecem.</p>
        @if ($texto('trajetoria_acerto'))<p class="mt-2 text-sm text-slate-800">{{ $texto('trajetoria_acerto') }}</p>@endif
        <figure class="rel-figura mt-4"><div class="relative h-80"><canvas id="rel-grafico-trajetoria" data-titulo="Percentual de acerto médio por período do curso"></canvas></div></figure>
    </section>

    {{-- 6 · Competências --}}
    <section class="rel-quebra {{ $secao }}" aria-labelledby="rel-competencias">
        <h2 id="rel-competencias" class="text-lg font-black text-[#1e3a5f]">6. Competências</h2>
        @if ($dados['bloom'])
            @if ($texto('bloom_distingue'))<p class="mt-2 text-sm text-slate-800">{{ $texto('bloom_distingue') }}</p>@endif
            <figure class="rel-figura mt-4"><div class="relative h-64"><canvas id="rel-grafico-bloom" data-titulo="Acerto de proficientes e não proficientes por nível de Bloom"></canvas></div></figure>
        @else
            <p class="mt-2 text-sm text-slate-600">Nenhuma questão deste recorte tem o nível de Bloom cadastrado.</p>
        @endif
        @if ($ranking !== [])
            @if ($texto('areas_ranking'))<p class="mt-6 text-sm text-slate-800">{{ $texto('areas_ranking') }}</p>@endif
            <figure class="rel-figura mt-4"><div class="relative" style="height: {{ 90 + 26 * count($dados['areas']) }}px"><canvas id="rel-grafico-areas" data-titulo="Percentual de acerto por área (as mais frágeis)"></canvas></div></figure>
        @endif
    </section>

    {{-- 7 · Evolução --}}
    @if ($mostraEvolucao)
        <section class="rel-quebra {{ $secao }}" aria-labelledby="rel-evolucao">
            <h2 id="rel-evolucao" class="text-lg font-black text-[#1e3a5f]">7. Evolução entre semestres</h2>
            <p class="mt-1 text-xs text-slate-600">Cada ponto é uma foto do semestre, na mesma categoria de avaliação; não acompanha os mesmos estudantes.</p>
            @if ($texto('evolucao_institucional'))<p class="mt-2 text-sm text-slate-800">{{ $texto('evolucao_institucional') }}</p>@endif
            <figure class="rel-figura mt-4"><div class="relative h-72"><canvas id="rel-grafico-evolucao" data-titulo="Proficiência, média e participação por período letivo"></canvas></div></figure>
        </section>
    @endif
</article>

@include('reitor._base')
<script src="https://cdn.jsdelivr.net/npm/pptxgenjs@3.12.0/dist/pptxgen.bundle.js"></script>
<script>
(function () {
    var dados = @json($dados);
    var V = window.ReitorViz;
    var cursos = dados.cursosGraficos;
    // Relatório: sem animação (a imagem do gráfico tem que estar pronta quando imprimir ou exportar).
    Chart.defaults.animation = false;
    var graficos = {};

    function pct(v) { return V.pct(v); }

    graficos.prof = V.criar('rel-grafico-prof', {
        type: 'bar',
        data: { labels: cursos.map(function (c) { return c.nome; }), datasets: [{
            label: '% proficientes', data: cursos.map(function (c) { return c.prof; }),
            backgroundColor: cursos.map(function (c) { return c.prof !== null && dados.totalProf !== null && c.prof >= dados.totalProf ? V.cores.verde : V.cores.marinho; }), maxBarThickness: 48,
        }] },
        options: {
            maintainAspectRatio: false, scales: { y: V.eixoPct(), x: { grid: { display: false }, ticks: { maxRotation: 60, minRotation: 30 } } },
            plugins: { legend: { display: false }, linhaReferencia: { linhas: [{ valor: dados.totalProf, cor: V.cores.vermelho, rotulo: 'Proficiência geral do conjunto ' + pct(dados.totalProf) }] }, rotulosValores: { dataset: 0, formato: pct } },
        },
    });

    var porMedia = cursos.slice().sort(function (a, b) { return (b.media === null ? -1 : b.media) - (a.media === null ? -1 : a.media); });
    graficos.media = V.criar('rel-grafico-media', {
        type: 'bar',
        data: { labels: porMedia.map(function (c) { return c.nome; }), datasets: [
            { type: 'line', label: 'Mediana', data: porMedia.map(function (c) { return c.mediana; }), showLine: false, pointStyle: 'rectRot', pointRadius: 6, pointBackgroundColor: V.cores.ambar, pointBorderColor: '#ffffff', order: 0 },
            { label: 'Média', data: porMedia.map(function (c) { return c.media; }), backgroundColor: V.cores.marinho, maxBarThickness: 48, order: 1 },
        ] },
        options: {
            maintainAspectRatio: false, scales: { y: V.eixoPct(), x: { grid: { display: false }, ticks: { maxRotation: 60, minRotation: 30 } } },
            plugins: { legend: V.legenda('top'), linhaReferencia: { linhas: [{ valor: dados.corte, cor: V.cores.vermelho, rotulo: 'Critério ' + V.fmt(dados.corte, 0) + '%' }] } },
        },
    });

    var coresFaixas = ['#b5001f', '#e07b2f', '#efb23d', '#42dcc0', '#00a67e'];
    var linhasFaixas = cursos.map(function (c) { return { rotulo: c.nome, faixas: c.faixas }; }).concat([{ rotulo: 'TOTAL DA VISÃO', faixas: dados.totalFaixas }]);
    graficos.faixas = V.criar('rel-grafico-faixas', {
        type: 'bar',
        data: { labels: linhasFaixas.map(function (l) { return l.rotulo; }), datasets: dados.faixasRotulos.map(function (rotulo, i) {
            return { label: rotulo, data: linhasFaixas.map(function (l) { return l.faixas[i]; }), backgroundColor: coresFaixas[i], borderRadius: 0, borderSkipped: false };
        }) },
        options: { indexAxis: 'y', maintainAspectRatio: false, scales: { x: { stacked: true, min: 0, max: 100, ticks: { callback: function (v) { return v + '%'; } } }, y: { stacked: true, grid: { display: false } } }, plugins: { legend: V.legenda('top') } },
    });

    function serie(periodos) {
        var por = {};
        periodos.forEach(function (p) { por[p.ordinal] = p; });
        return dados.ordinais.map(function (o) { return por[o] && por[o].n >= dados.minimoPeriodo ? por[o].media : null; });
    }
    var linhas = cursos.map(function (c) {
        return { label: c.nome, data: serie(c.periodos), borderColor: c.cor, backgroundColor: c.cor, pointBackgroundColor: c.cor, tension: 0.35 };
    });
    linhas.push({ label: 'Total da visão', data: serie(dados.totalPeriodos), borderColor: V.cores.tinta, backgroundColor: V.cores.tinta, borderDash: [8, 5], borderWidth: 3, tension: 0.35 });
    graficos.trajetoria = V.criar('rel-grafico-trajetoria', {
        type: 'line',
        data: { labels: dados.ordinais.map(function (o) { return o + 'º'; }), datasets: linhas },
        options: { maintainAspectRatio: false, scales: { y: V.eixoPct({ title: { display: true, text: '% de acerto médio' } }), x: { title: { display: true, text: 'Período do curso' }, grid: { display: false } } }, plugins: { legend: V.legenda('bottom') } },
    });

    if (dados.bloom) {
        graficos.bloom = V.criar('rel-grafico-bloom', {
            type: 'bar',
            data: { labels: dados.bloom.rotulos, datasets: [
                { label: 'Proficientes', data: dados.bloom.prof, backgroundColor: V.cores.verde, maxBarThickness: 44 },
                { label: 'Não proficientes', data: dados.bloom.nao, backgroundColor: V.cores.cinza, maxBarThickness: 44 },
            ] },
            options: { maintainAspectRatio: false, scales: { y: V.eixoPct(), x: { grid: { display: false } } }, plugins: { legend: V.legenda('top') } },
        });
    }
    if (dados.areas.length) {
        graficos.areas = V.criar('rel-grafico-areas', {
            type: 'bar',
            data: { labels: dados.areas.map(function (a) { return a.area; }), datasets: [{ label: '% de acerto', data: dados.areas.map(function (a) { return a.pct; }), backgroundColor: V.cores.marinho, maxBarThickness: 18 }] },
            options: { indexAxis: 'y', maintainAspectRatio: false, layout: { padding: { right: 44 } }, scales: { x: V.eixoPct(), y: { grid: { display: false } } }, plugins: { legend: { display: false }, rotulosValores: { dataset: 0, horizontal: true, formato: pct } } },
        });
    }
    if (dados.evolucao) {
        graficos.evolucao = V.criar('rel-grafico-evolucao', {
            type: 'line',
            data: { labels: dados.evolucao.rotulos, datasets: [
                { label: 'Proficientes (%)', data: dados.evolucao.prof, borderColor: V.cores.verde, backgroundColor: V.cores.verde, tension: 0.3 },
                { label: 'Média de acerto (%)', data: dados.evolucao.media, borderColor: V.cores.marinho, backgroundColor: V.cores.marinho, tension: 0.3 },
                { label: 'Participação (%)', data: dados.evolucao.part, borderColor: V.cores.ambar, backgroundColor: V.cores.ambar, borderDash: [6, 4], tension: 0.3 },
            ] },
            options: { maintainAspectRatio: false, scales: { y: V.eixoPct(), x: { grid: { display: false } } }, plugins: { legend: V.legenda('top') } },
        });
    }

    // Imprimir / salvar como PDF: os gráficos se redesenham no tamanho da página.
    window.addEventListener('beforeprint', function () { Object.keys(graficos).forEach(function (k) { if (graficos[k]) graficos[k].resize(); }); });
    document.getElementById('botao-imprimir').addEventListener('click', function () { window.print(); });

    // PowerPoint: os mesmos números e leituras, com os gráficos como imagem. Tudo no navegador (PptxGenJS).
    document.getElementById('botao-pptx').addEventListener('click', function () {
        var botao = this, rotulo = botao.querySelector('span');
        if (!window.PptxGenJS) { rotulo.textContent = 'Biblioteca indisponível (sem internet?)'; return; }
        rotulo.textContent = 'Gerando...';
        var pptx = new PptxGenJS();
        pptx.layout = 'LAYOUT_WIDE';
        pptx.title = dados.titulo + ' — ' + dados.recorte;
        var marinho = '1E3A5F', verde = '00A67E', tinta = '0F1720', cinza = '566270';

        function slide(titulo) {
            var s = pptx.addSlide();
            s.background = { color: 'FFFFFF' };
            s.addShape(pptx.ShapeType.rect, { x: 0, y: 0, w: 13.33, h: 0.9, fill: { color: marinho } });
            s.addText(titulo, { x: 0.5, y: 0.1, w: 12.3, h: 0.7, fontSize: 24, bold: true, color: 'FFFFFF', fontFace: 'Calibri' });
            s.addText(dados.recorte + ' · ' + dados.periodo, { x: 0.5, y: 7.0, w: 10, h: 0.3, fontSize: 10, color: cinza });
            return s;
        }
        function comGrafico(titulo, grafico, leitura) {
            var s = slide(titulo);
            if (grafico) s.addImage({ data: grafico.toBase64Image('image/png', 1), x: 0.5, y: 1.1, w: leitura ? 8.2 : 12.3, h: leitura ? 5.7 : 5.8 });
            if (leitura) s.addText(leitura, { x: 8.9, y: 1.2, w: 3.95, h: 5.6, fontSize: 13, color: tinta, valign: 'top', fontFace: 'Calibri' });
        }

        // Capa
        var capa = pptx.addSlide();
        capa.background = { color: marinho };
        capa.addText('Painel da reitoria', { x: 0.8, y: 1.2, w: 11.5, h: 0.5, fontSize: 16, color: 'CFE8E1', bold: true, charSpacing: 4 });
        capa.addText(dados.titulo, { x: 0.8, y: 1.9, w: 11.5, h: 1.2, fontSize: 44, bold: true, color: 'FFFFFF' });
        capa.addText(dados.recorte, { x: 0.8, y: 3.4, w: 11.5, h: 0.6, fontSize: 24, color: 'FFFFFF' });
        capa.addText(dados.periodo + ' · ' + dados.cursos + ' cursos', { x: 0.8, y: 4.0, w: 11.5, h: 0.4, fontSize: 16, color: 'CFE8E1' });
        capa.addText('Critério de proficiência ≥ ' + V.fmt(dados.corte, 0) + '% (interno, sem validação contra ENADE/ENAMED) · Meta de participação ' + V.fmt(dados.meta, 0) + '%\nGerado em ' + dados.geradoEm + ' por ' + dados.geradoPor, { x: 0.8, y: 6.2, w: 11.5, h: 0.8, fontSize: 11, color: 'CFE8E1' });

        // Resumo
        var resumo = slide('Resumo');
        dados.kpis.forEach(function (k, i) {
            var x = 0.5 + (i % 2) * 6.2, y = 1.2 + Math.floor(i / 2) * 1.7;
            resumo.addShape(pptx.ShapeType.roundRect, { x: x, y: y, w: 6.0, h: 1.5, fill: { color: 'F4F8F7' }, line: { color: 'D5DEDB' }, rectRadius: 0.1 });
            resumo.addText(k.rotulo.toUpperCase(), { x: x + 0.2, y: y + 0.1, w: 5.6, h: 0.3, fontSize: 10, bold: true, color: cinza });
            resumo.addText(k.valor, { x: x + 0.2, y: y + 0.4, w: 5.6, h: 0.7, fontSize: 32, bold: true, color: marinho, fontFace: 'Consolas' });
            resumo.addText(k.detalhe, { x: x + 0.2, y: y + 1.1, w: 5.6, h: 0.3, fontSize: 11, color: cinza });
        });
        if (dados.alertas.length) {
            resumo.addText(dados.alertas.slice(0, 4).map(function (t) { return { text: t, options: { bullet: true, breakLine: true } }; }), { x: 0.5, y: 4.7, w: 12.3, h: 2.2, fontSize: 12, color: tinta, valign: 'top' });
        }

        // Participação (tabela, em páginas de 10 cursos)
        for (var i = 0; i < dados.tabela.length; i += 10) {
            var linhas = [['Curso', 'Previstos', 'Fizeram', 'Participação', 'Proficientes', 'Média', 'Mediana'].map(function (t) { return { text: t, options: { bold: true, color: 'FFFFFF', fill: { color: marinho }, fontSize: 11 } }; })]
                .concat(dados.tabela.slice(i, i + 10).map(function (l) { return l.map(function (t, j) { return { text: t, options: { fontSize: 11, align: j === 0 ? 'left' : 'right' } }; }); }));
            var s = slide('Participação por curso' + (dados.tabela.length > 10 ? ' (' + (Math.floor(i / 10) + 1) + ')' : ''));
            s.addTable(linhas, { x: 0.5, y: 1.2, w: 12.3, colW: [4.2, 1.3, 1.3, 1.5, 1.5, 1.2, 1.3], border: { type: 'solid', color: 'D5DEDB', pt: 0.5 } });
            if (i === 0 && dados.leituras.participacao) s.addText(dados.leituras.participacao, { x: 0.5, y: 6.1, w: 12.3, h: 0.8, fontSize: 11, color: tinta, valign: 'top' });
        }

        comGrafico('Proficiência institucional', graficos.prof, dados.leituras.proficiencia);
        comGrafico('Média e mediana', graficos.media, dados.leituras.media);
        comGrafico('Distribuição por faixa de acerto', graficos.faixas, dados.leituras.faixas);
        comGrafico('Trajetória ao longo do curso', graficos.trajetoria, dados.leituras.trajetoria);
        if (graficos.bloom) comGrafico('Acerto por nível de Bloom', graficos.bloom, dados.leituras.bloom);
        if (graficos.areas) comGrafico('Áreas de conhecimento', graficos.areas, dados.leituras.areas);
        if (graficos.evolucao) comGrafico('Evolução entre semestres', graficos.evolucao, dados.leituras.evolucao);

        var arquivo = 'relatorio-institucional-' + (dados.periodo.normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^0-9A-Za-z]+/g, '-').toLowerCase()) + '.pptx';
        pptx.writeFile({ fileName: arquivo }).then(function () { rotulo.textContent = 'Baixar PowerPoint (.pptx)'; }, function () { rotulo.textContent = 'Não foi possível gerar'; });
    });
})();
</script>
@endsection
