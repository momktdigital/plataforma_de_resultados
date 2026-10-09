@extends('layouts.app')

@section('title', 'Comparar semestres')

@php
    use App\Support\CorDesempenho;

    $semDados = ! empty($painel['semCurso']) || ! empty($painel['semResultados']);
    $pronto = isset($comparacao);
    $fmt = fn ($v, $casas = 1) => $v === null ? '—' : number_format($v, $casas, ',', '.');
    $estiloInsight = fn (string $tom) => match ($tom) {
        'positivo' => ['bg' => 'bg-emerald-50', 'borda' => 'border-emerald-100', 'icone' => 'text-emerald-600'],
        'atencao' => ['bg' => 'bg-amber-50', 'borda' => 'border-amber-100', 'icone' => 'text-amber-600'],
        default => ['bg' => 'bg-slate-50', 'borda' => 'border-slate-100', 'icone' => 'text-slate-600'],
    };
    $manter = array_filter(['curso' => $painel['cursoSelecionado'] ?? ''], fn ($v) => $v !== '');
    $filtros = ['categoria' => (string) ($filtrosEscolhidos['categoria'] ?? ''), 'periodoCurso' => isset($filtrosEscolhidos['periodo_curso']) && $filtrosEscolhidos['periodo_curso'] !== '' ? (int) $filtrosEscolhidos['periodo_curso'] : null];
@endphp

@section('content')
@include('coordenador._cabecalho', ['ctx' => $painel, 'aba' => 'comparativo'])

@if (! empty($painel['semCurso']))
    <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-xl p-6 text-sm">
        Sua conta ainda não está vinculada a nenhum curso. Peça a um administrador para vincular em <strong>Usuários &rarr; Coordenadores</strong>.
    </div>
@elseif (! empty($painel['semResultados']))
    <div class="bg-slate-50 border border-slate-200 text-slate-600 rounded-xl p-6 text-sm">
        Ainda não há resultados importados de alunos {{ count($painel['cursosEmFoco']) > 1 ? 'destes cursos' : 'deste curso' }}.
    </div>
@elseif (! empty($poucosPeriodos))
    <div class="bg-slate-50 border border-slate-200 text-slate-600 rounded-xl p-6 text-sm">
        Só existe um período letivo com resultados. A comparação fica disponível quando houver resultados de dois períodos.
    </div>
@else
    <form method="GET" action="{{ route('coordenador.comparativo') }}" class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 mb-6 flex flex-wrap items-end gap-3">
        @if (count($painel['meusCursos']) > 1)
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1" for="seletor-curso">Curso</label>
                <select id="seletor-curso" name="curso" onchange="this.form.submit()" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white min-w-[180px] max-w-full">
                    <option value="" {{ $painel['cursoSelecionado'] === '' ? 'selected' : '' }}>Todos os meus cursos</option>
                    @foreach ($painel['meusCursos'] as $c)
                        <option value="{{ $c }}" {{ $painel['cursoSelecionado'] === $c ? 'selected' : '' }}>{{ $c }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div>
            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1" for="periodo-atual">Período</label>
            <select id="periodo-atual" name="periodo_letivo" onchange="this.form.submit()" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white min-w-[130px]">
                @foreach ($periodos as $p)
                    <option value="{{ $p }}" {{ $atual === $p ? 'selected' : '' }}>{{ $p }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1" for="periodo-referencia">Comparar com</label>
            <select id="periodo-referencia" name="comparar" onchange="this.form.submit()" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white min-w-[130px]">
                @foreach ($periodos as $p)
                    @if ($p !== $atual)
                        <option value="{{ $p }}" {{ $referencia === $p ? 'selected' : '' }}>{{ $p }}</option>
                    @endif
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1" for="filtro-categoria">Categoria</label>
            <select id="filtro-categoria" name="categoria" onchange="this.form.submit()" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white min-w-[170px] max-w-full">
                <option value="" {{ $filtros['categoria'] === '' ? 'selected' : '' }}>Todas as categorias</option>
                @foreach ($comparacao['categoriasDisponiveis'] as $c)
                    <option value="{{ $c['id'] }}" {{ $filtros['categoria'] !== '' && (int) $filtros['categoria'] === $c['id'] ? 'selected' : '' }}>{{ $c['nome'] }}</option>
                @endforeach
            </select>
        </div>
        @if (! empty($comparacao['periodosCursoDisponiveis']))
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1" for="filtro-periodo-curso">Período do curso</label>
                <select id="filtro-periodo-curso" name="periodo_curso" onchange="this.form.submit()" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white min-w-[140px]">
                    <option value="" {{ $filtros['periodoCurso'] === null ? 'selected' : '' }}>Todos</option>
                    @foreach ($comparacao['periodosCursoDisponiveis'] as $p)
                        <option value="{{ $p }}" {{ $filtros['periodoCurso'] === $p ? 'selected' : '' }}>{{ \App\Support\PeriodoCurso::rotulo($p) }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <noscript><button type="submit" class="bg-slate-800 text-white font-semibold rounded-lg px-4 py-2 text-sm">Comparar</button></noscript>
        <p class="text-sm text-slate-500 ml-auto max-w-md">
            Cada categoria de avaliação é comparada só com ela mesma. A variação é <strong>{{ $atual }}</strong> menos <strong>{{ $referencia }}</strong>.
        </p>
    </form>

    {{-- Visão geral dos dois períodos --}}
    @php
        $cartoes = [
            ['rotulo' => 'Alunos', 'icone' => 'ph-users-three', 'par' => $comparacao['geral']['alunos'], 'sufixo' => '', 'unidade' => '', 'inverter' => false],
            ['rotulo' => 'Precisam de atenção', 'icone' => 'ph-warning-circle', 'par' => $comparacao['geral']['emAtencao'], 'sufixo' => '', 'unidade' => '', 'inverter' => true],
            ['rotulo' => 'Presença', 'icone' => 'ph-user-check', 'par' => $comparacao['geral']['presenca'], 'sufixo' => '%', 'unidade' => 'pp', 'inverter' => false],
            ['rotulo' => 'Avaliações', 'icone' => 'ph-exam', 'par' => $comparacao['geral']['avaliacoes'], 'sufixo' => '', 'unidade' => '', 'inverter' => false],
        ];
    @endphp
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-6">
        @foreach ($cartoes as $c)
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 flex items-center gap-1.5"><i class="ph-bold {{ $c['icone'] }}" aria-hidden="true"></i> {{ $c['rotulo'] }}</p>
                <p class="text-3xl font-bold mt-2 tracking-tight">{{ $fmt($c['par']['atual'], $c['unidade'] === '' ? 0 : 1) }}<span class="text-lg font-medium text-slate-500">{{ $c['sufixo'] }}</span></p>
                <p class="text-xs text-slate-500 mt-1">{{ $atual }} &middot; em {{ $referencia }}: {{ $fmt($c['par']['referencia'], $c['unidade'] === '' ? 0 : 1) }}{{ $c['sufixo'] }}</p>
                <p class="mt-2">@include('coordenador._variacao', ['delta' => $c['par']['delta'], 'unidade' => $c['unidade'], 'inverter' => $c['inverter']])</p>
            </div>
        @endforeach
    </div>

    @if (! empty($comparacao['destaques']))
        <section class="mb-8" aria-labelledby="titulo-destaques-comparacao">
            <h2 id="titulo-destaques-comparacao" class="font-bold mb-3 flex items-center gap-2"><i class="ph-bold ph-lightbulb text-primary" aria-hidden="true"></i> O que mudou</h2>
            <div class="grid sm:grid-cols-2 gap-3 sm:[&>*:last-child:nth-child(odd)]:col-span-2">
                @foreach ($comparacao['destaques'] as $insight)
                    @php $e = $estiloInsight($insight['tom']); @endphp
                    <div class="{{ $e['bg'] }} border {{ $e['borda'] }} rounded-xl p-4 flex items-start gap-3">
                        <i class="ph-bold {{ $insight['icone'] }} {{ $e['icone'] }} text-xl shrink-0 mt-0.5" aria-hidden="true"></i>
                        <p class="text-sm text-slate-700">{{ $insight['texto'] }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Uma seção por categoria --}}
    @forelse ($comparacao['categorias'] as $i => $cat)
        <section class="mb-10" aria-labelledby="cat-{{ $i }}">
            <h2 id="cat-{{ $i }}" class="text-lg font-bold mb-3 flex items-center gap-2">
                <i class="ph-bold ph-folder-open text-primary" aria-hidden="true"></i> {{ $cat['nome'] }}
            </h2>

            @if (! $cat['emAmbos'])
                <div class="bg-slate-50 border border-slate-200 text-slate-600 rounded-xl p-4 text-sm">
                    Esta categoria só teve avaliações em <strong>{{ $cat['so'] }}</strong>, então não há com o que comparar.
                    @php $unico = $cat['so'] === $atual ? 'atual' : 'referencia'; @endphp
                    Média no período: <strong>{{ $fmt($cat['media'][$unico]) }}%</strong>.
                </div>
            @else
                @php
                    $ctxPlano = ['curso' => $painel['cursoSelecionado'] ?? '', 'periodo_letivo' => $atual, 'categoria' => $cat['id']];
                    $metricas = [
                        ['rotulo' => 'Média', 'par' => $cat['media'], 'inverter' => false, 'cor' => true],
                        // Com o mínimo esperado por período na categoria, o corte é o esperado de cada aluno; sem ele, os 60% de sempre.
                        $cat['comMeta']
                            ? ['rotulo' => 'Alunos abaixo do esperado', 'par' => $cat['abaixoEsperado'], 'inverter' => true, 'cor' => false]
                            : ['rotulo' => 'Alunos abaixo de '.(int) \App\Services\CoordenadorAlunosService::LIMIAR_ADEQUADO.'%', 'par' => $cat['abaixoPct'], 'inverter' => true, 'cor' => false],
                        ['rotulo' => 'Presença', 'par' => $cat['presenca'], 'inverter' => false, 'cor' => false],
                    ];
                @endphp
                <div class="grid gap-4 sm:grid-cols-3 mb-4">
                    @foreach ($metricas as $m)
                        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
                            <div class="flex items-start justify-between gap-2">
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $m['rotulo'] }}</p>
                                @include('plano._botao', ['visual' => $m['rotulo'] === 'Presença' ? 'participacao' : 'proficiencia', 'titulo' => $m['rotulo'].' (comparação de semestres)', 'ctx' => $ctxPlano])
                            </div>
                            <p class="text-3xl font-bold mt-2 tracking-tight {{ $m['cor'] ? CorDesempenho::classeTexto($m['par']['atual']) : '' }}">{{ $fmt($m['par']['atual']) }}<span class="text-lg font-medium text-slate-500">%</span></p>
                            <p class="text-xs text-slate-500 mt-1">{{ $atual }} &middot; em {{ $referencia }}: {{ $fmt($m['par']['referencia']) }}%</p>
                            <p class="mt-2">@include('coordenador._variacao', ['delta' => $m['par']['delta'], 'inverter' => $m['inverter']])</p>
                        </div>
                    @endforeach
                </div>

                <div class="grid gap-4 lg:grid-cols-2 mb-4">
                    @foreach ([
                        ['visual' => 'area', 'titulo' => 'Desempenho por área', 'sub' => 'Da que mais piorou para a que mais melhorou.', 'itens' => $cat['areas'], 'vazio' => 'Sem áreas com respostas suficientes em algum dos períodos.'],
                        ['visual' => 'periodo_curso', 'titulo' => 'Desempenho por período do curso', 'sub' => 'Média de cada período do curso (1º, 2º...) em cada semestre.', 'itens' => $cat['periodosDoCurso'], 'vazio' => 'Sem período do curso válido na planilha de resultados.'],
                    ] as $quadro)
                        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
                            <div class="flex items-start justify-between gap-3">
                                <h3 class="font-semibold mb-1">{{ $quadro['titulo'] }}</h3>
                                @if (! empty($quadro['itens']))
                                    @include('plano._botao', [
                                        'visual' => $quadro['visual'], 'titulo' => $quadro['titulo'].' ('.$atual.')', 'ctx' => $ctxPlano,
                                        'planoItens' => array_map(fn ($it) => ['rotulo' => $it['rotulo'], 'valor' => $it['atual'] !== null ? $fmt($it['atual']).'%' : null], $quadro['itens']),
                                    ])
                                @endif
                            </div>
                            <p class="text-sm text-slate-500 mb-3">{{ $quadro['sub'] }}</p>
                            @if (empty($quadro['itens']))
                                <p class="text-sm text-slate-500">{{ $quadro['vazio'] }}</p>
                            @else
                                <div class="overflow-x-auto max-h-96 overflow-y-auto">
                                    <table class="w-full text-sm">
                                        <caption class="sr-only">{{ $quadro['titulo'] }}: {{ $referencia }} contra {{ $atual }}</caption>
                                        <thead class="text-slate-500 text-left">
                                            <tr>
                                                <th scope="col" class="py-2 pr-3 font-semibold">{{ $loop->first ? 'Área' : 'Período' }}</th>
                                                <th scope="col" class="py-2 px-3 font-semibold text-right">{{ $referencia }}</th>
                                                <th scope="col" class="py-2 px-3 font-semibold text-right">{{ $atual }}</th>
                                                <th scope="col" class="py-2 pl-3 font-semibold">Variação</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100">
                                            @foreach ($quadro['itens'] as $item)
                                                <tr>
                                                    <th scope="row" class="py-2 pr-3 text-left font-medium">{{ $item['rotulo'] }}</th>
                                                    <td class="py-2 px-3 text-right {{ CorDesempenho::classeTextoLegivel($item['referencia']) }}">{{ $item['referencia'] !== null ? $fmt($item['referencia']).'%' : '—' }}</td>
                                                    <td class="py-2 px-3 text-right font-bold {{ CorDesempenho::classeTextoLegivel($item['atual']) }}">{{ $item['atual'] !== null ? $fmt($item['atual']).'%' : '—' }}</td>
                                                    <td class="py-2 pl-3 whitespace-nowrap">@include('coordenador._variacao', ['delta' => $item['delta']])</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- Alunos dentro do esperado, por período do curso (olha para o período, não para o aluno) --}}
                @php $ap = $cat['alunosPorPeriodo']; @endphp
                <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
                    <h3 class="font-semibold mb-1">Alunos dentro do esperado, por período do curso</h3>
                    @if ($ap === null || empty($ap['periodos']))
                        <p class="text-sm text-slate-500">Nenhum aluno com nota nesta categoria para comparar por período do curso.</p>
                    @else
                        <p class="text-sm text-slate-500 mb-4">
                            Em cada período do curso, quantos alunos atingiram o resultado esperado em {{ $referencia }} e em {{ $atual }}
                            @if ($cat['comMeta'])
                                (o esperado de cada aluno depende do período em que ele está; sem essa informação na prova, vale 60%).
                            @else
                                (a categoria não define o esperado por período: vale 60% de acerto).
                            @endif
                            Variação menor que {{ (int) \App\Services\ComparacaoSemestresService::VARIACAO_PERIODO }} pontos conta como estável.
                        </p>

                        <div class="grid grid-cols-3 gap-3 mb-4 text-center">
                            <div class="rounded-xl bg-emerald-50 border border-emerald-100 p-3">
                                <p class="text-2xl font-black text-emerald-700">{{ $ap['subiram'] }}</p>
                                <p class="text-xs font-medium text-slate-600">{{ $ap['subiram'] === 1 ? 'período subiu' : 'períodos subiram' }}</p>
                            </div>
                            <div class="rounded-xl bg-slate-50 border border-slate-200 p-3">
                                <p class="text-2xl font-black text-slate-700">{{ $ap['estaveis'] }}</p>
                                <p class="text-xs font-medium text-slate-600">{{ $ap['estaveis'] === 1 ? 'período estável' : 'períodos estáveis' }}</p>
                            </div>
                            <div class="rounded-xl bg-amber-50 border border-amber-100 p-3">
                                <p class="text-2xl font-black text-amber-700">{{ $ap['cairam'] }}</p>
                                <p class="text-xs font-medium text-slate-600">{{ $ap['cairam'] === 1 ? 'período caiu' : 'períodos caíram' }}</p>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <caption class="sr-only">Alunos dentro do esperado por período do curso: {{ $referencia }} contra {{ $atual }}</caption>
                                <thead class="text-slate-500 text-left">
                                    <tr>
                                        <th scope="col" class="py-2 pr-3 font-semibold">Período do curso</th>
                                        <th scope="col" class="py-2 px-3 font-semibold">{{ $referencia }}</th>
                                        <th scope="col" class="py-2 px-3 font-semibold">{{ $atual }}</th>
                                        <th scope="col" class="py-2 pl-3 font-semibold">Variação</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach ($ap['periodos'] as $p)
                                        <tr>
                                            <th scope="row" class="py-2 pr-3 text-left font-medium whitespace-nowrap">{{ $p['rotulo'] }}</th>
                                            @foreach ([$p['referencia'], $p['atual']] as $lado)
                                                <td class="py-2 px-3 min-w-[170px]">
                                                    @if ($lado === null)
                                                        <span class="text-slate-500">—</span>
                                                    @else
                                                        <div class="flex items-center gap-2">
                                                            <div class="h-2 w-20 rounded-full bg-slate-100 overflow-hidden" aria-hidden="true">
                                                                <div class="h-full rounded-full bg-primary" style="width: {{ max(3, min(100, $lado['pct'])) }}%"></div>
                                                            </div>
                                                            <span class="font-bold text-slate-700">{{ $fmt($lado['pct'], 0) }}%</span>
                                                        </div>
                                                        <span class="block text-xs text-slate-500">{{ $lado['dentro'] }} de {{ $lado['presentes'] }} alunos</span>
                                                    @endif
                                                </td>
                                            @endforeach
                                            <td class="py-2 pl-3 whitespace-nowrap">
                                                @if ($p['sentido'] === null)
                                                    <span class="text-xs text-slate-500">só em um dos períodos</span>
                                                @else
                                                    @include('coordenador._variacao', ['delta' => $p['deltaPct']])
                                                    <span class="block text-xs text-slate-500 mt-1">
                                                        {{ $p['deltaAlunos'] > 0 ? '+' : ($p['deltaAlunos'] < 0 ? '−' : '') }}{{ abs($p['deltaAlunos']) }} {{ abs($p['deltaAlunos']) === 1 ? 'aluno' : 'alunos' }} dentro do esperado
                                                    </span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endif
        </section>
    @empty
        <div class="bg-slate-50 border border-slate-200 text-slate-600 rounded-xl p-6 text-sm">
            Nenhuma categoria com avaliações nestes períodos.
        </div>
    @endforelse
@endif
@endsection
