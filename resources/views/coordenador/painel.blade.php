@extends('layouts.app')

@section('title', 'Painel do curso')

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
    $graficos = [];
@endphp

@section('content')
<div class="relative overflow-hidden rounded-2xl shadow-lg mb-6" style="background: linear-gradient(135deg, #00b48d 0%, #009e7d 55%, #007a61 100%);">
    <div class="absolute inset-0 opacity-10 pointer-events-none" style="background-image: radial-gradient(circle at 85% 15%, white 0, transparent 45%), radial-gradient(circle at 10% 90%, white 0, transparent 40%);"></div>
    <div class="relative p-6 sm:p-8">
        <p class="text-white/80 text-sm font-medium uppercase tracking-wide">Painel do coordenador</p>
        @if (count($painel['meusCursos']) > 1)
            {{-- Mais de um curso: o título vira o seletor de curso. Mantém o período letivo escolhido. --}}
            <form method="GET" action="{{ route('coordenador.painel') }}" class="mt-1">
                @if (isset($painel['periodoSelecionado']))
                    <input type="hidden" name="periodo_letivo" value="{{ $painel['periodoSelecionado'] }}">
                @endif
                <label for="seletor-curso" class="sr-only">Curso exibido no painel</label>
                <div class="relative inline-block max-w-full">
                    <select id="seletor-curso" name="curso" onchange="this.form.submit()"
                            class="appearance-none max-w-full cursor-pointer rounded-xl bg-white/15 hover:bg-white/25 border border-white/30 text-white text-2xl sm:text-3xl font-black pl-4 pr-12 py-1.5 focus:outline-none focus:ring-2 focus:ring-white/70">
                        <option value="" class="text-slate-800 text-base font-normal" {{ $painel['cursoSelecionado'] === '' ? 'selected' : '' }}>Todos os meus cursos</option>
                        @foreach ($painel['meusCursos'] as $c)
                            <option value="{{ $c }}" class="text-slate-800 text-base font-normal" {{ $painel['cursoSelecionado'] === $c ? 'selected' : '' }}>{{ $c }}</option>
                        @endforeach
                    </select>
                    <i class="ph-bold ph-caret-down absolute right-4 top-1/2 -translate-y-1/2 text-white text-xl pointer-events-none"></i>
                </div>
                <noscript><button type="submit" class="ml-2 bg-white/20 text-white font-semibold rounded-lg px-3 py-1 text-sm">Aplicar</button></noscript>
            </form>
            @if ($painel['cursoSelecionado'] === '')
                <p class="text-white/85 text-sm mt-2">{{ $nomeCursos }}</p>
            @endif
        @else
            <h1 class="text-2xl sm:text-3xl font-black text-white">{{ $nomeCursos ?: 'Nenhum curso vinculado' }}</h1>
        @endif
        <p class="text-white/85 text-sm mt-1">{{ $usuario->username }}@if (! $semDados && $painel['periodoSelecionado'] !== '') &middot; período letivo {{ $painel['periodoSelecionado'] }} @elseif (! $semDados) &middot; todos os períodos @endif</p>
    </div>
</div>

@if (! empty($painel['semCurso']))
    <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-xl p-6 text-sm">
        Sua conta ainda não está vinculada a nenhum curso. Peça a um administrador para vincular em <strong>Usuários &rarr; Coordenadores</strong>.
    </div>
@elseif (! empty($painel['semResultados']))
    <div class="bg-slate-50 border border-slate-200 text-slate-600 rounded-xl p-6 text-sm">
        Ainda não há resultados importados de alunos {{ count($painel['cursosEmFoco']) > 1 ? 'destes cursos' : 'deste curso' }}.
    </div>
@else
    {{-- Filtros --}}
    <form method="GET" action="{{ route('coordenador.painel') }}" class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 mb-6 flex flex-wrap items-end gap-3">
        {{-- O curso se escolhe no título; aqui só é preservado ao trocar o período. --}}
        @if ($painel['cursoSelecionado'] !== '')
            <input type="hidden" name="curso" value="{{ $painel['cursoSelecionado'] }}">
        @endif
        <div>
            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1" for="periodo-letivo">Período letivo</label>
            <select id="periodo-letivo" name="periodo_letivo" onchange="this.form.submit()"
                    class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white min-w-[140px]">
                <option value="" {{ $painel['periodoSelecionado'] === '' ? 'selected' : '' }}>Todos</option>
                @foreach ($painel['periodosDisponiveis'] as $p)
                    <option value="{{ $p }}" {{ $painel['periodoSelecionado'] === $p ? 'selected' : '' }}>{{ $p }}</option>
                @endforeach
            </select>
        </div>
        <noscript><button type="submit" class="bg-slate-800 text-white font-semibold rounded-lg px-4 py-2 text-sm">Filtrar</button></noscript>
    </form>

    @if ($g['avaliacoes'] === 0)
        <div class="bg-slate-50 border border-slate-200 text-slate-600 rounded-xl p-6 text-sm mb-6">
            Nenhuma avaliação deste curso no período selecionado.
        </div>
    @else
        {{-- Visão geral: só o que não depende da prova (presença e contagem) --}}
        <div class="grid gap-4 sm:grid-cols-3 mb-6">
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Presença</p>
                <p class="text-3xl font-bold mt-2 tracking-tight">{{ $fmt($g['presenca']) }}<span class="text-lg font-medium text-slate-500">%</span></p>
                <p class="text-xs text-slate-500 mt-1">{{ $g['presentes'] }} de {{ $g['inscritos'] }} participações &middot; {{ $g['ausentes'] }} ausente(s)</p>
            </div>
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Avaliações no período</p>
                <p class="text-3xl font-bold mt-2 tracking-tight">{{ $g['avaliacoes'] }}</p>
                <p class="text-xs text-slate-500 mt-1">em {{ count($painel['categorias']) }} categoria(s)</p>
            </div>
            <div class="bg-slate-50 border border-slate-200 rounded-xl p-5 text-sm text-slate-600">
                <p class="font-semibold text-slate-700 mb-1">Como ler este painel</p>
                Os resultados são separados por <strong>categoria de avaliação</strong>: provas de categorias diferentes não são comparáveis.
                A comparação "com a anterior" é sempre com a avaliação anterior da <strong>mesma categoria</strong>, mesmo que de outro período letivo.
                Alunos ausentes (prova inteira em branco) não entram nas médias.
            </div>
        </div>

        @foreach ($painel['insights'] as $insight)
            @php $e = $estiloInsight($insight['tom']); @endphp
            <div class="{{ $e['bg'] }} border {{ $e['borda'] }} rounded-xl p-4 flex items-start gap-3 mb-6">
                <i class="ph-bold {{ $insight['icone'] }} {{ $e['icone'] }} text-xl shrink-0 mt-0.5"></i>
                <p class="text-sm text-slate-700">{{ $insight['texto'] }}</p>
            </div>
        @endforeach

        {{-- Uma seção por categoria --}}
        @foreach ($painel['categorias'] as $i => $cat)
            @php $t = $cat['totais']; @endphp
            <section class="mb-10" aria-labelledby="cat-{{ $i }}">
                <h2 id="cat-{{ $i }}" class="text-lg font-bold mb-3 flex items-center gap-2">
                    <i class="ph-bold ph-folder-open text-primary"></i> {{ $cat['nome'] }}
                </h2>

                @if (! empty($cat['insights']))
                    <div class="grid sm:grid-cols-2 gap-3 mb-4 sm:[&>*:last-child:nth-child(odd)]:col-span-2">
                        @foreach ($cat['insights'] as $insight)
                            @php $e = $estiloInsight($insight['tom']); @endphp
                            <div class="{{ $e['bg'] }} border {{ $e['borda'] }} rounded-xl p-4 flex items-start gap-3">
                                <i class="ph-bold {{ $insight['icone'] }} {{ $e['icone'] }} text-xl shrink-0 mt-0.5"></i>
                                <p class="text-sm text-slate-700">{{ $insight['texto'] }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-4">
                    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Média da categoria</p>
                        <p class="text-3xl font-bold mt-2 tracking-tight {{ CorDesempenho::classeTexto($t['media']) }}">{{ $fmt($t['media']) }}<span class="text-lg font-medium text-slate-500">%</span></p>
                        <p class="text-xs text-slate-500 mt-1">só alunos presentes</p>
                    </div>
                    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Abaixo de 60%</p>
                        <p class="text-3xl font-bold mt-2 tracking-tight">{{ $fmt($t['abaixoPct']) }}<span class="text-lg font-medium text-slate-500">%</span></p>
                        <p class="text-xs text-slate-500 mt-1">{{ $t['abaixo'] }} de {{ $t['comNota'] }} resultados</p>
                    </div>
                    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Presença</p>
                        <p class="text-3xl font-bold mt-2 tracking-tight">{{ $fmt($t['presenca']) }}<span class="text-lg font-medium text-slate-500">%</span></p>
                        <p class="text-xs text-slate-500 mt-1">{{ $t['presentes'] }} de {{ $t['inscritos'] }} &middot; {{ $t['ausentes'] }} ausente(s)</p>
                    </div>
                    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Avaliações</p>
                        <p class="text-3xl font-bold mt-2 tracking-tight">{{ $t['avaliacoes'] }}</p>
                        <p class="text-xs text-slate-500 mt-1">neste período</p>
                    </div>
                </div>

                {{-- Evolução da categoria (todos os períodos) --}}
                @if (! empty($cat['evolucao']))
                    @php $graficos[] = ['id' => "grafico-evolucao-{$i}", 'pontos' => $cat['evolucao']]; @endphp
                    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-4">
                        <h3 class="font-semibold mb-1">Evolução da média nesta categoria</h3>
                        <p class="text-sm text-slate-500 mb-4">Todas as avaliações da categoria, inclusive de outros períodos; as do período selecionado aparecem maiores. A linha tracejada marca os 60%.</p>
                        <canvas id="grafico-evolucao-{{ $i }}" height="110"></canvas>
                    </div>
                @endif

                {{-- Avaliações da categoria --}}
                <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-x-auto mb-4">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-slate-500 text-left">
                            <tr>
                                <th class="px-4 py-3">Avaliação</th>
                                <th class="px-4 py-3">Data</th>
                                <th class="px-4 py-3">Presença</th>
                                <th class="px-4 py-3">Média</th>
                                <th class="px-4 py-3">vs. anterior da categoria</th>
                                <th class="px-4 py-3">Abaixo de 60%</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($cat['avaliacoes'] as $a)
                                <tr>
                                    <td class="px-4 py-3 font-medium">{{ $a['nome'] }} <span class="font-mono text-xs text-slate-400">#{{ $a['codigo'] }}</span></td>
                                    <td class="px-4 py-3 text-slate-500">{{ $a['data'] ? \Illuminate\Support\Carbon::parse($a['data'])->format('d/m/Y') : '—' }}</td>
                                    <td class="px-4 py-3 text-slate-600">{{ $fmt($a['presenca']) }}% <span class="text-xs text-slate-400">({{ $a['presentes'] }}/{{ $a['inscritos'] }})</span></td>
                                    <td class="px-4 py-3">
                                        @if ($a['media'] === null)
                                            <span class="text-slate-400">—</span>
                                        @else
                                            <div class="flex items-center gap-2 min-w-[140px]">
                                                <div class="h-2 w-24 rounded-full bg-slate-100 overflow-hidden">
                                                    <div class="h-full rounded-full {{ CorDesempenho::classeBg($a['media']) }}" style="width: {{ max(3, min(100, $a['media'])) }}%"></div>
                                                </div>
                                                <span class="font-bold {{ CorDesempenho::classeTexto($a['media']) }}">{{ $fmt($a['media']) }}%</span>
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($a['delta'] === null)
                                            <span class="text-slate-400" title="Nenhuma avaliação anterior desta categoria com resultado">—</span>
                                        @else
                                            <span class="font-semibold {{ $a['delta'] >= 0 ? 'text-emerald-700' : 'text-amber-700' }}">{{ $a['delta'] > 0 ? '+' : '' }}{{ $fmt($a['delta']) }} pp</span>
                                            <span class="block text-xs text-slate-400">{{ $a['anterior']['nome'] }}@if ($a['anterior']['periodoLetivo'] !== '') ({{ $a['anterior']['periodoLetivo'] }})@endif</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-slate-600">{{ $fmt($a['abaixoPct']) }}%</td>
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

                {{-- Cartões de barras da categoria. Quantos aparecerem, ocupam a linha inteira: um cartão
                     sozinho (ou o último de uma quantidade ímpar) vira largura total, com as barras em
                     colunas — nunca fica um espaço vazio ao lado. --}}
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
                    if (! empty($cat['porArea'])) {
                        $barras[] = [
                            'titulo' => 'Desempenho por área',
                            'subtitulo' => '% de acerto por área, da mais fraca para a mais forte.',
                            'itens' => array_map(fn ($a) => ['rotulo' => $a['area'], 'extra' => null, 'valor' => $a['percentual']], $cat['porArea']),
                            'rodape' => null,
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
            </section>
        @endforeach

        @if (! empty($graficos))
            <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1"></script>
            @include('partials.linha-desempenho')
            <script>
                (function () {
                    var graficos = @json($graficos);
                    graficos.forEach(function (g) {
                        var pontos = g.pontos;
                        new Chart(document.getElementById(g.id), {
                            type: 'line',
                            data: {
                                labels: pontos.map(function (p) { return p.nome + (p.periodoLetivo ? ' (' + p.periodoLetivo + ')' : ''); }),
                                datasets: [
                                    // Verde a partir de 60%, amarelo abaixo; degradê quando a linha cruza os 60%.
                                    LinhaDesempenho.serie({
                                        label: 'Média (%)',
                                        data: pontos.map(function (p) { return p.media; }),
                                        pointRadius: pontos.map(function (p) { return p.noPeriodo ? 7 : 4; }),
                                    }),
                                    {
                                        label: 'Meta de 60%',
                                        data: pontos.map(function () { return 60; }),
                                        borderColor: '#94a3b8',
                                        borderDash: [6, 6],
                                        pointRadius: 0,
                                    },
                                ],
                            },
                            options: {
                                scales: { y: { min: 0, max: 100, title: { display: true, text: '% de acerto' } } },
                                plugins: { legend: { position: 'bottom', labels: LinhaDesempenho.legenda() } },
                            },
                        });
                    });
                })();
            </script>
        @endif
    @endif
@endif
@endsection
