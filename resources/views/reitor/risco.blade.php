@extends('layouts.app')

@section('title', 'Estudantes em risco — Painel da reitoria')

@php
    $fmt = fn ($v, $casas = 1) => $v === null ? '—' : number_format($v, $casas, ',', '.');
    $cursos = array_values($risco['cursos']);
    $total = $risco['total'];
    $minimo = $risco['minimo'];
    $regra = $risco['regra'];
    $acertoTxt = $regra['acerto'] !== null ? \App\Support\RegraDeRisco::numero($regra['acerto']) : null;
    $rotuloFalta = $regra['faltas'] !== null ? 'Faltou a '.$regra['faltas'].($regra['faltas'] > 1 ? ' ou mais aplicações' : ' aplicação ou mais') : 'Faltas';
    $rotuloAcerto = $acertoTxt !== null ? 'Média abaixo de '.$acertoTxt.'%' : 'Acerto';
    $estCursos = $est['cursos'];

    $classeDelta = fn (?float $d) => $d === null ? 'text-slate-500' : (abs($d) < 1 ? 'text-slate-600' : ($d > 0 ? 'text-red-700' : 'text-emerald-700'));
    $setaTxt = fn (?float $d) => $d === null ? '—' : ($d > 0 ? '▲ +' : ($d < 0 ? '▼ −' : '= ')).$fmt(abs($d)).' pp';

    // cor do mapa: quanto MAIS risco, mais escuro o vermelho
    $corRisco = function (?float $v): array {
        if ($v === null) {
            return ['bg' => '#f1f5f9', 'fg' => '#64748b'];
        }

        return match (true) {
            $v >= 40 => ['bg' => '#9b1c1c', 'fg' => '#ffffff'],
            $v >= 25 => ['bg' => '#e57373', 'fg' => '#0f1720'],
            $v >= 10 => ['bg' => '#fbd0d0', 'fg' => '#7f1d1d'],
            default => ['bg' => '#e8f6f1', 'fg' => '#0b4f3f'],
        };
    };

    $dados = [
        'cursos' => array_map(fn ($c) => [
            'chave' => $c['chave'],
            'nome' => $c['nome'],
            'falta' => $c['pctPorFalta'],
            'acerto' => $c['pctPorAcerto'],
            'pessoas' => $c['pessoas'],
        ], $cursos),
        'total' => ['falta' => $total['pctPorFalta'], 'acerto' => $total['pctPorAcerto']],
        'rotuloFalta' => $rotuloFalta,
        'rotuloAcerto' => $rotuloAcerto,
        'faltaLigada' => $regra['faltas'] !== null,
        'acertoLigado' => $regra['acertoAtivo'],
    ];
@endphp

@section('content')
@include('reitor._cabecalho', [
    'ctx' => $ctx,
    'aba' => 'risco',
    'chips' => [
        ['valor' => $risco['temDados'] ? $fmt($total['pctRisco']).'%' : '—', 'rotulo' => 'Em risco'],
        ['valor' => $fmt($total['pessoas'], 0), 'rotulo' => 'Estudantes'],
    ],
])

@include('reitor._filtros', ['ctx' => $ctx, 'exportar' => true])

<div class="mb-4 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
    <i class="ph-bold ph-info mr-1" aria-hidden="true"></i>
    Só <strong>números agregados</strong>: a lista nominal dos estudantes é do coordenador de cada curso (o nome do curso abre a análise dele).
    <strong>Em risco</strong> é quem se enquadra na regra da instituição: <strong>{{ $regra['descricao'] }}</strong>
    (definida por quem administra o sistema em Configurações; cada avaliação pode ter uma regra própria). Grupos com menos de {{ $minimo }} estudantes não mostram percentual.
</div>

@if (! $risco['temDados'])
    <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm text-amber-900">
        <p class="font-semibold mb-1">Este recorte tem poucos estudantes ({{ $fmt($total['pessoas'], 0) }}) para mostrar percentuais.</p>
        Escolha uma categoria com mais avaliações, mais cursos ou o período <strong>Todos os períodos</strong>.
    </div>
@endif
@if ($regra['faltasInalcancavel'])
    <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <i class="ph-bold ph-warning mr-1" aria-hidden="true"></i>
        A regra de faltas ({{ $regra['faltas'] }} ou mais) não pode ser atingida neste recorte: ele tem menos aplicações que isso. Para medir faltas, escolha uma categoria com várias avaliações (como os simulados) ou <strong>Todos os períodos</strong>.
    </div>
@endif

<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4 mb-2">
    <div class="rounded-2xl border border-red-200 border-t-4 border-t-red-700 bg-red-50 p-5 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wide text-red-900">Estudantes em risco</p>
        <p class="mt-2 font-mono text-4xl font-black text-red-800">{{ $fmt($total['pctRisco']) }}<span class="text-2xl">%</span></p>
        <p class="mt-1 text-xs text-slate-700">{{ $fmt($total['risco'], 0) }} de {{ $fmt($total['pessoas'], 0) }} estudantes</p>
        @if ($total['deltaRisco'] !== null)<p class="mt-1 text-xs font-semibold {{ $classeDelta($total['deltaRisco']) }}">{{ $setaTxt($total['deltaRisco']) }} vs {{ $risco['semestreAnterior'] }}</p>@endif
    </div>
    <div class="rounded-2xl border border-slate-200 border-t-4 border-t-amber-500 bg-white p-5 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wide text-slate-600">Por faltas</p>
        @if ($regra['faltas'] !== null)
            <p class="mt-2 font-mono text-4xl font-black text-[#1e3a5f]">{{ $fmt($total['pctPorFalta']) }}<span class="text-2xl">%</span></p>
            <p class="mt-1 text-xs text-slate-600">{{ $fmt($total['porFalta'], 0) }} estudantes: {{ strtolower($rotuloFalta) }}</p>
        @else
            <p class="mt-2 font-mono text-4xl font-black text-slate-500" aria-label="critério desligado">—</p>
            <p class="mt-1 text-xs text-slate-600">Critério de faltas desligado</p>
        @endif
    </div>
    <div class="rounded-2xl border border-slate-200 border-t-4 border-t-violet-500 bg-white p-5 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wide text-slate-600">Por acerto</p>
        @if ($regra['acertoAtivo'])
            <p class="mt-2 font-mono text-4xl font-black text-[#1e3a5f]">{{ $fmt($total['pctPorAcerto']) }}<span class="text-2xl">%</span></p>
            <p class="mt-1 text-xs text-slate-600">{{ $fmt($total['porAcerto'], 0) }} estudantes: {{ strtolower($rotuloAcerto) }}</p>
        @else
            <p class="mt-2 font-mono text-4xl font-black text-slate-500" aria-label="critério desligado">—</p>
            <p class="mt-1 text-xs text-slate-600">Critério de acerto desligado</p>
        @endif
    </div>
    <div class="rounded-2xl border border-slate-200 border-t-4 border-t-[#1e3a5f] bg-white p-5 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wide text-slate-600">Na faixa mais baixa (agora)</p>
        <p class="mt-2 font-mono text-4xl font-black text-[#1e3a5f]">{{ $fmt($est['total']['faixas'][0]['pct'] ?? null) }}<span class="text-2xl">%</span></p>
        <p class="mt-1 text-xs text-slate-600">{{ $est['total']['faixas'][0]['rotulo'] ?? '' }} de acerto, entre quem fez a prova (vale com uma aplicação só)</p>
    </div>
</div>

{{-- 1 · Por curso --}}
@include('reitor._secao', ['numero' => 1, 'titulo' => 'Risco por curso', 'id' => 'secao-risco-cursos'])
<section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="secao-risco-cursos">
    <div class="overflow-x-auto">
        <table data-ordenavel class="w-full text-sm">
            <caption class="sr-only">Para cada curso: estudantes, por faltas, por acerto, em risco, variação frente ao semestre anterior e percentual na faixa mais baixa.</caption>
            <thead class="text-left text-[11px] font-bold uppercase tracking-wide text-slate-600">
                <tr class="border-b border-slate-200">
                    @foreach ([['Curso', 'texto'], ['Estudantes', 'numero'], ['Por faltas', 'numero'], ['Por acerto', 'numero'], ['Em risco', 'numero'], ['Variação', 'numero'], ['Faixa mais baixa', 'numero'], ['Ausentes', 'numero']] as [$rotulo, $tipo])
                        <th scope="col" data-ordem="{{ $tipo }}" class="px-3 py-2 {{ $tipo === 'numero' ? 'text-right' : '' }}">
                            <button type="button" class="inline-flex items-center gap-1 font-bold uppercase tracking-wide hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">{{ $rotulo }} <i class="ph ph-arrows-down-up text-xs" aria-hidden="true"></i></button>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($cursos as $c)
                    @php
                        $e = $estCursos[$c['chave']] ?? null;
                        $alto = $c['pctRisco'] !== null && $total['pctRisco'] !== null && $c['pctRisco'] > $total['pctRisco'];
                    @endphp
                    <tr class="border-b border-slate-100 {{ $alto ? 'bg-red-50' : '' }}">
                        <td data-valor="{{ $c['nome'] }}" class="px-3 py-2.5 font-medium text-slate-800 border-l-4 {{ $alto ? 'border-red-700' : 'border-transparent' }}">@include('reitor._nome-curso', ['chave' => $c['chave'], 'nome' => $c['nome'], 'destino' => 'alunos', 'extra' => ['situacao' => 'atencao']])</td>
                        <td data-valor="{{ $c['pessoas'] }}" class="px-3 py-2.5 text-right font-mono">{{ $fmt($c['pessoas'], 0) }}</td>
                        <td data-valor="{{ $c['pctPorFalta'] }}" class="px-3 py-2.5 text-right font-mono">{{ $c['pctPorFalta'] === null ? '—' : $fmt($c['pctPorFalta']).'%' }} <span class="text-xs text-slate-500">({{ $c['porFalta'] }})</span></td>
                        <td data-valor="{{ $c['pctPorAcerto'] }}" class="px-3 py-2.5 text-right font-mono">{{ $c['pctPorAcerto'] === null ? '—' : $fmt($c['pctPorAcerto']).'%' }} <span class="text-xs text-slate-500">({{ $c['porAcerto'] }})</span></td>
                        <td data-valor="{{ $c['pctRisco'] }}" class="px-3 py-2.5 text-right font-mono font-bold">{{ $c['pctRisco'] === null ? '—' : $fmt($c['pctRisco']).'%' }} <span class="text-xs font-normal text-slate-500">({{ $c['risco'] }})</span></td>
                        <td data-valor="{{ $c['deltaRisco'] }}" class="px-3 py-2.5 text-right font-mono text-xs font-semibold {{ $classeDelta($c['deltaRisco']) }}">{{ $setaTxt($c['deltaRisco']) }}</td>
                        <td data-valor="{{ $e['faixas'][0]['pct'] ?? '' }}" class="px-3 py-2.5 text-right font-mono">{{ $fmt($e['faixas'][0]['pct'] ?? null) }}%</td>
                        <td data-valor="{{ $e['ausentes'] ?? '' }}" class="px-3 py-2.5 text-right font-mono">{{ $fmt($e['ausentes'] ?? null, 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="bg-slate-50 font-bold text-slate-800">
                    <td class="px-3 py-2.5">Total da visão</td>
                    <td class="px-3 py-2.5 text-right font-mono">{{ $fmt($total['pessoas'], 0) }}</td>
                    <td class="px-3 py-2.5 text-right font-mono">{{ $total['pctPorFalta'] === null ? '—' : $fmt($total['pctPorFalta']).'%' }}</td>
                    <td class="px-3 py-2.5 text-right font-mono">{{ $total['pctPorAcerto'] === null ? '—' : $fmt($total['pctPorAcerto']).'%' }}</td>
                    <td class="px-3 py-2.5 text-right font-mono">{{ $total['pctRisco'] === null ? '—' : $fmt($total['pctRisco']).'%' }}</td>
                    <td class="px-3 py-2.5 text-right font-mono text-xs {{ $classeDelta($total['deltaRisco']) }}">{{ $setaTxt($total['deltaRisco']) }}</td>
                    <td class="px-3 py-2.5 text-right font-mono">{{ $fmt($est['total']['faixas'][0]['pct'] ?? null) }}%</td>
                    <td class="px-3 py-2.5 text-right font-mono">{{ $fmt($est['total']['ausentes'], 0) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
    <p class="mt-2 text-xs text-slate-600">Linhas em vermelho: risco acima do total da visão. Entre parênteses, o número de estudantes. O nome do curso abre a lista de alunos em atenção dele (visão do coordenador).</p>
    @include('reitor._rodape-quadro', ['id' => 'risco-cursos', 'sobre' => $sobre['risco_cursos'], 'leitura' => $leituras['risco_cursos'] ?? null])
</section>

{{-- 2 · Comparação das duas situações --}}
@include('reitor._secao', ['numero' => 2, 'titulo' => 'Faltas × acerto: o que leva cada curso ao risco', 'id' => 'secao-risco-grafico'])
<section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="secao-risco-grafico">
    @if ($risco['temDados'])
        <div class="relative h-96"><canvas id="grafico-risco" data-titulo="Estudantes em risco por faltas e por acerto, por curso"></canvas></div>
    @else
        <p class="text-sm text-slate-600">Sem estudantes suficientes neste recorte.</p>
    @endif
    @include('reitor._rodape-quadro', ['id' => 'risco-grafico', 'sobre' => $sobre['risco_grafico'], 'leitura' => $leituras['risco_grafico'] ?? null])
</section>

{{-- 3 · Mapa por período do curso --}}
@include('reitor._secao', ['numero' => 3, 'titulo' => 'Onde o risco se concentra: curso × período do curso', 'id' => 'secao-risco-mapa'])
<section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="secao-risco-mapa">
    <ul class="mb-3 flex flex-wrap gap-4 text-xs text-slate-600" aria-label="Legenda">
        <li class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm" style="background:#e8f6f1"></span> &lt; 10% em risco</li>
        <li class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm" style="background:#fbd0d0"></span> 10–24%</li>
        <li class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm" style="background:#e57373"></span> 25–39%</li>
        <li class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm" style="background:#9b1c1c"></span> ≥ 40%</li>
    </ul>
    <div class="overflow-x-auto">
        <table class="w-full text-sm border-separate" style="border-spacing: 2px">
            <caption class="sr-only">Percentual de estudantes em risco por curso e período do curso.</caption>
            <thead class="text-[11px] font-bold uppercase tracking-wide text-slate-600">
                <tr>
                    <th scope="col" class="px-3 py-2 text-left">Curso</th>
                    @foreach ($risco['periodos'] as $o)
                        <th scope="col" class="px-3 py-2 text-center">{{ $o }}º</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($cursos as $c)
                    <tr>
                        <th scope="row" class="px-3 py-2 text-left font-medium text-slate-800">@include('reitor._nome-curso', ['chave' => $c['chave'], 'nome' => $c['nome']])</th>
                        @foreach ($risco['periodos'] as $o)
                            @php
                                $p = $c['periodos'][$o] ?? null;
                                $cor = $corRisco($p['pctRisco'] ?? null);
                            @endphp
                            <td class="px-3 py-2 text-center font-mono font-semibold" style="background: {{ $cor['bg'] }}; color: {{ $cor['fg'] }}"
                                @if ($p) title="{{ $p['risco'] }} de {{ $p['pessoas'] }} estudantes em risco" @endif>
                                @if ($p && $p['pctRisco'] !== null)
                                    @include('reitor._nome-curso', ['chave' => $c['chave'], 'nome' => $fmt($p['pctRisco'], 0).'%', 'destino' => 'alunos', 'extra' => ['periodo_curso' => $o, 'situacao' => 'atencao']])
                                @else
                                    ·
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="mt-2 text-xs text-slate-600">Células vazias: menos de {{ $minimo }} estudantes nesse período do curso.</p>
    @include('reitor._rodape-quadro', ['id' => 'risco-mapa', 'sobre' => $sobre['risco_mapa'], 'leitura' => $leituras['risco_mapa'] ?? null])
</section>

@include('reitor._base')
@if ($risco['temDados'])
<script>
(function () {
    var dados = @json($dados);
    var V = window.ReitorViz;
    var cursos = dados.cursos.filter(function (c) { return c.falta !== null || c.acerto !== null; });
    var series = [];
    if (dados.faltaLigada) series.push({ label: dados.rotuloFalta + ' (%)', data: cursos.map(function (c) { return c.falta; }), backgroundColor: V.cores.ambar, maxBarThickness: 36 });
    if (dados.acertoLigado) series.push({ label: dados.rotuloAcerto + ' (%)', data: cursos.map(function (c) { return c.acerto; }), backgroundColor: V.cores.marinho, maxBarThickness: 36 });

    V.criar('grafico-risco', {
        type: 'bar',
        data: {
            labels: cursos.map(function (c) { return c.nome; }),
            datasets: series,
        },
        options: {
            maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return v + '%'; } }, title: { display: true, text: '% dos estudantes' } }, x: { grid: { display: false } } },
            plugins: {
                legend: V.legenda('top'),
                tooltip: { callbacks: {
                    label: function (item) { return item.dataset.label + ': ' + V.pct(item.raw); },
                    afterBody: function (itens) { return 'Estudantes: ' + cursos[itens[0].dataIndex].pessoas; },
                } },
            },
        },
    });
    V.habilitarDrill(Chart.getChart('grafico-risco'), function (el) {
        return cursos[el.index] ? { chave: cursos[el.index].chave, extra: { destino: 'alunos', situacao: 'atencao' } } : null;
    });
})();
</script>
@endif
@endsection
