@extends('layouts.app')

@section('title', 'Painel da reitoria')

@php
    $fmt = fn ($v, $casas = 1) => $v === null ? '—' : number_format($v, $casas, ',', '.');
    $cursos = array_values($est['cursos']);
    $total = $est['total'];
    $meta = $ctx['meta'];
    $corte = $ctx['corte'];
    $metaTxt = $fmt($meta);
    $corteTxt = $fmt($corte, 0);

    $estiloInsight = fn (string $tom) => match ($tom) {
        'positivo' => ['bg' => 'bg-emerald-50', 'borda' => 'border-emerald-100', 'icone' => 'text-emerald-700'],
        'atencao' => ['bg' => 'bg-amber-50', 'borda' => 'border-amber-100', 'icone' => 'text-amber-700'],
        default => ['bg' => 'bg-slate-50', 'borda' => 'border-slate-100', 'icone' => 'text-slate-600'],
    };

    // Semestre anterior (mesma categoria de avaliação) para as setas de variação dos cartões.
    $semestres = $evolucao['semestres'] ?? [];
    $posicao = collect($semestres)->search(fn ($s) => $s['ehSelecionada']);
    $anterior = $posicao !== false && $posicao > 0 ? $semestres[$posicao - 1] : null;
    $variacao = function (?float $atual, ?float $antes) use ($fmt, $anterior) {
        if ($anterior === null || $atual === null || $antes === null) {
            return null;
        }
        $delta = round($atual - $antes, 1);

        return ['delta' => $delta, 'texto' => ($delta > 0 ? '▲ +' : ($delta < 0 ? '▼ −' : '= ')).$fmt(abs($delta)).' pp vs '.$anterior['periodoLetivo'], 'classe' => $delta > 0 ? 'text-emerald-700' : ($delta < 0 ? 'text-red-700' : 'text-slate-500')];
    };
    $vProf = $variacao($total['proficienciaPct'], $anterior['total']['proficienciaPct'] ?? null);
    $vMedia = $variacao($total['media'], $anterior['total']['media'] ?? null);
    $vPart = $variacao($total['participacao'], $anterior['total']['participacao'] ?? null);

    $mediaEntreCursos = collect($cursos)->pluck('media')->filter(fn ($v) => $v !== null)->avg();
    $naMeta = collect($cursos)->filter(fn ($c) => $c['participacao'] !== null && $c['participacao'] >= $meta)->count();
    $abaixoDaMedia = fn ($c) => $c['difParticipacao'] !== null && $c['difParticipacao'] < 0;

    $corPatamar = function (?float $v): array {
        if ($v === null) {
            return ['bg' => '#f1f5f9', 'fg' => '#475569'];
        }

        return match (true) {
            $v >= 60 => ['bg' => '#00a67e', 'fg' => '#ffffff'],
            $v >= 40 => ['bg' => '#d8f3ec', 'fg' => '#0b4f3f'],
            $v >= 20 => ['bg' => '#fdf0cf', 'fg' => '#7a4b00'],
            default => ['bg' => '#fbe4e6', 'fg' => '#8a1c27'],
        };
    };

    $colunasParticipacao = [
        ['Curso', 'texto', ''], ['Previstos', 'numero', 'text-right'], ['Fizeram', 'numero', 'text-right'], ['Ausentes', 'numero', 'text-right'],
        ['Participação', 'numero', 'text-right'], ['Dif. da média', 'numero', 'text-right'], ['Distância da meta ('.$fmt($meta, 0).'%)', 'numero', 'text-right'],
        ['Alunos a mais', 'numero', 'text-right'], ['Períodos avaliados', 'texto', ''], ['Ativos sem aplicação', 'texto', ''],
    ];

    $porResultados = collect($cursos)->filter(fn ($c) => $c['fontePrevistos'] === 'resultados')->pluck('nome')->all();

    $dados = [
        'meta' => $meta,
        'corte' => $corte,
        'patamares' => $patamares,
        'total' => ['proficiencia' => $total['proficienciaPct'], 'participacao' => $total['participacao']],
        'cursos' => array_map(fn ($c) => [
            'chave' => $c['chave'],
            'nome' => $c['nome'],
            'cor' => $ctx['cores'][$c['chave']] ?? '#64748b',
            'participacao' => $c['participacao'],
            'proficiencia' => $c['proficienciaPct'],
            'previstos' => $c['previstos'],
            'fizeram' => $c['fizeram'],
            'proficientes' => $c['proficientes'],
            'n' => $c['n'],
            'periodos' => array_map(fn ($p) => ['rotulo' => $p['rotulo'], 'n' => $p['n'], 'pcts' => array_values($p['patamares'])], array_values($c['periodos'])),
        ], $cursos),
    ];
@endphp

@section('content')
@include('reitor._cabecalho', [
    'ctx' => $ctx,
    'aba' => 'visao',
    'chips' => [
        ['valor' => $fmt($total['fizeram'], 0), 'rotulo' => 'Estudantes'],
        ['valor' => count($cursos), 'rotulo' => 'Cursos'],
    ],
])

@include('reitor._filtros', ['ctx' => $ctx, 'exportar' => true])

{{-- Pontos de atenção --}}
@if (! empty($alertas))
    <section class="mb-6" aria-labelledby="titulo-atencao">
        <h2 id="titulo-atencao" class="flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-[#1e3a5f] mb-3">
            <i class="ph-bold ph-flag text-base" aria-hidden="true"></i> Pontos de atenção
        </h2>
        <div class="grid sm:grid-cols-2 gap-3 sm:[&>*:last-child:nth-child(odd)]:col-span-2">
            @foreach ($alertas as $alerta)
                @php $e = $estiloInsight($alerta['tom']); @endphp
                <div class="{{ $e['bg'] }} border {{ $e['borda'] }} rounded-xl p-4 flex items-start gap-3">
                    <i class="ph-bold {{ $alerta['icone'] }} {{ $e['icone'] }} text-xl shrink-0 mt-0.5" aria-hidden="true"></i>
                    <div class="min-w-0">
                        <p class="text-sm text-slate-700">{{ $alerta['texto'] }}</p>
                        @if (! empty($alerta['ver']))
                            <a href="{{ route($alerta['ver'][0], array_filter($ctx['filtro'], fn ($v) => $v !== '') + ($ctx['filtrando'] ? ['cursos' => $ctx['cursosSelecionados']] : [])) }}#{{ $alerta['ver'][1] }}"
                               class="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-emerald-800 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">{{ $alerta['ver'][2] }} <i class="ph-bold ph-arrow-right" aria-hidden="true"></i></a>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endif

{{-- Números principais --}}
<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4 mb-2">
    <div class="rounded-2xl border border-emerald-200 border-t-4 border-t-emerald-500 bg-emerald-50 p-5 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wide text-emerald-800">Proficiência institucional · critério ≥ {{ $corteTxt }}%</p>
        <p class="mt-2 font-mono text-4xl font-black text-emerald-700">{{ $fmt($total['proficienciaPct']) }}<span class="text-2xl">%</span></p>
        <p class="mt-1 text-xs text-slate-600">{{ $fmt($total['proficientes'], 0) }} de {{ $fmt($total['n'], 0) }} estudantes &middot; ≥ {{ $patamares[2] }}%: {{ $fmt($total['patamares'][$patamares[2]] ?? null) }}%</p>
        @if ($vProf)<p class="mt-1 text-xs font-semibold {{ $vProf['classe'] }}">{{ $vProf['texto'] }}</p>@endif
    </div>
    <div class="rounded-2xl border border-slate-200 border-t-4 border-t-violet-500 bg-white p-5 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wide text-slate-600">% de acerto médio</p>
        <p class="mt-2 font-mono text-4xl font-black text-[#1e3a5f]">{{ $fmt($total['media']) }}<span class="text-2xl">%</span></p>
        <p class="mt-1 text-xs text-slate-600">mediana {{ $fmt($total['mediana']) }}% &middot; média entre cursos {{ $fmt($mediaEntreCursos) }}%</p>
        @if ($vMedia)<p class="mt-1 text-xs font-semibold {{ $vMedia['classe'] }}">{{ $vMedia['texto'] }}</p>@endif
    </div>
    <div class="rounded-2xl border border-slate-200 border-t-4 border-t-[#1e3a5f] bg-white p-5 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wide text-slate-600">Participação</p>
        <p class="mt-2 font-mono text-4xl font-black text-[#1e3a5f]">{{ $fmt($total['participacao']) }}<span class="text-2xl">%</span></p>
        <p class="mt-1 text-xs text-slate-600">{{ $fmt($total['fizeram'], 0) }} de {{ $fmt($total['previstos'], 0) }} ativos fizeram</p>
        @if ($vPart)<p class="mt-1 text-xs font-semibold {{ $vPart['classe'] }}">{{ $vPart['texto'] }}</p>@endif
    </div>
    <div class="rounded-2xl border border-slate-200 border-t-4 border-t-amber-500 bg-white p-5 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wide text-slate-600">Cursos na meta de participação</p>
        <p class="mt-2 font-mono text-4xl font-black text-[#1e3a5f]">{{ $naMeta }}<span class="text-2xl text-slate-500"> de {{ count($cursos) }}</span></p>
        <p class="mt-1 text-xs text-slate-600">meta de {{ $metaTxt }}% &middot; {{ $total['alunosAMais'] > 0 ? 'faltam '.$fmt($total['alunosAMais'], 0).' estudantes' : 'meta atingida em todos' }}</p>
    </div>
</div>

{{-- 1 · Participação por curso --}}
@include('reitor._secao', ['numero' => 1, 'titulo' => 'Participação por curso', 'id' => 'secao-participacao'])
<section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="secao-participacao">
    <p class="text-xs font-bold uppercase tracking-wide text-emerald-800 mb-3">Previstos × quem fez &middot; períodos avaliados</p>

    @if ($est['previstosPelosResultados'])
        <div class="mb-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
            <i class="ph-bold ph-info mr-1" aria-hidden="true"></i>
            Sem matrículas importadas para o período em: {{ implode(', ', $porResultados) }}. Neles, os "previstos" são os próprios resultados registrados
            (a participação vira presença) — importe as matrículas para medir a participação real.
        </div>
    @endif

    <div class="mb-3 max-w-xs">
        <label class="sr-only" for="filtro-tabela-participacao">Filtrar cursos</label>
        <input id="filtro-tabela-participacao" type="search" placeholder="Filtrar curso..." data-filtro-tabela="tabela-participacao" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
    </div>

    <div class="overflow-x-auto">
        <table id="tabela-participacao" data-ordenavel class="w-full text-sm">
            <caption class="sr-only">Participação por curso: previstos, quem fez, ausentes, distância da meta, períodos avaliados e períodos com alunos ativos sem aplicação.</caption>
            <thead class="text-left text-[11px] font-bold uppercase tracking-wide text-slate-600">
                <tr class="border-b border-slate-200">
                    @foreach ($colunasParticipacao as [$rotulo, $tipo, $alinhamento])
                        <th scope="col" data-ordem="{{ $tipo }}" class="px-3 py-2 {{ $alinhamento }}">
                            <button type="button" class="inline-flex items-center gap-1 font-bold uppercase tracking-wide hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">{{ $rotulo }} <i class="ph ph-arrows-down-up text-xs" aria-hidden="true"></i></button>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($cursos as $c)
                    @php $abaixo = $abaixoDaMedia($c); @endphp
                    <tr data-nome="{{ $c['nome'] }}" class="border-b border-slate-100 {{ $abaixo ? 'bg-red-50' : '' }}">
                        <td data-valor="{{ $c['nome'] }}" class="px-3 py-2.5 font-medium text-slate-800 border-l-4 {{ $abaixo ? 'border-red-700' : 'border-transparent' }}">
                            @include('reitor._nome-curso', ['chave' => $c['chave'], 'nome' => $c['nome']])
                            @if ($usuario->ehReitor())<i class="ph ph-arrow-square-out text-slate-500" aria-hidden="true"></i>@endif
                        </td>
                        <td data-valor="{{ $c['previstos'] }}" class="px-3 py-2.5 text-right font-mono">{{ $fmt($c['previstos'], 0) }}</td>
                        <td data-valor="{{ $c['fizeram'] }}" class="px-3 py-2.5 text-right font-mono">{{ $fmt($c['fizeram'], 0) }}</td>
                        <td data-valor="{{ $c['ausentes'] }}" class="px-3 py-2.5 text-right font-mono">{{ $fmt($c['ausentes'], 0) }}</td>
                        <td data-valor="{{ $c['participacao'] }}" class="px-3 py-2.5 text-right font-mono font-bold">{{ $fmt($c['participacao']) }}%</td>
                        <td data-valor="{{ $c['difParticipacao'] }}" class="px-3 py-2.5 text-right font-mono font-bold {{ ($c['difParticipacao'] ?? 0) < 0 ? 'text-red-700' : 'text-emerald-700' }}">
                            {{ $c['difParticipacao'] === null ? '—' : ($c['difParticipacao'] > 0 ? '+' : ($c['difParticipacao'] < 0 ? '−' : '')).$fmt(abs($c['difParticipacao'])).' pp' }}
                        </td>
                        <td data-valor="{{ $c['distanciaMeta'] }}" class="px-3 py-2.5 text-right font-mono font-bold">
                            @if ($c['distanciaMeta'] !== null && $c['distanciaMeta'] >= 0)
                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-800">atingida</span>
                            @else
                                <span class="text-red-700">{{ $c['distanciaMeta'] === null ? '—' : '−'.$fmt(abs($c['distanciaMeta'])).' pp' }}</span>
                            @endif
                        </td>
                        <td data-valor="{{ $c['alunosAMais'] }}" class="px-3 py-2.5 text-right font-mono font-bold">{{ $c['alunosAMais'] > 0 ? '+'.$c['alunosAMais'] : '—' }}</td>
                        <td data-valor="{{ $c['periodosAvaliados'][0] ?? '' }}" class="px-3 py-2.5 whitespace-nowrap">{{ $c['periodosAvaliadosRotulo'] }}</td>
                        <td data-valor="{{ array_key_first($c['ativosSemAplicacao']) ?? '' }}" class="px-3 py-2.5 whitespace-nowrap">
                            @if ($c['ativosSemAplicacao'] === [])
                                <span class="text-slate-500" aria-label="nenhum">—</span>
                            @else
                                <span class="font-semibold text-amber-700" title="{{ collect($c['ativosSemAplicacao'])->map(fn ($n, $o) => $n.' aluno(s) no '.$o.'º período')->implode('; ') }}">{{ $c['ativosSemAplicacaoRotulo'] }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="bg-slate-50 font-bold text-slate-800">
                    <td class="px-3 py-2.5">Total da visão</td>
                    <td class="px-3 py-2.5 text-right font-mono">{{ $fmt($total['previstos'], 0) }}</td>
                    <td class="px-3 py-2.5 text-right font-mono">{{ $fmt($total['fizeram'], 0) }}</td>
                    <td class="px-3 py-2.5 text-right font-mono">{{ $fmt($total['ausentes'], 0) }}</td>
                    <td class="px-3 py-2.5 text-right font-mono">{{ $fmt($total['participacao']) }}%</td>
                    <td class="px-3 py-2.5"></td>
                    <td class="px-3 py-2.5 text-right font-mono {{ ($total['distanciaMeta'] ?? 0) < 0 ? 'text-red-700' : 'text-emerald-700' }}">{{ $total['distanciaMeta'] === null ? '—' : ($total['distanciaMeta'] >= 0 ? '+' : '−').$fmt(abs($total['distanciaMeta'])).' pp' }}</td>
                    <td class="px-3 py-2.5 text-right font-mono">{{ $total['alunosAMais'] > 0 ? '+'.$total['alunosAMais'] : '—' }}</td>
                    <td class="px-3 py-2.5"></td>
                    <td class="px-3 py-2.5"></td>
                </tr>
            </tfoot>
        </table>
    </div>

    @if ($est['semAplicacao'] !== [])
        <p class="mt-3 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg p-3">
            <i class="ph-bold ph-prohibit mr-1" aria-hidden="true"></i>
            Sem nenhuma aplicação nesta avaliação (alunos ativos no período): {{ collect($est['semAplicacao'])->map(fn ($c) => $c['nome'].' ('.$c['ativos'].')')->implode(', ') }}.
        </p>
    @endif

    {{-- Meta --}}
    <div class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 flex flex-col lg:flex-row gap-5">
        <div class="shrink-0 lg:w-56">
            <p class="font-mono text-5xl font-black text-emerald-700">{{ $metaTxt }}<span class="text-3xl">%</span></p>
            <p class="text-xs font-bold uppercase tracking-wide text-slate-600 mt-1">Meta geral de participação &middot; todos os cursos</p>
        </div>
        <div class="min-w-0 flex-1">
            <p class="text-sm text-slate-700">
                Hoje {{ $fmt($total['participacao']) }}% na visão. {{ $naMeta }} de {{ count($cursos) }} {{ count($cursos) === 1 ? 'curso já está' : 'cursos já estão' }} em {{ $metaTxt }}% ou mais{{ $total['alunosAMais'] > 0 ? '; para todos chegarem à meta faltam' : '.' }}
                @if ($total['alunosAMais'] > 0)<strong>{{ $fmt($total['alunosAMais'], 0) }} alunos</strong>.@endif
            </p>
            <ul class="mt-3 flex flex-wrap gap-2" aria-label="Situação de cada curso frente à meta">
                @foreach ($cursos as $c)
                    <li class="rounded-full border border-slate-300 bg-white px-3 py-1 text-xs text-slate-700">
                        <strong>{{ $c['nome'] }}</strong>
                        @if ($c['distanciaMeta'] !== null && $c['distanciaMeta'] >= 0)
                            {{ $fmt($c['participacao']) }}% <span class="font-bold text-emerald-700" aria-label="meta atingida">✓</span>
                        @else
                            {{ $fmt($c['participacao']) }}% → <strong>{{ $fmt($meta, 0) }}%</strong> <span class="text-slate-500">(+{{ $c['alunosAMais'] }})</span>
                        @endif
                    </li>
                @endforeach
            </ul>
            <button type="button" data-alternar="como-meta" aria-expanded="false" aria-controls="como-meta"
                    class="mt-3 inline-flex items-center gap-2 rounded-full border border-slate-300 bg-white px-4 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary print:hidden">
                <i class="ph ph-calculator text-base" aria-hidden="true"></i> Como a meta foi definida
            </button>
            <div id="como-meta" hidden class="mt-3 rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-700 leading-relaxed">
                {{ $sobre['meta'] }}
                <br><span class="text-slate-600">Para alterar o valor, a administração usa <em>Configurações → Painel da reitoria</em>.</span>
            </div>
        </div>
    </div>

    @include('reitor._rodape-quadro', ['id' => 'participacao', 'sobre' => $sobre['participacao'], 'leitura' => $leituras['participacao'] ?? null])
</section>

{{-- 2 · Proficiência institucional --}}
@include('reitor._secao', ['numero' => 2, 'titulo' => 'Proficiência institucional', 'id' => 'secao-proficiencia'])
<div class="mb-3 flex flex-wrap items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
    <span>Critério interno da instituição (≥ {{ $corteTxt }}% de acerto), sem validação contra a proficiência do ENADE/ENAMED.</span>
    <button type="button" data-alternar="sobre-proficiencia" aria-expanded="false" aria-controls="sobre-proficiencia"
            class="inline-flex items-center gap-2 rounded-full border border-slate-300 bg-white px-3 py-1 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary print:hidden">
        <i class="ph ph-book-open text-base" aria-hidden="true"></i> Saiba mais
    </button>
</div>

<section class="bg-white border border-slate-200 border-t-4 border-t-emerald-500 rounded-2xl shadow-sm p-5" aria-labelledby="secao-proficiencia">
    <h3 class="text-xs font-bold uppercase tracking-wide text-emerald-800 mb-3">Estudantes proficientes pelo critério (≥ {{ $corteTxt }}% de acerto) por curso</h3>
    <div class="relative h-80"><canvas id="grafico-proficiencia" data-titulo="Estudantes proficientes por curso"></canvas></div>
    @include('reitor._rodape-quadro', ['id' => 'proficiencia', 'sobre' => $sobre['proficiencia'], 'leitura' => $leituras['proficiencia'] ?? null])
</section>

<section class="mt-4 bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="titulo-patamares">
    <h3 id="titulo-patamares" class="text-xs font-bold uppercase tracking-wide text-emerald-800">Patamares de proficiência — {{ implode(', ', array_slice($patamares, 0, -1)) }}% e {{ end($patamares) }}%</h3>
    <ul class="mt-2 flex flex-wrap gap-4 text-xs text-slate-600" aria-label="Legenda das cores">
        <li class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm" style="background:#fbe4e6"></span> &lt; 20% dos estudantes</li>
        <li class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm" style="background:#fdf0cf"></span> 20–39%</li>
        <li class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm" style="background:#d8f3ec"></span> 40–59%</li>
        <li class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm" style="background:#00a67e"></span> ≥ 60%</li>
    </ul>
    <div class="mt-3 overflow-x-auto">
        <table class="w-full text-sm border-separate" style="border-spacing: 2px">
            <caption class="sr-only">Percentual de estudantes de cada curso que atingem cada patamar de acerto.</caption>
            <thead class="text-[11px] font-bold uppercase tracking-wide text-slate-600">
                <tr>
                    <th scope="col" class="px-3 py-2 text-left">Curso</th>
                    <th scope="col" class="px-3 py-2 text-right">N</th>
                    @foreach ($patamares as $p)
                        <th scope="col" class="px-3 py-2 text-center">≥ {{ $p }}%</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($cursos as $c)
                    <tr>
                        <th scope="row" class="px-3 py-2 text-left font-medium text-slate-800">@include('reitor._nome-curso', ['chave' => $c['chave'], 'nome' => $c['nome']])</th>
                        <td class="px-3 py-2 text-right font-mono text-slate-600">{{ $fmt($c['n'], 0) }}</td>
                        @foreach ($patamares as $p)
                            @php $cor = $corPatamar($c['patamares'][$p] ?? null); @endphp
                            <td class="px-3 py-2 text-center font-mono" style="background: {{ $cor['bg'] }}; color: {{ $cor['fg'] }}">{{ $fmt($c['patamares'][$p] ?? null) }}%</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="font-bold">
                    <th scope="row" class="px-3 py-2 text-left text-slate-800">Total da visão</th>
                    <td class="px-3 py-2 text-right font-mono text-slate-700">{{ $fmt($total['n'], 0) }}</td>
                    @foreach ($patamares as $p)
                        @php $cor = $corPatamar($total['patamares'][$p] ?? null); @endphp
                        <td class="px-3 py-2 text-center font-mono" style="background: {{ $cor['bg'] }}; color: {{ $cor['fg'] }}">{{ $fmt($total['patamares'][$p] ?? null) }}%</td>
                    @endforeach
                </tr>
            </tfoot>
        </table>
    </div>

    @php
        $botaoPeriodo = '<button type="button" data-alternar="patamares-por-periodo" aria-expanded="false" aria-controls="patamares-por-periodo" class="inline-flex items-center gap-2 rounded-full border border-slate-300 bg-white px-4 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"><i class="ph ph-table text-base" aria-hidden="true"></i> Ver por período de um curso</button>';
    @endphp
    @include('reitor._rodape-quadro', ['id' => 'patamares', 'sobre' => $sobre['patamares'], 'leitura' => $leituras['patamares'] ?? null, 'botoes' => $botaoPeriodo])

    <div id="patamares-por-periodo" hidden class="mt-3 rounded-lg border border-slate-200 bg-white p-4">
        <label for="seletor-patamares-curso" class="text-xs font-bold uppercase tracking-wide text-slate-600">Curso</label>
        <select id="seletor-patamares-curso" class="ml-2 rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white">
            @foreach ($cursos as $i => $c)
                <option value="{{ $i }}">{{ $c['nome'] }}</option>
            @endforeach
        </select>
        <div class="mt-3 overflow-x-auto"><table id="tabela-patamares-periodo" class="w-full text-sm border-separate" style="border-spacing: 2px"></table></div>
    </div>
</section>

{{-- 3 · Participação × proficiência --}}
@include('reitor._secao', ['numero' => 3, 'titulo' => 'Onde agir primeiro: participação × proficiência', 'id' => 'secao-quadrante'])
<section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="secao-quadrante">
    <p class="text-sm text-slate-600 mb-3">Cada bolha é um curso; o tamanho é o número de previstos. A área rosada é o quadrante de atenção: participação abaixo da meta e proficiência abaixo da geral do conjunto.</p>
    <div class="relative h-96"><canvas id="grafico-quadrante" data-titulo="Participação e proficiência por curso"></canvas></div>
    @include('reitor._rodape-quadro', ['id' => 'quadrante', 'sobre' => $sobre['quadrante'], 'leitura' => $leituras['quadrante'] ?? null])
</section>

@include('reitor._base')
<script>
(function () {
    var dados = @json($dados);
    var V = window.ReitorViz;
    var cursos = dados.cursos;

    // 2 · Proficiência por curso: barras (verde acima do conjunto, azul-marinho abaixo) + linha do total da visão.
    var totalProf = dados.total.proficiencia;
    V.criar('grafico-proficiencia', {
        type: 'bar',
        data: {
            labels: cursos.map(function (c) { return c.nome; }),
            datasets: [{
                label: '% proficientes',
                data: cursos.map(function (c) { return c.proficiencia; }),
                backgroundColor: cursos.map(function (c) { return c.proficiencia !== null && totalProf !== null && c.proficiencia >= totalProf ? V.cores.verde : V.cores.marinho; }),
                maxBarThickness: 64,
            }, {
                // entrada só para a legenda (a "proficiência geral do conjunto" é a linha tracejada desenhada pelo plugin)
                label: 'Proficiência geral do conjunto (' + V.pct(totalProf) + ')',
                data: [],
                type: 'line',
                borderColor: V.cores.vermelho,
                borderDash: [6, 4],
                pointRadius: 0,
            }],
        },
        options: {
            maintainAspectRatio: false,
            scales: { y: V.eixoPct(), x: { grid: { display: false } } },
            plugins: {
                legend: V.legenda('top'),
                linhaReferencia: { linhas: [{ valor: totalProf, cor: V.cores.vermelho }] },
                rotulosValores: { dataset: 0, formato: function (v) { return V.pct(v); } },
                tooltip: { callbacks: {
                    label: function (item) {
                        if (item.datasetIndex !== 0) return null;
                        var c = cursos[item.dataIndex];
                        return V.pct(c.proficiencia) + ' (' + c.proficientes + ' de ' + c.n + ' estudantes)';
                    },
                } },
            },
        },
    });

    // 3 · Quadrante participação × proficiência.
    var minX = Math.min.apply(null, cursos.map(function (c) { return c.participacao === null ? 100 : c.participacao; }).concat([dados.meta]));
    var limiteX = Math.max(0, Math.floor((minX - 5) / 5) * 5);
    var maiorPrevistos = Math.max.apply(null, cursos.map(function (c) { return c.previstos; }).concat([1]));
    var quadrante = {
        id: 'quadranteAtencao',
        beforeDatasetsDraw: function (chart) {
            var x = chart.scales.x, y = chart.scales.y, area = chart.chartArea;
            if (dados.total.proficiencia === null) return;
            var xMeta = x.getPixelForValue(dados.meta), yTotal = y.getPixelForValue(dados.total.proficiencia);
            chart.ctx.save();
            chart.ctx.fillStyle = 'rgba(193, 18, 31, 0.07)';
            chart.ctx.fillRect(area.left, yTotal, Math.max(0, xMeta - area.left), Math.max(0, area.bottom - yTotal));
            chart.ctx.restore();
        },
        afterDatasetsDraw: function (chart) {
            var meta = chart.getDatasetMeta(0), ctx = chart.ctx;
            ctx.save();
            ctx.font = '600 11px ui-sans-serif, system-ui, sans-serif';
            ctx.fillStyle = V.cores.tinta;
            ctx.textAlign = 'left';
            ctx.textBaseline = 'middle';
            meta.data.forEach(function (ponto, i) {
                ctx.fillText(cursos[i].nome, ponto.x + ponto.options.radius + 4, ponto.y);
            });
            ctx.restore();
        },
    };
    V.criar('grafico-quadrante', {
        type: 'bubble',
        plugins: [quadrante],
        data: {
            datasets: [{
                label: 'Cursos',
                data: cursos.filter(function (c) { return c.participacao !== null && c.proficiencia !== null; }).map(function (c) {
                    return { x: c.participacao, y: c.proficiencia, r: 6 + 16 * Math.sqrt(c.previstos / maiorPrevistos), curso: c };
                }),
                backgroundColor: cursos.map(function (c) { return c.cor + 'cc'; }),
                borderColor: '#ffffff',
                borderWidth: 2,
            }],
        },
        options: {
            maintainAspectRatio: false,
            layout: { padding: { right: 90 } },
            scales: {
                x: { min: limiteX, max: 100, title: { display: true, text: 'Participação (% dos previstos)' }, ticks: { callback: function (v) { return v + '%'; } } },
                y: { min: 0, max: 100, title: { display: true, text: 'Estudantes proficientes (%)' }, ticks: { callback: function (v) { return v + '%'; } } },
            },
            plugins: {
                legend: { display: false },
                linhaReferencia: { linhas: [{ valor: dados.total.proficiencia, cor: V.cores.vermelho, rotulo: 'Proficiência geral do conjunto' }] },
                linhaReferenciaX: { linhas: [{ valor: dados.meta, cor: V.cores.verdeEscuro, rotulo: 'Meta' }] },
                tooltip: { callbacks: {
                    title: function (itens) { return itens[0].raw.curso.nome; },
                    label: function (item) {
                        var c = item.raw.curso;
                        return ['Participação: ' + V.pct(c.participacao) + ' (' + c.fizeram + ' de ' + c.previstos + ')', 'Proficientes: ' + V.pct(c.proficiencia)];
                    },
                } },
            },
        },
    });

    // Drill-down (reitor): clicar numa barra ou bolha abre a análise do curso.
    V.habilitarDrill(Chart.getChart('grafico-proficiencia'), function (el) { return el.datasetIndex === 0 && cursos[el.index] ? { chave: cursos[el.index].chave } : null; });
    V.habilitarDrill(Chart.getChart('grafico-quadrante'), function (el, grafico) {
        var ponto = grafico.data.datasets[0].data[el.index];
        return ponto && ponto.curso ? { chave: ponto.curso.chave } : null;
    });

    // Patamares por período do curso (tabela montada ao escolher o curso).
    var seletor = document.getElementById('seletor-patamares-curso');
    var tabela = document.getElementById('tabela-patamares-periodo');
    function cor(v) {
        if (v === null) return ['#f1f5f9', '#475569'];
        if (v >= 60) return ['#00a67e', '#ffffff'];
        if (v >= 40) return ['#d8f3ec', '#0b4f3f'];
        if (v >= 20) return ['#fdf0cf', '#7a4b00'];
        return ['#fbe4e6', '#8a1c27'];
    }
    function montarPeriodos() {
        var curso = cursos[parseInt(seletor.value, 10)];
        var html = '<thead class="text-[11px] font-bold uppercase tracking-wide text-slate-600"><tr><th scope="col" class="px-3 py-2 text-left">Período do curso</th><th scope="col" class="px-3 py-2 text-right">N</th>'
            + dados.patamares.map(function (p) { return '<th scope="col" class="px-3 py-2 text-center">≥ ' + p + '%</th>'; }).join('') + '</tr></thead><tbody>';
        if (!curso.periodos.length) {
            html += '<tr><td class="px-3 py-3 text-slate-600" colspan="' + (dados.patamares.length + 2) + '">Este curso não tem períodos do curso identificados nos resultados.</td></tr>';
        }
        curso.periodos.forEach(function (p) {
            html += '<tr><th scope="row" class="px-3 py-2 text-left font-medium text-slate-800">' + p.rotulo + '</th><td class="px-3 py-2 text-right font-mono text-slate-600">' + p.n + '</td>'
                + p.pcts.map(function (v) { var c = cor(v); return '<td class="px-3 py-2 text-center font-mono" style="background:' + c[0] + ';color:' + c[1] + '">' + V.pct(v) + '</td>'; }).join('') + '</tr>';
        });
        tabela.innerHTML = html + '</tbody>';
    }
    if (seletor && tabela) {
        seletor.addEventListener('change', montarPeriodos);
        montarPeriodos();
    }
})();
</script>
@endsection
