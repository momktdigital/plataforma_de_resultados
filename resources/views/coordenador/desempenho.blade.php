@extends('layouts.app')

@section('title', 'Desempenho do curso')

@php
    use App\Support\CorDesempenho;

    $semDados = ! empty($painel['semCurso']) || ! empty($painel['semResultados']);
    $nomeCursos = implode(' · ', $painel['cursosEmFoco']);
    $g = $painel['geral'] ?? null;
    $fmt = fn ($v, $casas = 1) => $v === null ? '—' : number_format($v, $casas, ',', '.');
    $estiloInsight = fn (string $tom) => match ($tom) {
        'positivo' => ['bg' => 'bg-emerald-50', 'borda' => 'border-emerald-100', 'icone' => 'text-emerald-600'],
        'atencao' => ['bg' => 'bg-amber-50', 'borda' => 'border-amber-100', 'icone' => 'text-amber-600'],
        default => ['bg' => 'bg-slate-50', 'borda' => 'border-slate-100', 'icone' => 'text-slate-600'],
    };
    // Dados dos gráficos por categoria (índice na tela → evolução, área, Bloom e tema), lidos por painel-desempenho.js.
    $dadosGraficos = [];
    $filtros = $painel['filtros'] ?? ['categoria' => '', 'periodoCurso' => null];
@endphp

@section('content')
@include('coordenador._cabecalho', [
    'ctx' => $painel,
    'aba' => 'desempenho',
    'chips' => $semDados || ($g['avaliacoes'] ?? 0) === 0 ? [] : [
        ['valor' => $g['avaliacoes'], 'rotulo' => 'Avaliações'],
        ['valor' => $fmt($g['presenca']).'%', 'rotulo' => 'Presença'],
    ],
])

@if (! empty($painel['semCurso']))
    <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-xl p-6 text-sm">
        Sua conta ainda não está vinculada a nenhum curso. Peça a um administrador para vincular em <strong>Usuários &rarr; Coordenadores</strong>.
    </div>
@elseif (! empty($painel['semResultados']))
    <div class="bg-slate-50 border border-slate-200 text-slate-600 rounded-xl p-6 text-sm">
        Ainda não há resultados importados de alunos {{ count($painel['cursosEmFoco']) > 1 ? 'destes cursos' : 'deste curso' }}.
    </div>
@else
    {{-- Filtros: curso (se mais de um), período letivo, categoria e período do curso --}}
    <form method="GET" action="{{ route('coordenador.desempenho') }}" class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 mb-6 flex flex-wrap items-end gap-3">
        @include('coordenador._seletores', ['ctx' => $painel, 'autoEnviar' => true])
        <div>
            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1" for="filtro-categoria">Categoria</label>
            <select id="filtro-categoria" name="categoria" onchange="this.form.submit()"
                    class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white min-w-[180px] max-w-full">
                <option value="" {{ $filtros['categoria'] === '' ? 'selected' : '' }}>Todas as categorias</option>
                @foreach ($painel['categoriasDisponiveis'] as $c)
                    <option value="{{ $c['id'] }}" {{ $filtros['categoria'] !== '' && (int) $filtros['categoria'] === $c['id'] ? 'selected' : '' }}>{{ $c['nome'] }}</option>
                @endforeach
            </select>
        </div>
        @if (! empty($painel['periodosCursoDisponiveis']))
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1" for="filtro-periodo-curso">Período do curso</label>
                <select id="filtro-periodo-curso" name="periodo_curso" onchange="this.form.submit()"
                        class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white min-w-[150px]">
                    <option value="" {{ $filtros['periodoCurso'] === null ? 'selected' : '' }}>Todos</option>
                    @foreach ($painel['periodosCursoDisponiveis'] as $p)
                        <option value="{{ $p }}" {{ $filtros['periodoCurso'] === $p ? 'selected' : '' }}>{{ \App\Support\PeriodoCurso::rotulo($p) }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <noscript><button type="submit" class="bg-slate-800 text-white font-semibold rounded-lg px-4 py-2 text-sm">Filtrar</button></noscript>
    </form>

    @if ($g['avaliacoes'] === 0)
        <div class="bg-slate-50 border border-slate-200 text-slate-600 rounded-xl p-6 text-sm mb-6">
            Nenhuma avaliação deste curso no recorte selecionado.
        </div>
    @else
        {{-- Visão geral: só o que não depende da prova (presença e contagem) --}}
        <div class="grid gap-4 sm:grid-cols-3 mb-6">
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Presença</p>
                <p class="text-3xl font-bold mt-2 tracking-tight">{{ $fmt($g['presenca']) }}<span class="text-lg font-medium text-slate-500">%</span></p>
                <p class="text-xs text-slate-500 mt-1">{{ $g['presentes'] }} de {{ $g['inscritos'] }} participações &middot; {{ $g['ausentes'] }} ausente(s)</p>
            </div>
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Avaliações no período</p>
                <p class="text-3xl font-bold mt-2 tracking-tight">{{ $g['avaliacoes'] }}</p>
                <p class="text-xs text-slate-500 mt-1">em {{ count($painel['categorias']) }} categoria(s)</p>
            </div>
            <div class="bg-slate-50 border border-slate-200 rounded-xl p-5 text-sm text-slate-600">
                <p class="font-semibold text-slate-700 mb-1">Como ler este painel</p>
                Os resultados são separados por <strong>categoria de avaliação</strong>: provas de categorias diferentes não são comparáveis.
                Abra a categoria que deseja analisar. O <strong>desempenho esperado</strong> de cada aluno depende do período do curso em que ele está
                (quando a prova não traz essa informação, usamos 60%). Alunos ausentes (prova inteira em branco) não entram nas médias.
            </div>
        </div>

        @foreach ($painel['insights'] as $insight)
            @php $e = $estiloInsight($insight['tom']); @endphp
            <div class="{{ $e['bg'] }} border {{ $e['borda'] }} rounded-xl p-4 flex items-start gap-3 mb-6">
                <i class="ph-bold {{ $insight['icone'] }} {{ $e['icone'] }} text-xl shrink-0 mt-0.5" aria-hidden="true"></i>
                <p class="text-sm text-slate-700">{{ $insight['texto'] }}</p>
            </div>
        @endforeach

        {{-- Uma categoria por vez: cada uma é um dropdown --}}
        <div class="space-y-4 mb-10">
        @foreach ($painel['categorias'] as $i => $cat)
            @php
                $t = $cat['totais'];
                $det = $cat['detalhe'];
                $comMeta = $det['comMeta'];
                $abrir = $filtros['categoria'] !== '' || count($painel['categorias']) === 1;

                // --- dados dos gráficos desta categoria (ver painel-desempenho.js) ---
                $evolucaoJs = null;
                if (! empty($det['evolucao'])) {
                    $valor = fn (array $m) => $comMeta ? $m['pct'] : $m['media'];
                    $detalhe = fn (array $m) => $comMeta && $m['presentes'] > 0 ? $m['dentro'].' de '.$m['presentes'].' alunos' : null;
                    $evolucaoJs = [
                        'modo' => $comMeta ? 'esperado' : 'media',
                        'periodos' => $det['periodosEvolucao'],
                        'pontos' => array_map(fn ($p) => [
                            'nome' => $p['nome'],
                            'periodoLetivo' => $p['periodoLetivo'],
                            'noPeriodo' => $p['noPeriodo'],
                            'geral' => ['valor' => $valor($p['geral']), 'detalhe' => $detalhe($p['geral'])],
                            'porPeriodo' => (object) array_map(fn ($m) => ['valor' => $valor($m), 'detalhe' => $detalhe($m)], $p['porPeriodo']),
                        ], $det['evolucao']),
                    ];
                }
                $camposJs = collect($det['campos'])->map(fn ($c) => [
                    'geral' => $c['geral'],
                    'porPeriodo' => (object) $c['porPeriodo'],
                    'periodos' => $c['periodos'],
                ])->all();
                $dadosGraficos[$i] = array_filter(['evolucao' => $evolucaoJs] + $camposJs, fn ($d) => $d !== null);
            @endphp
            <details class="categoria-painel group bg-white border border-slate-200 rounded-xl shadow-sm" data-cat="{{ $i }}" {{ $abrir ? 'open' : '' }}>
                <summary class="cursor-pointer list-none [&::-webkit-details-marker]:hidden px-5 py-4 flex flex-wrap items-center justify-between gap-x-6 gap-y-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded-xl">
                    <span class="text-lg font-bold flex items-center gap-2 min-w-0">
                        <i class="ph-bold ph-folder-open text-primary shrink-0" aria-hidden="true"></i>
                        <span class="truncate">{{ $cat['nome'] }}</span>
                    </span>
                    <span class="flex items-center gap-4 text-sm text-slate-600">
                        <span>Média <strong class="{{ CorDesempenho::classeTextoLegivel($t['media']) }}">{{ $fmt($t['media']) }}{{ $t['media'] !== null ? '%' : '' }}</strong></span>
                        <span class="hidden sm:inline">{{ $t['avaliacoes'] }} {{ $t['avaliacoes'] === 1 ? 'avaliação' : 'avaliações' }}</span>
                        <i class="ph-bold ph-caret-down text-slate-500 transition-transform group-open:rotate-180" aria-hidden="true"></i>
                    </span>
                </summary>

                <div class="px-5 pb-5 space-y-4 border-t border-slate-100 pt-4">
                    @if (! empty($cat['insights']))
                        <div class="grid sm:grid-cols-2 gap-3 sm:[&>*:last-child:nth-child(odd)]:col-span-2">
                            @foreach ($cat['insights'] as $insight)
                                @php $e = $estiloInsight($insight['tom']); @endphp
                                <div class="{{ $e['bg'] }} border {{ $e['borda'] }} rounded-xl p-4 flex items-start gap-3">
                                    <i class="ph-bold {{ $insight['icone'] }} {{ $e['icone'] }} text-xl shrink-0 mt-0.5" aria-hidden="true"></i>
                                    <p class="text-sm text-slate-700">{{ $insight['texto'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Média da categoria</p>
                            <p class="text-3xl font-bold mt-2 tracking-tight {{ CorDesempenho::classeTexto($t['media']) }}">{{ $fmt($t['media']) }}<span class="text-lg font-medium text-slate-500">%</span></p>
                            <p class="text-xs text-slate-500 mt-1">só alunos presentes</p>
                        </div>
                        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
                            @if ($comMeta)
                                @php $ae = $det['abaixoEsperado']; @endphp
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Alunos abaixo do desempenho esperado</p>
                                <p class="text-3xl font-bold mt-2 tracking-tight">{{ $fmt($ae['pct']) }}<span class="text-lg font-medium text-slate-500">%</span></p>
                                <p class="text-xs text-slate-500 mt-1">{{ $ae['abaixo'] }} de {{ $ae['total'] }} resultados &middot; esperado para o período de cada aluno</p>
                            @else
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Abaixo de 60%</p>
                                <p class="text-3xl font-bold mt-2 tracking-tight">{{ $fmt($t['abaixoPct']) }}<span class="text-lg font-medium text-slate-500">%</span></p>
                                <p class="text-xs text-slate-500 mt-1">{{ $t['abaixo'] }} de {{ $t['comNota'] }} resultados</p>
                            @endif
                        </div>
                        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Presença</p>
                            <p class="text-3xl font-bold mt-2 tracking-tight">{{ $fmt($t['presenca']) }}<span class="text-lg font-medium text-slate-500">%</span></p>
                            <p class="text-xs text-slate-500 mt-1">{{ $t['presentes'] }} de {{ $t['inscritos'] }} &middot; {{ $t['ausentes'] }} ausente(s)</p>
                        </div>
                        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Avaliações</p>
                            <p class="text-3xl font-bold mt-2 tracking-tight">{{ $t['avaliacoes'] }}</p>
                            <p class="text-xs text-slate-500 mt-1">neste período</p>
                        </div>
                    </div>

                    {{-- Gráficos, cada um com as abas Geral e Por período --}}
                    <div class="grid gap-4 lg:grid-cols-2">
                        @if ($evolucaoJs !== null)
                            <div class="lg:col-span-2">
                                @include('coordenador._grafico_abas', [
                                    'cat' => $i, 'tipo' => 'evolucao',
                                    'titulo' => $comMeta ? 'Evolução dos alunos que atingiram o desempenho esperado' : 'Evolução da média nesta categoria',
                                    'subtitulo' => $comMeta
                                        ? 'Percentual de alunos presentes que alcançaram o mínimo esperado para o período do curso em que estão, a cada avaliação da categoria (inclusive de outros períodos letivos).'
                                        : 'Todas as avaliações da categoria, inclusive de outros períodos letivos; as do período selecionado aparecem maiores. A linha tracejada marca os 60%.',
                                    'temPeriodo' => ! empty($evolucaoJs['periodos']),
                                ])
                            </div>
                        @endif
                        @foreach ([
                            'area' => ['Desempenho por área', '% de acerto por área, da mais fraca para a mais forte.'],
                            'bloom' => ['Desempenho por nível de Bloom', '% de acerto por tipo de raciocínio exigido (lembrar, aplicar, analisar...).'],
                            'tema' => ['Desempenho por tema', '% de acerto por tema, dos 15 mais fracos.'],
                        ] as $tipo => [$tituloGrafico, $subtituloGrafico])
                            @if (! empty($det['campos'][$tipo]['geral']))
                                @include('coordenador._grafico_abas', [
                                    'cat' => $i, 'tipo' => $tipo, 'titulo' => $tituloGrafico, 'subtitulo' => $subtituloGrafico,
                                    'temPeriodo' => ! empty($det['campos'][$tipo]['periodos']),
                                ])
                            @endif
                        @endforeach
                    </div>

                    {{-- Cartões de barras da categoria (período do curso e comparativo entre cursos). Quantos aparecerem,
                         ocupam a linha inteira: um cartão sozinho (ou o último de uma quantidade ímpar) vira largura total. --}}
                    @php
                        $barras = [];
                        if (! empty($cat['porPeriodoDoCurso'])) {
                            $barras[] = [
                                'titulo' => 'Desempenho por período do curso',
                                'subtitulo' => 'Média de acerto de cada período (1º, 2º...), só presentes.',
                                'itens' => array_map(fn ($p) => ['rotulo' => $p['rotulo'], 'extra' => '('.$p['presentes'].')', 'valor' => $p['media']], $cat['porPeriodoDoCurso']),
                                'rodape' => $cat['periodosOmitidos'] > 0 ? $cat['periodosOmitidos'].' participação(ões) sem período do curso válido na planilha não aparecem aqui.' : null,
                            ];
                        }
                        if (! empty($cat['porCurso'])) {
                            $barras[] = [
                                'titulo' => 'Comparativo entre os seus cursos',
                                'subtitulo' => 'Média de cada curso nesta categoria, só presentes.',
                                'itens' => array_map(fn ($c) => ['rotulo' => $c['curso'], 'extra' => '('.$c['presentes'].' presentes · '.$fmt($c['abaixoPct']).'% abaixo de 60%)', 'valor' => $c['media']], $cat['porCurso']),
                                'rodape' => null,
                            ];
                        }
                        $qtdBarras = count($barras);
                    @endphp
                    @if ($qtdBarras > 0)
                        <div class="grid gap-4 {!! $qtdBarras > 1 ? 'lg:grid-cols-2 lg:[&>*:last-child:nth-child(odd)]:col-span-2' : '' !!}">
                            @foreach ($barras as $b)
                                @include('coordenador._barras', [
                                    'titulo' => $b['titulo'],
                                    'subtitulo' => $b['subtitulo'],
                                    'itens' => $b['itens'],
                                    'rodape' => $b['rodape'],
                                    'larga' => $qtdBarras === 1 || ($loop->last && $qtdBarras % 2 === 1),
                                ])
                            @endforeach
                        </div>
                    @endif

                    {{-- Avaliações da categoria: no fim, abaixo dos gráficos --}}
                    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-x-auto">
                        <table class="w-full text-sm">
                            <caption class="sr-only">Avaliações da categoria {{ $cat['nome'] }}</caption>
                            <thead class="bg-slate-50 text-slate-500 text-left">
                                <tr>
                                    <th scope="col" class="px-4 py-3">Avaliação</th>
                                    <th scope="col" class="px-4 py-3">Data</th>
                                    <th scope="col" class="px-4 py-3">Presença</th>
                                    <th scope="col" class="px-4 py-3">Média</th>
                                    <th scope="col" class="px-4 py-3">vs. anterior da categoria</th>
                                    <th scope="col" class="px-4 py-3">{{ $comMeta ? 'Abaixo do esperado' : 'Abaixo de 60%' }}</th>
                                    <th scope="col" class="px-4 py-3"><span class="sr-only">Ações</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($cat['avaliacoes'] as $a)
                                    <tr>
                                        <td class="px-4 py-3 font-medium">{{ $a['nome'] }} <span class="font-mono text-xs text-slate-500">#{{ $a['codigo'] }}</span></td>
                                        <td class="px-4 py-3 text-slate-500">{{ $a['data'] ? \Illuminate\Support\Carbon::parse($a['data'])->format('d/m/Y') : '—' }}</td>
                                        <td class="px-4 py-3 text-slate-600">{{ $fmt($a['presenca']) }}% <span class="text-xs text-slate-500">({{ $a['presentes'] }}/{{ $a['inscritos'] }})</span></td>
                                        <td class="px-4 py-3">
                                            @if ($a['media'] === null)
                                                <span class="text-slate-500">—</span>
                                            @else
                                                <div class="flex items-center gap-2 min-w-[140px]">
                                                    <div class="h-2 w-24 rounded-full bg-slate-100 overflow-hidden" aria-hidden="true">
                                                        <div class="h-full rounded-full {{ CorDesempenho::classeBg($a['media']) }}" style="width: {{ max(3, min(100, $a['media'])) }}%"></div>
                                                    </div>
                                                    <span class="font-bold {{ CorDesempenho::classeTexto($a['media']) }}">{{ $fmt($a['media']) }}%</span>
                                                </div>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3">
                                            @if ($a['delta'] === null)
                                                <span class="text-slate-500" title="Nenhuma avaliação anterior desta categoria com resultado">—</span>
                                            @else
                                                <span class="font-semibold {{ $a['delta'] >= 0 ? 'text-emerald-700' : 'text-amber-700' }}">{{ $a['delta'] > 0 ? '+' : '' }}{{ $fmt($a['delta']) }} pp</span>
                                                <span class="block text-xs text-slate-500">{{ $a['anterior']['nome'] }}@if ($a['anterior']['periodoLetivo'] !== '') ({{ $a['anterior']['periodoLetivo'] }})@endif</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-slate-600 whitespace-nowrap">
                                            @if ($comMeta && $a['esperado'] !== null)
                                                {{ $a['esperado']['abaixo'] }} <span class="text-xs text-slate-500">({{ $fmt($a['esperado']['abaixoPct'], 0) }}%)</span>
                                                @unless ($a['esperado']['comMeta'])
                                                    <span class="block text-xs text-slate-500">referência de 60% (sem meta por período)</span>
                                                @endunless
                                            @else
                                                {{ $fmt($a['abaixoPct']) }}%
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            @if (in_array($a['codigo'], $codigosAcessiveis, true))
                                                <a href="{{ route('avaliacoes.bi', $a['codigo']) }}" class="text-emerald-700 font-semibold hover:underline">Dashboard</a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </details>
        @endforeach
        </div>

        <script type="application/json" id="painel-dados">{!! json_encode($dadosGraficos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_PARTIAL_OUTPUT_ON_ERROR) !!}</script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1"></script>
        <script src="{{ asset('assets/js/painel-desempenho.js') }}?v={{ @filemtime(public_path('assets/js/painel-desempenho.js')) }}"></script>
    @endif
@endif
@endsection
