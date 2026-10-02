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
                    $metricas = [
                        ['rotulo' => 'Média', 'par' => $cat['media'], 'inverter' => false, 'cor' => true],
                        ['rotulo' => 'Alunos abaixo de '.(int) \App\Services\CoordenadorAlunosService::LIMIAR_ADEQUADO.'%', 'par' => $cat['abaixoPct'], 'inverter' => true, 'cor' => false],
                        ['rotulo' => 'Presença', 'par' => $cat['presenca'], 'inverter' => false, 'cor' => false],
                    ];
                @endphp
                <div class="grid gap-4 sm:grid-cols-3 mb-4">
                    @foreach ($metricas as $m)
                        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $m['rotulo'] }}</p>
                            <p class="text-3xl font-bold mt-2 tracking-tight {{ $m['cor'] ? CorDesempenho::classeTexto($m['par']['atual']) : '' }}">{{ $fmt($m['par']['atual']) }}<span class="text-lg font-medium text-slate-500">%</span></p>
                            <p class="text-xs text-slate-500 mt-1">{{ $atual }} &middot; em {{ $referencia }}: {{ $fmt($m['par']['referencia']) }}%</p>
                            <p class="mt-2">@include('coordenador._variacao', ['delta' => $m['par']['delta'], 'inverter' => $m['inverter']])</p>
                        </div>
                    @endforeach
                </div>

                <div class="grid gap-4 lg:grid-cols-2 mb-4">
                    @foreach ([
                        ['titulo' => 'Desempenho por área', 'sub' => 'Da que mais piorou para a que mais melhorou.', 'itens' => $cat['areas'], 'vazio' => 'Sem áreas com respostas suficientes em algum dos períodos.'],
                        ['titulo' => 'Desempenho por período do curso', 'sub' => 'Média de cada período do curso (1º, 2º...) em cada semestre.', 'itens' => $cat['periodosDoCurso'], 'vazio' => 'Sem período do curso válido na planilha de resultados.'],
                    ] as $quadro)
                        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
                            <h3 class="font-semibold mb-1">{{ $quadro['titulo'] }}</h3>
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

                {{-- Os mesmos alunos nos dois períodos --}}
                @php $al = $cat['alunos']; @endphp
                <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
                    <h3 class="font-semibold mb-1">Os mesmos alunos, nos dois períodos</h3>
                    @if ($al === null || $al['comparaveis'] === 0)
                        <p class="text-sm text-slate-500">Nenhum aluno com nota nesta categoria nos dois períodos.</p>
                    @else
                        <p class="text-sm text-slate-500 mb-4">
                            {{ $al['comparaveis'] }} aluno(s) fizeram provas desta categoria em {{ $referencia }} e em {{ $atual }}.
                            Variação menor que {{ (int) \App\Services\ComparacaoSemestresService::VARIACAO_ALUNO }} pontos conta como estável.
                        </p>
                        <div class="grid grid-cols-3 gap-3 mb-4 text-center">
                            <div class="rounded-xl bg-emerald-50 border border-emerald-100 p-3">
                                <p class="text-2xl font-black text-emerald-700">{{ $al['subiram'] }}</p>
                                <p class="text-xs font-medium text-slate-600">subiram</p>
                            </div>
                            <div class="rounded-xl bg-slate-50 border border-slate-200 p-3">
                                <p class="text-2xl font-black text-slate-700">{{ $al['estaveis'] }}</p>
                                <p class="text-xs font-medium text-slate-600">estáveis</p>
                            </div>
                            <div class="rounded-xl bg-amber-50 border border-amber-100 p-3">
                                <p class="text-2xl font-black text-amber-700">{{ $al['cairam'] }}</p>
                                <p class="text-xs font-medium text-slate-600">caíram</p>
                            </div>
                        </div>

                        <div class="grid gap-6 md:grid-cols-2">
                            @foreach ([['Mais caíram', $al['maisCairam']], ['Mais subiram', $al['maisSubiram']]] as [$titulo, $lista])
                                <div>
                                    <h4 class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-2">{{ $titulo }}</h4>
                                    @if (empty($lista))
                                        <p class="text-sm text-slate-500">Nenhum.</p>
                                    @else
                                        <ul class="divide-y divide-slate-100">
                                            @foreach ($lista as $p)
                                                <li class="py-2 flex items-center gap-3">
                                                    @include('coordenador._avatar', ['nome' => $p['nome'] ?? $p['ra'], 'foto' => $p['foto'], 'tamanho' => 'w-8 h-8 text-sm'])
                                                    <div class="min-w-0 flex-1">
                                                        <a href="{{ route('coordenador.alunos.show', [$p['id'], ...$manter, 'periodo_letivo' => $atual]) }}" class="font-medium text-slate-800 hover:text-emerald-700 hover:underline truncate block">{{ $p['nome'] ?: 'Aluno sem nome' }}</a>
                                                        <p class="text-xs text-slate-500">{{ $fmt($p['de']) }}% → {{ $fmt($p['para']) }}%</p>
                                                    </div>
                                                    @include('coordenador._variacao', ['delta' => $p['delta']])
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @endforeach
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
