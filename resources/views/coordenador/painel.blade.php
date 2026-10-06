@extends('layouts.app')

@section('title', 'Visão geral do curso')

@php
    use App\Support\CorDesempenho;

    $semDados = ! empty($painel['semCurso']) || ! empty($painel['semResultados']);
    $g = $painel['geral'] ?? null;
    $temAvaliacoes = ! $semDados && $g['avaliacoes'] > 0;
    $fmt = fn ($v, $casas = 1) => $v === null ? '—' : number_format($v, $casas, ',', '.');
    $estiloInsight = fn (string $tom) => match ($tom) {
        'positivo' => ['bg' => 'bg-emerald-50', 'borda' => 'border-emerald-100', 'icone' => 'text-emerald-600'],
        'atencao' => ['bg' => 'bg-amber-50', 'borda' => 'border-amber-100', 'icone' => 'text-amber-600'],
        default => ['bg' => 'bg-slate-50', 'borda' => 'border-slate-100', 'icone' => 'text-slate-600'],
    };
    $manter = array_filter(['curso' => $painel['cursoSelecionado'] ?? ''], fn ($v) => $v !== '');
    if (! $semDados) {
        $manter['periodo_letivo'] = $painel['periodoSelecionado'];
    }
    $r = $resumoAlunos ?? null;
@endphp

@section('content')
@include('coordenador._cabecalho', [
    'ctx' => $painel,
    'aba' => 'visao',
    'chips' => $r === null ? [] : [
        ['valor' => $r['total'], 'rotulo' => 'Alunos'],
        ['valor' => $r['precisamAtencao'], 'rotulo' => 'Em atenção'],
        ['valor' => $fmt($g['presenca']).'%', 'rotulo' => 'Presença'],
    ],
])

{{-- Avisos ainda não lidos --}}
@if (isset($avisos) && $avisos->isNotEmpty())
    <section class="bg-white border border-emerald-300 ring-1 ring-emerald-100 rounded-xl shadow-sm p-5 mb-6" aria-labelledby="titulo-avisos">
        <div class="flex items-center justify-between gap-3 mb-2">
            <h2 id="titulo-avisos" class="font-bold flex items-center gap-2"><i class="ph-bold ph-bell text-primary" aria-hidden="true"></i> {{ $totalAvisos === 1 ? '1 notificação nova' : $totalAvisos.' notificações novas' }}</h2>
            <a href="{{ route('notificacoes.index', ['filtro' => 'nao-lidas']) }}" class="text-sm font-semibold text-emerald-700 hover:underline">Ver todas</a>
        </div>
        <ul class="divide-y divide-slate-100">
            @foreach ($avisos as $aviso)
                <li class="py-2 flex items-start gap-3">
                    <i class="ph-bold {{ $aviso->aparencia()['icone'] }} text-xl text-slate-600 mt-0.5" aria-hidden="true"></i>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold">{{ $aviso->titulo }}</p>
                        <p class="text-xs text-slate-600">{{ $aviso->texto }}</p>
                    </div>
                    <a href="{{ route('notificacoes.abrir', $aviso) }}" class="shrink-0 text-sm font-semibold text-emerald-700 hover:underline">Abrir</a>
                </li>
            @endforeach
        </ul>
    </section>
@endif

@if (! empty($painel['semCurso']))
    <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-xl p-6 text-sm">
        Sua conta ainda não está vinculada a nenhum curso. Peça a um administrador para vincular em <strong>Usuários &rarr; Coordenadores</strong>.
    </div>
@elseif (! empty($painel['semResultados']))
    <div class="bg-slate-50 border border-slate-200 text-slate-600 rounded-xl p-6 text-sm">
        Ainda não há resultados importados de alunos {{ count($painel['cursosEmFoco']) > 1 ? 'destes cursos' : 'deste curso' }}.
    </div>
@else
    <form method="GET" action="{{ route('coordenador.painel') }}" class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 mb-6 flex flex-wrap items-end gap-3">
        @include('coordenador._seletores', ['ctx' => $painel, 'autoEnviar' => true])
        <noscript><button type="submit" class="bg-slate-800 text-white font-semibold rounded-lg px-4 py-2 text-sm">Filtrar</button></noscript>
    </form>

    @if (! $temAvaliacoes)
        <div class="bg-slate-50 border border-slate-200 text-slate-600 rounded-xl p-6 text-sm mb-6">
            Nenhuma avaliação deste curso no período selecionado.
        </div>
    @else
        {{-- Números do semestre --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-6">
            <a href="{{ route('coordenador.alunos', $manter) }}" class="block bg-white border border-slate-200 rounded-xl shadow-sm p-5 hover:border-emerald-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 flex items-center gap-1.5"><i class="ph-bold ph-users-three" aria-hidden="true"></i> Alunos</p>
                <p class="text-3xl font-bold mt-2 tracking-tight">{{ $r['total'] }}</p>
                <p class="text-xs text-slate-500 mt-1">{{ $r['total'] - $r['porSituacao']['sem_resultado'] }} com avaliação registrada neste período</p>
            </a>
            <a href="{{ route('coordenador.alunos', [...$manter, 'situacao' => 'atencao', 'ordem' => 'prioridade']) }}" class="block bg-white border border-slate-200 rounded-xl shadow-sm p-5 hover:border-amber-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 flex items-center gap-1.5"><i class="ph-bold ph-warning-circle" aria-hidden="true"></i> Precisam de atenção</p>
                <p class="text-3xl font-bold mt-2 tracking-tight {{ $r['precisamAtencao'] > 0 ? 'text-amber-700' : '' }}">{{ $r['precisamAtencao'] }}</p>
                <p class="text-xs text-slate-500 mt-1">
                    {{ $r['total'] > 0 ? $fmt($r['precisamAtencao'] / $r['total'] * 100) : '0' }}% dos alunos &middot; ver lista
                </p>
            </a>
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 flex items-center gap-1.5"><i class="ph-bold ph-user-check" aria-hidden="true"></i> Presença</p>
                <p class="text-3xl font-bold mt-2 tracking-tight">{{ $fmt($g['presenca']) }}<span class="text-lg font-medium text-slate-500">%</span></p>
                <p class="text-xs text-slate-500 mt-1">{{ $g['presentes'] }} de {{ $g['inscritos'] }} participações &middot; {{ $g['ausentes'] }} ausente(s)</p>
            </div>
            <a href="{{ route('coordenador.desempenho', $manter) }}" class="block bg-white border border-slate-200 rounded-xl shadow-sm p-5 hover:border-emerald-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 flex items-center gap-1.5"><i class="ph-bold ph-exam" aria-hidden="true"></i> Avaliações</p>
                <p class="text-3xl font-bold mt-2 tracking-tight">{{ $g['avaliacoes'] }}</p>
                <p class="text-xs text-slate-500 mt-1">em {{ count($painel['categorias']) }} categoria(s) &middot; ver desempenho</p>
            </a>
        </div>

        <div class="grid gap-6 lg:grid-cols-3 mb-6">
            {{-- Quem precisa de atenção --}}
            <section class="lg:col-span-2 bg-white border border-slate-200 rounded-xl shadow-sm" aria-labelledby="titulo-atencao">
                <div class="px-5 pt-5 flex items-start justify-between gap-3">
                    <div>
                        <h2 id="titulo-atencao" class="font-bold flex items-center gap-2"><i class="ph-bold ph-warning-circle text-amber-600" aria-hidden="true"></i> Alunos que precisam de atenção</h2>
                        <p class="text-sm text-slate-500 mt-0.5">Média abaixo de {{ (int) \App\Services\CoordenadorAlunosService::LIMIAR_ADEQUADO }}%, {{ \App\Services\CoordenadorAlunosService::FALTAS_ALERTA }} faltas ou mais, queda de {{ (int) \App\Services\CoordenadorAlunosService::QUEDA_ALERTA }} pontos ou mais, ou ausente em tudo.</p>
                    </div>
                    @if ($r['precisamAtencao'] > 0)
                        <a href="{{ route('coordenador.alunos', [...$manter, 'situacao' => 'atencao', 'ordem' => 'prioridade']) }}" class="shrink-0 text-sm font-semibold text-emerald-700 hover:underline">Ver todos ({{ $r['precisamAtencao'] }})</a>
                    @endif
                </div>

                @if (empty($emAtencao))
                    <div class="m-5 rounded-xl bg-emerald-50 border border-emerald-100 p-4 text-sm text-emerald-800 flex items-center gap-3">
                        <i class="ph-bold ph-check-circle text-xl" aria-hidden="true"></i>
                        Nenhum aluno em atenção neste período. Bom sinal!
                    </div>
                @else
                    <ul class="divide-y divide-slate-100 mt-3">
                        @foreach ($emAtencao as $a)
                            <li class="px-5 py-3 flex items-center gap-3">
                                @include('coordenador._avatar', ['nome' => $a['nome'] ?? $a['ra'], 'foto' => $a['foto'], 'tamanho' => 'w-10 h-10'])
                                <div class="min-w-0 flex-1">
                                    @if ($a['id'])
                                        <a href="{{ route('coordenador.alunos.show', [$a['id'], ...$manter]) }}" class="font-semibold text-slate-800 hover:text-emerald-700 hover:underline truncate block">{{ $a['nome'] ?: 'Aluno sem cadastro' }}</a>
                                    @else
                                        <span class="font-semibold text-slate-800 truncate block">{{ $a['nome'] ?: 'Aluno sem cadastro' }}</span>
                                    @endif
                                    <p class="text-xs text-slate-500 truncate">
                                        RA {{ $a['ra'] ?: '—' }}@if ($a['periodoCursoRotulo']) &middot; {{ $a['periodoCursoRotulo'] }}@endif
                                    </p>
                                    <p class="text-xs text-slate-600 mt-0.5">{{ $a['motivos'][0] ?? '' }}@if (count($a['motivos']) > 1) <span class="text-slate-500">(+{{ count($a['motivos']) - 1 }})</span>@endif</p>
                                    @if (! empty($a['acompanhamento']))<p class="mt-1">@include('coordenador._acompanhamento', ['acompanhamento' => $a['acompanhamento']])</p>@endif
                                </div>
                                <div class="text-right shrink-0">
                                    <p class="font-bold {{ CorDesempenho::classeTextoLegivel($a['media']) }}">{{ $a['media'] !== null ? $fmt($a['media']).'%' : '—' }}</p>
                                    <p class="text-xs text-slate-500">{{ $a['faltas'] }} falta(s)</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Como estão os alunos --}}
            <section class="bg-white border border-slate-200 rounded-xl shadow-sm p-5" aria-labelledby="titulo-situacao">
                <h2 id="titulo-situacao" class="font-bold flex items-center gap-2"><i class="ph-bold ph-chart-donut text-primary" aria-hidden="true"></i> Situação dos alunos</h2>
                <p class="text-sm text-slate-500 mt-0.5 mb-4">Como o curso se divide neste período.</p>

                @php
                    $faixas = [
                        'destaque' => ['Destaque', 'bg-emerald-500'],
                        'regular' => ['Regular', 'bg-slate-300'],
                        'atencao' => ['Em atenção', 'bg-amber-500'],
                        'ausente' => ['Ausente em tudo', 'bg-red-500'],
                        'sem_resultado' => ['Sem resultado', 'bg-slate-200'],
                    ];
                    $descricaoFaixas = collect($faixas)->map(fn ($f, $k) => $f[0].': '.$r['porSituacao'][$k])->implode('; ');
                @endphp
                <div class="flex h-3 w-full overflow-hidden rounded-full bg-slate-100" role="img" aria-label="Situação dos alunos. {{ $descricaoFaixas }}.">
                    @foreach ($faixas as $chave => [$rotulo, $cor])
                        @if ($r['porSituacao'][$chave] > 0)
                            <div class="{{ $cor }}" style="width: {{ $r['porSituacao'][$chave] / max(1, $r['total']) * 100 }}%"></div>
                        @endif
                    @endforeach
                </div>
                <ul class="mt-4 space-y-2 text-sm">
                    @foreach ($faixas as $chave => [$rotulo, $cor])
                        <li>
                            <a href="{{ route('coordenador.alunos', [...$manter, 'situacao' => $chave]) }}" class="flex items-center justify-between gap-2 rounded-lg px-2 py-1 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                <span class="flex items-center gap-2"><span class="inline-block h-2.5 w-2.5 rounded-full {{ $cor }}" aria-hidden="true"></span> {{ $rotulo }}</span>
                                <span class="font-semibold">{{ $r['porSituacao'][$chave] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>

        {{-- Destaques do semestre, por categoria --}}
        @if (! empty($destaques))
            <section class="mb-6" aria-labelledby="titulo-destaques">
                <div class="flex items-center justify-between gap-3 mb-1">
                    <h2 id="titulo-destaques" class="font-bold flex items-center gap-2"><i class="ph-bold ph-lightbulb text-primary" aria-hidden="true"></i> O que chamou atenção</h2>
                    <a href="{{ route('coordenador.desempenho', $manter) }}" class="text-sm font-semibold text-emerald-700 hover:underline">Ver tudo em Desempenho</a>
                </div>
                <p class="text-sm text-slate-500 mb-3">Cada grupo é uma categoria de avaliação: provas de categorias diferentes não se comparam.</p>
                <div class="space-y-5">
                    @foreach ($destaques as $grupo)
                        <div>
                            <h3 class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-2 flex items-center gap-1.5"><i class="ph-bold ph-folder-open" aria-hidden="true"></i> {{ $grupo['titulo'] }}</h3>
                            <div class="grid sm:grid-cols-2 gap-3 sm:[&>*:last-child:nth-child(odd)]:col-span-2">
                                @foreach (array_slice($grupo['insights'], 0, 4) as $insight)
                                    @php $e = $estiloInsight($insight['tom']); @endphp
                                    <div class="{{ $e['bg'] }} border {{ $e['borda'] }} rounded-xl p-4 flex items-start gap-3">
                                        <i class="ph-bold {{ $insight['icone'] }} {{ $e['icone'] }} text-xl shrink-0 mt-0.5" aria-hidden="true"></i>
                                        <p class="text-sm text-slate-700">{{ $insight['texto'] }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Avaliações mais recentes --}}
        <section class="mb-6" aria-labelledby="titulo-recentes">
            <div class="flex items-center justify-between gap-3 mb-3">
                <h2 id="titulo-recentes" class="font-bold flex items-center gap-2"><i class="ph-bold ph-exam text-primary" aria-hidden="true"></i> Avaliações mais recentes</h2>
                <a href="{{ route('coordenador.desempenho', $manter) }}" class="text-sm font-semibold text-emerald-700 hover:underline">Ver desempenho detalhado</a>
            </div>
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">Avaliações mais recentes do período, com presença e média</caption>
                    <thead class="bg-slate-50 text-slate-500 text-left">
                        <tr>
                            <th scope="col" class="px-4 py-3">Avaliação</th>
                            <th scope="col" class="px-4 py-3">Data</th>
                            <th scope="col" class="px-4 py-3">Presença</th>
                            <th scope="col" class="px-4 py-3">Média</th>
                            <th scope="col" class="px-4 py-3">vs. anterior</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($recentes as $a)
                            <tr>
                                <td class="px-4 py-3">
                                    <span class="font-medium">{{ $a['nome'] }}</span> <span class="font-mono text-xs text-slate-500">#{{ $a['codigo'] }}</span>
                                    <span class="block text-xs text-slate-500">{{ $a['categoria'] }}</span>
                                </td>
                                <td class="px-4 py-3 text-slate-500 whitespace-nowrap">{{ $a['data'] ? \Illuminate\Support\Carbon::parse($a['data'])->format('d/m/Y') : '—' }}</td>
                                <td class="px-4 py-3 text-slate-600 whitespace-nowrap">{{ $fmt($a['presenca']) }}% <span class="text-xs text-slate-500">({{ $a['presentes'] }}/{{ $a['inscritos'] }})</span></td>
                                <td class="px-4 py-3">
                                    @if ($a['media'] === null)
                                        <span class="text-slate-500">—</span>
                                    @else
                                        <div class="flex items-center gap-2 min-w-[130px]">
                                            <div class="h-2 w-20 rounded-full bg-slate-100 overflow-hidden" aria-hidden="true">
                                                <div class="h-full rounded-full {{ CorDesempenho::classeBg($a['media']) }}" style="width: {{ max(3, min(100, $a['media'])) }}%"></div>
                                            </div>
                                            <span class="font-bold {{ CorDesempenho::classeTextoLegivel($a['media']) }}">{{ $fmt($a['media']) }}%</span>
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    @if ($a['delta'] === null)
                                        <span class="text-slate-500" title="Nenhuma avaliação anterior desta categoria com resultado">—</span>
                                    @else
                                        <span class="font-semibold {{ $a['delta'] >= 0 ? 'text-emerald-700' : 'text-amber-700' }}">{{ $a['delta'] > 0 ? '+' : '' }}{{ $fmt($a['delta']) }} pp</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    @if (in_array($a['codigo'], $codigosAcessiveis, true))
                                        <a href="{{ route('avaliacoes.bi', $a['codigo']) }}" class="text-emerald-700 font-semibold hover:underline">Dashboard</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Por período do curso --}}
        @if (! empty($r['porPeriodoCurso']))
            <section class="mb-6" aria-labelledby="titulo-periodos">
                <h2 id="titulo-periodos" class="font-bold mb-1 flex items-center gap-2"><i class="ph-bold ph-graduation-cap text-primary" aria-hidden="true"></i> Alunos por período do curso</h2>
                <p class="text-sm text-slate-500 mb-3">Onde estão concentrados os alunos que precisam de atenção.</p>
                <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-x-auto">
                    <table class="w-full text-sm">
                        <caption class="sr-only">Alunos, alunos em atenção e presença por período do curso</caption>
                        <thead class="bg-slate-50 text-slate-500 text-left">
                            <tr>
                                <th scope="col" class="px-4 py-3">Período do curso</th>
                                <th scope="col" class="px-4 py-3">Alunos</th>
                                <th scope="col" class="px-4 py-3">Em atenção</th>
                                <th scope="col" class="px-4 py-3">Presença</th>
                                <th scope="col" class="px-4 py-3"><span class="sr-only">Ações</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($r['porPeriodoCurso'] as $p)
                                <tr>
                                    <th scope="row" class="px-4 py-3 text-left font-medium">{{ $p['rotulo'] }}</th>
                                    <td class="px-4 py-3">{{ $p['alunos'] }}</td>
                                    <td class="px-4 py-3">
                                        <span class="font-semibold {{ $p['atencao'] > 0 ? 'text-amber-700' : 'text-slate-600' }}">{{ $p['atencao'] }}</span>
                                        <span class="text-xs text-slate-500">({{ $p['alunos'] > 0 ? $fmt($p['atencao'] / $p['alunos'] * 100, 0) : 0 }}%)</span>
                                    </td>
                                    <td class="px-4 py-3 text-slate-600">{{ $fmt($p['presenca']) }}%</td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap">
                                        <a href="{{ route('coordenador.alunos', [...$manter, 'periodo_curso' => $p['ordinal']]) }}" class="text-emerald-700 font-semibold hover:underline">Ver alunos</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        {{-- Atalhos --}}
        <section aria-labelledby="titulo-atalhos">
            <h2 id="titulo-atalhos" class="font-bold mb-3 flex items-center gap-2"><i class="ph-bold ph-lightning text-primary" aria-hidden="true"></i> Acesso rápido</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['rota' => route('coordenador.alunos', $manter), 'icone' => 'ph-users-three', 'titulo' => 'Alunos do curso', 'texto' => 'Busque, filtre e acompanhe cada aluno.'],
                    ['rota' => route('coordenador.desempenho', $manter), 'icone' => 'ph-chart-line-up', 'titulo' => 'Desempenho', 'texto' => 'Médias, evolução e áreas por categoria.'],
                    ['rota' => route('avaliacoes.index'), 'icone' => 'ph-exam', 'titulo' => 'Avaliações', 'texto' => 'Abra o dashboard de cada avaliação.'],
                    ['rota' => route('coordenador.alunos.xlsx', $manter), 'icone' => 'ph-file-xls', 'titulo' => 'Baixar lista de alunos', 'texto' => 'Planilha do período com média e situação.'],
                ] as $atalho)
                    <a href="{{ $atalho['rota'] }}" class="flex items-start gap-3 bg-white border border-slate-200 rounded-xl shadow-sm p-4 hover:border-emerald-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-700"><i class="ph-bold {{ $atalho['icone'] }} text-xl" aria-hidden="true"></i></span>
                        <span>
                            <span class="block font-semibold">{{ $atalho['titulo'] }}</span>
                            <span class="block text-xs text-slate-500 mt-0.5">{{ $atalho['texto'] }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
@endif
@endsection
