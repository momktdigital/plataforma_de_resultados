@extends('layouts.app')

@section('title', 'Trajetória no curso — Painel da reitoria')

@php
    $fmt = fn ($v, $casas = 1) => $v === null ? '—' : number_format($v, $casas, ',', '.');
    $cursos = array_values($est['cursos']);
    $total = $est['total'];
    $corte = $ctx['corte'];
    $corteTxt = $fmt($corte, 0);
    $minimo = $minimoPeriodo;

    // Eixo: todos os períodos do curso que aparecem em algum curso (com resultado ou com aluno ativo sem aplicação).
    $ordinais = collect($est['periodos'])
        ->merge(collect($cursos)->flatMap(fn ($c) => array_keys($c['ativosSemAplicacao'])))
        ->unique()->sort()->values()->all();

    $celula = fn (array $p) => [
        'n' => $p['n'], 'previstos' => $p['previstos'], 'fizeram' => $p['fizeram'],
        'media' => $p['media'], 'prof' => $p['proficienciaPct'], 'part' => $p['participacao'],
    ];

    $dados = [
        'corte' => $corte,
        'minimo' => $minimo,
        'ordinais' => $ordinais,
        'total' => ['nome' => 'Total da visão', 'periodos' => array_map($celula, $total['periodos'])],
        'cursos' => array_map(fn ($c) => [
            'chave' => $c['chave'],
            'nome' => $c['nome'],
            'cor' => $ctx['cores'][$c['chave']] ?? '#64748b',
            'periodos' => array_map($celula, $c['periodos']),
        ], $cursos),
        'crescimentos' => array_map(fn ($c) => ['nome' => $c['nome'], 'inclinacao' => $c['inclinacao'], 'cor' => $ctx['cores'][$c['chave']] ?? '#64748b'], $crescimentos),
    ];
@endphp

@section('content')
@include('reitor._cabecalho', [
    'ctx' => $ctx,
    'aba' => 'trajetoria',
    'chips' => [
        ['valor' => \App\Support\Previstos::rotuloDosPeriodos($est['periodos']), 'rotulo' => 'Períodos avaliados'],
    ],
])

@include('reitor._filtros', ['ctx' => $ctx, 'exportar' => true])

<div class="mb-2 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
    <i class="ph-bold ph-info mr-1" aria-hidden="true"></i>
    Cada período do curso (1º, 2º...) é uma <strong>turma diferente</strong> fotografada no mesmo semestre: as linhas mostram como o resultado muda de uma turma para a seguinte, não o caminho de um mesmo estudante.
    Períodos com menos de {{ $minimo }} participantes ficam fora das linhas.
</div>

{{-- 1 · Proficiência ao longo do curso --}}
@include('reitor._secao', ['numero' => 1, 'titulo' => 'Proficiência ao longo do curso', 'id' => 'secao-traj-prof'])
<section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="secao-traj-prof">
    <p class="text-xs font-bold uppercase tracking-wide text-emerald-800 mb-3">% de estudantes proficientes (≥ {{ $corteTxt }}%) em cada período do curso</p>
    <div class="relative h-96"><canvas id="grafico-traj-prof" data-titulo="Proficiência ao longo do curso, por curso"></canvas></div>
    @include('reitor._rodape-quadro', ['id' => 'traj-prof', 'sobre' => $sobre['trajetoria_proficiencia'], 'leitura' => $leituras['trajetoria_proficiencia'] ?? null])
</section>

{{-- 2 · Acerto por período --}}
@include('reitor._secao', ['numero' => 2, 'titulo' => 'Percentual de acerto por período — quanto cada curso cresce', 'id' => 'secao-traj-acerto'])
<section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="secao-traj-acerto">
    <div class="relative h-96"><canvas id="grafico-traj-acerto" data-titulo="Percentual de acerto médio por período do curso"></canvas></div>
    @include('reitor._rodape-quadro', ['id' => 'traj-acerto', 'sobre' => $sobre['trajetoria_acerto'], 'leitura' => $leituras['trajetoria_acerto'] ?? null])
</section>

{{-- 3 · Mapa de calor --}}
@include('reitor._secao', ['numero' => 3, 'titulo' => 'Mapa de calor: curso × período do curso', 'id' => 'secao-mapa'])
<section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="secao-mapa">
    <div class="mb-3 flex flex-wrap items-center gap-2" role="group" aria-label="Indicador do mapa de calor">
        @foreach ([['media', 'Média de acerto'], ['prof', 'Proficientes'], ['part', 'Participação'], ['n', 'Participantes']] as $i => [$valor, $rotulo])
            <button type="button" data-metrica="{{ $valor }}" aria-pressed="{{ $i === 0 ? 'true' : 'false' }}"
                    class="rounded-full border px-4 py-1.5 text-sm font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-primary {{ $i === 0 ? 'border-[#1e3a5f] bg-[#1e3a5f] text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">{{ $rotulo }}</button>
        @endforeach
    </div>
    <div class="overflow-x-auto"><table id="mapa-periodos" class="w-full text-sm border-separate" style="border-spacing: 2px"></table></div>
    <p class="mt-2 text-xs text-slate-600">Células vazias: período sem aplicação ou com menos de {{ $minimo }} participantes. Passe o mouse sobre a célula para ver os números.</p>
    @include('reitor._rodape-quadro', ['id' => 'mapa', 'sobre' => $sobre['mapa_periodo'], 'leitura' => $leituras['mapa_periodo'] ?? null])
</section>

{{-- 4 · Crescimento --}}
@include('reitor._secao', ['numero' => 4, 'titulo' => 'Crescimento ao longo do curso', 'id' => 'secao-crescimento'])
<section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="secao-crescimento">
    @if (count($crescimentos) < 1)
        <p class="text-sm text-slate-600">Nenhum curso tem dois ou mais períodos com participantes suficientes para estimar o crescimento.</p>
    @else
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="relative" style="height: {{ 90 + 34 * count($crescimentos) }}px"><canvas id="grafico-crescimento" data-titulo="Crescimento do percentual de acerto por período, por curso"></canvas></div>
            <div class="overflow-x-auto">
                <table data-ordenavel class="w-full text-sm">
                    <caption class="sr-only">Crescimento de cada curso: pontos percentuais por período e diferença entre o último e o primeiro período avaliado.</caption>
                    <thead class="text-left text-[11px] font-bold uppercase tracking-wide text-slate-600">
                        <tr class="border-b border-slate-200">
                            <th scope="col" data-ordem="texto" class="px-3 py-2"><button type="button" class="font-bold uppercase tracking-wide focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">Curso</button></th>
                            <th scope="col" data-ordem="numero" class="px-3 py-2 text-right"><button type="button" class="font-bold uppercase tracking-wide focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">pp por período</button></th>
                            <th scope="col" data-ordem="numero" class="px-3 py-2 text-right"><button type="button" class="font-bold uppercase tracking-wide focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">Último − primeiro</button></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($crescimentos as $c)
                            <tr class="border-b border-slate-100">
                                <td data-valor="{{ $c['nome'] }}" class="px-3 py-2.5 font-medium text-slate-800">{{ $c['nome'] }}</td>
                                <td data-valor="{{ $c['inclinacao'] }}" class="px-3 py-2.5 text-right font-mono font-bold {{ $c['inclinacao'] < 0 ? 'text-red-700' : 'text-emerald-700' }}">{{ ($c['inclinacao'] > 0 ? '+' : ($c['inclinacao'] < 0 ? '−' : '')).$fmt(abs($c['inclinacao'])) }}</td>
                                <td data-valor="{{ $c['delta'] }}" class="px-3 py-2.5 text-right font-mono">{{ ($c['delta'] > 0 ? '+' : ($c['delta'] < 0 ? '−' : '')).$fmt(abs($c['delta'])) }} pp <span class="text-xs text-slate-500">({{ $c['de'] }}→{{ $c['ate'] }})</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                    @if ($crescimentoTotal)
                        <tfoot>
                            <tr class="bg-slate-50 font-bold text-slate-800">
                                <td class="px-3 py-2.5">Total da visão</td>
                                <td class="px-3 py-2.5 text-right font-mono">{{ ($crescimentoTotal['inclinacao'] > 0 ? '+' : ($crescimentoTotal['inclinacao'] < 0 ? '−' : '')).$fmt(abs($crescimentoTotal['inclinacao'])) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono">{{ ($crescimentoTotal['delta'] > 0 ? '+' : ($crescimentoTotal['delta'] < 0 ? '−' : '')).$fmt(abs($crescimentoTotal['delta'])) }} pp</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    @endif
    @include('reitor._rodape-quadro', ['id' => 'crescimento', 'sobre' => $sobre['crescimento'], 'leitura' => $leituras['crescimento'] ?? null])
</section>

{{-- 5 · Cobertura da aplicação --}}
@include('reitor._secao', ['numero' => 5, 'titulo' => 'Cobertura da aplicação: onde a prova chegou', 'id' => 'secao-cobertura'])
<section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="secao-cobertura">
    <ul class="mb-3 flex flex-wrap gap-4 text-xs text-slate-600" aria-label="Legenda">
        <li class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm" style="background:#d8f3ec"></span> aplicada (participação do período)</li>
        <li class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm" style="background: repeating-linear-gradient(45deg,#fdf0cf,#fdf0cf 3px,#f5c75a 3px,#f5c75a 5px)"></span> alunos ativos sem aplicação</li>
        <li class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm bg-slate-100 border border-slate-200"></span> sem alunos / sem dados</li>
    </ul>
    <div class="overflow-x-auto">
        <table class="w-full text-sm border-separate" style="border-spacing: 2px">
            <caption class="sr-only">Para cada curso e período do curso: se a avaliação foi aplicada (e a participação) ou se há alunos ativos sem aplicação.</caption>
            <thead class="text-[11px] font-bold uppercase tracking-wide text-slate-600">
                <tr>
                    <th scope="col" class="px-3 py-2 text-left">Curso</th>
                    @foreach ($ordinais as $o)
                        <th scope="col" class="px-3 py-2 text-center">{{ $o }}º</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($cursos as $c)
                    <tr>
                        <th scope="row" class="px-3 py-2 text-left font-medium text-slate-800">{{ $c['nome'] }}</th>
                        @foreach ($ordinais as $o)
                            @if (isset($c['periodos'][$o]))
                                @php $p = $c['periodos'][$o]; @endphp
                                <td class="px-3 py-2 text-center font-mono" style="background:#d8f3ec;color:#0b4f3f" title="{{ $p['fizeram'] }} de {{ $p['previstos'] }} previstos fizeram a prova">{{ $fmt($p['participacao'], 0) }}%</td>
                            @elseif (isset($c['ativosSemAplicacao'][$o]))
                                <td class="px-3 py-2 text-center font-mono font-bold" style="background: repeating-linear-gradient(45deg,#fdf0cf,#fdf0cf 6px,#f9e2a0 6px,#f9e2a0 12px);color:#7a4b00" title="{{ $c['ativosSemAplicacao'][$o] }} aluno(s) ativo(s) sem aplicação da prova">{{ $c['ativosSemAplicacao'][$o] }} ativos</td>
                            @else
                                <td class="px-3 py-2 text-center bg-slate-100 text-slate-500" aria-label="sem dados">·</td>
                            @endif
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @include('reitor._rodape-quadro', ['id' => 'cobertura', 'sobre' => $sobre['cobertura'], 'leitura' => $leituras['cobertura'] ?? null])
</section>

@include('reitor._base')
<script>
(function () {
    var dados = @json($dados);
    var V = window.ReitorViz;
    var ordinais = dados.ordinais;
    var minimo = dados.minimo;

    function serie(periodos, campo) {
        return ordinais.map(function (o) {
            var p = periodos[o];
            return p && p.n >= minimo && p[campo] !== null ? p[campo] : null;
        });
    }

    function linhas(id, campo, referencia) {
        var datasets = dados.cursos.map(function (c) {
            return {
                label: c.nome,
                data: serie(c.periodos, campo),
                borderColor: c.cor,
                backgroundColor: c.cor,
                pointBackgroundColor: c.cor,
                tension: 0.35,
                spanGaps: false,
            };
        });
        datasets.push({
            label: 'Total da visão',
            data: serie(dados.total.periodos, campo),
            borderColor: V.cores.tinta,
            backgroundColor: V.cores.tinta,
            pointBackgroundColor: V.cores.tinta,
            borderDash: [8, 5],
            borderWidth: 3,
            tension: 0.35,
        });
        V.criar(id, {
            type: 'line',
            data: { labels: ordinais.map(function (o) { return o + 'º'; }), datasets: datasets },
            options: {
                maintainAspectRatio: false,
                interaction: { mode: 'nearest', intersect: true },
                scales: {
                    y: V.eixoPct({ title: { display: true, text: campo === 'prof' ? '% de estudantes proficientes' : '% de acerto médio' } }),
                    x: { title: { display: true, text: 'Período do curso' }, grid: { display: false } },
                },
                plugins: {
                    legend: V.legenda('top'),
                    linhaReferencia: referencia ? { linhas: [{ valor: dados.corte, cor: '#94a3b8', rotulo: 'Critério ' + V.fmt(dados.corte, 0) + '%' }] } : {},
                    tooltip: { callbacks: { label: function (item) {
                        var origem = item.datasetIndex < dados.cursos.length ? dados.cursos[item.datasetIndex] : dados.total;
                        var p = origem.periodos[ordinais[item.dataIndex]];
                        return item.dataset.label + ': ' + V.pct(item.raw) + (p ? ' (n=' + p.n + ')' : '');
                    } } },
                },
            },
        });
    }
    linhas('grafico-traj-prof', 'prof', false);
    linhas('grafico-traj-acerto', 'media', true);

    // 3 · Mapa de calor com troca de indicador.
    var tabela = document.getElementById('mapa-periodos');
    var botoes = document.querySelectorAll('[data-metrica]');
    var metrica = 'media';
    var maiorN = 1;
    dados.cursos.forEach(function (c) { Object.keys(c.periodos).forEach(function (o) { maiorN = Math.max(maiorN, c.periodos[o].n); }); });

    function desenharMapa() {
        var html = '<thead class="text-[11px] font-bold uppercase tracking-wide text-slate-600"><tr><th scope="col" class="px-3 py-2 text-left">Curso</th>'
            + ordinais.map(function (o) { return '<th scope="col" class="px-3 py-2 text-center">' + o + 'º</th>'; }).join('') + '</tr></thead><tbody>';
        dados.cursos.forEach(function (c) {
            html += '<tr><th scope="row" class="px-3 py-2 text-left font-medium text-slate-800">' + V.esc(c.nome) + '</th>';
            ordinais.forEach(function (o) {
                var p = c.periodos[o];
                var valido = p && p.n >= minimo;
                var valor = valido ? (metrica === 'n' ? p.n : p[metrica]) : null;
                if (valor === null || valor === undefined) {
                    html += '<td class="px-3 py-2 text-center" style="background:#f1f5f9;color:#64748b" aria-label="sem dados">·</td>';
                    return;
                }
                var escala = metrica === 'n' ? valor / maiorN * 100 : valor;
                var texto = metrica === 'n' ? String(valor) : V.fmt(valor, 0) + '%';
                var dica = V.esc(c.nome) + ' · ' + o + 'º período: média ' + V.pct(p.media) + ', proficientes ' + V.pct(p.prof) + ', participação ' + V.pct(p.part) + ', ' + p.n + ' participantes';
                html += '<td class="px-3 py-2 text-center font-mono font-semibold" style="background:' + Viz.corSequencial(escala) + ';color:' + Viz.tintaSobreSequencial(escala) + '" title="' + dica + '">' + texto + '</td>';
            });
            html += '</tr>';
        });
        tabela.innerHTML = html + '</tbody>';
    }
    botoes.forEach(function (botao) {
        botao.addEventListener('click', function () {
            metrica = botao.getAttribute('data-metrica');
            botoes.forEach(function (b) {
                var ativo = b === botao;
                b.setAttribute('aria-pressed', ativo ? 'true' : 'false');
                b.className = 'rounded-full border px-4 py-1.5 text-sm font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-primary '
                    + (ativo ? 'border-[#1e3a5f] bg-[#1e3a5f] text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50');
            });
            desenharMapa();
        });
    });
    desenharMapa();

    // 4 · Crescimento (pp por período): verde cresce, vermelho cai.
    if (dados.crescimentos.length) {
        V.criar('grafico-crescimento', {
            type: 'bar',
            data: {
                labels: dados.crescimentos.map(function (c) { return c.nome; }),
                datasets: [{
                    label: 'pp por período',
                    data: dados.crescimentos.map(function (c) { return c.inclinacao; }),
                    backgroundColor: dados.crescimentos.map(function (c) { return c.inclinacao >= 0 ? V.cores.verde : V.cores.vermelho; }),
                    maxBarThickness: 22,
                }],
            },
            options: {
                indexAxis: 'y',
                maintainAspectRatio: false,
                scales: { x: { title: { display: true, text: 'pontos percentuais de acerto por período do curso' } }, y: { grid: { display: false } } },
                plugins: {
                    legend: { display: false },
                    rotulosValores: { dataset: 0, horizontal: true, formato: function (v) { return V.pp(v); } },
                    tooltip: { callbacks: { label: function (item) { return V.pp(item.raw) + ' por período'; } } },
                },
                layout: { padding: { right: 60 } },
            },
        });
    }
})();
</script>
@endsection
