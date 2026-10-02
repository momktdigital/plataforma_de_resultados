@extends('layouts.app')

@section('title', 'Alunos do curso')

@php
    use App\Services\CoordenadorAlunosService;
    use App\Support\CorDesempenho;

    $semDados = ! empty($painel['semCurso']) || ! empty($painel['semResultados']);
    $temLista = isset($resumo);
    $fmt = fn ($v, $casas = 1) => $v === null ? '—' : number_format($v, $casas, ',', '.');

    // Links de ficha/abas mantêm o curso e o semestre em foco.
    $manter = array_filter(['curso' => $painel['cursoSelecionado'] ?? ''], fn ($v) => $v !== '');
    if (! $semDados) {
        $manter['periodo_letivo'] = $painel['periodoSelecionado'];
    }

    // Query atual sem a página (para os atalhos de situação e o "limpar").
    $consulta = request()->except('page');
    // (periodo_letivo vazio = "Todos" e precisa seguir na URL; os demais filtros vazios saem.)
    $linkSituacao = fn (string $situacao) => route('coordenador.alunos', array_filter(
        [...$consulta, 'situacao' => $situacao, 'periodo_letivo' => $painel['periodoSelecionado'] ?? null],
        fn ($v, $k) => $k === 'periodo_letivo' ? $v !== null : ($v !== '' && $v !== null),
        ARRAY_FILTER_USE_BOTH,
    ));
@endphp

@section('content')
@include('coordenador._cabecalho', [
    'ctx' => $painel,
    'aba' => 'alunos',
    'chips' => ! $temLista ? [] : [
        ['valor' => $resumo['total'], 'rotulo' => 'Alunos'],
        ['valor' => $resumo['precisamAtencao'], 'rotulo' => 'Em atenção'],
        ['valor' => $fmt($resumo['presenca']).'%', 'rotulo' => 'Presença'],
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
    <form method="GET" action="{{ route('coordenador.alunos') }}" class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 mb-4">
        @if (! empty($filtros['situacao']))
            <input type="hidden" name="situacao" value="{{ $filtros['situacao'] }}">
        @endif
        <div class="flex flex-wrap items-end gap-3">
            @include('coordenador._seletores', ['ctx' => $painel, 'autoEnviar' => false])

            @if ($temLista && count($categorias) > 1)
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1" for="filtro-categoria">Categoria das avaliações</label>
                    <select id="filtro-categoria" name="categoria" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white min-w-[180px]">
                        <option value="">Todas</option>
                        @foreach ($categorias as $id => $nome)
                            <option value="{{ $id }}" {{ (string) $categoria === (string) $id ? 'selected' : '' }}>{{ $nome }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if ($temLista)
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1" for="filtro-busca">Buscar aluno</label>
                    <input id="filtro-busca" type="search" name="busca" value="{{ $filtros['busca'] }}" placeholder="Nome ou RA"
                           class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white w-56">
                </div>
                @if (! empty($resumo['porPeriodoCurso']))
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1" for="filtro-periodo-curso">Período do curso</label>
                        <select id="filtro-periodo-curso" name="periodo_curso" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white min-w-[130px]">
                            <option value="">Todos</option>
                            @foreach ($resumo['porPeriodoCurso'] as $p)
                                <option value="{{ $p['ordinal'] }}" {{ $filtros['periodo_curso'] === (string) $p['ordinal'] ? 'selected' : '' }}>{{ $p['rotulo'] }} ({{ $p['alunos'] }})</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1" for="filtro-ordem">Ordenar por</label>
                    <select id="filtro-ordem" name="ordem" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white min-w-[170px]">
                        @foreach (['nome' => 'Nome (A–Z)', 'prioridade' => 'Quem precisa de atenção primeiro', 'media_asc' => 'Menor média', 'media_desc' => 'Maior média', 'presenca_asc' => 'Menor presença', 'faltas' => 'Mais faltas'] as $valor => $rotulo)
                            <option value="{{ $valor }}" {{ $filtros['ordem'] === $valor ? 'selected' : '' }}>{{ $rotulo }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="flex items-center gap-2">
                <button type="submit" class="bg-slate-800 hover:bg-slate-900 text-white font-semibold rounded-lg px-4 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">Filtrar</button>
                @if ($temLista)
                    <a href="{{ route('coordenador.alunos', $manter) }}" class="text-sm text-slate-500 hover:text-slate-800 underline">Limpar filtros</a>
                @endif
            </div>

            @if ($temLista)
                <a href="{{ route('coordenador.alunos.xlsx', array_filter([...$consulta, 'periodo_letivo' => $painel['periodoSelecionado']], fn ($v) => $v !== null)) }}"
                   class="ml-auto inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                    <i class="ph-bold ph-file-xls text-lg" aria-hidden="true"></i> Baixar planilha
                </a>
            @endif
        </div>
    </form>

    @if (! $temLista)
        <div class="bg-slate-50 border border-slate-200 text-slate-600 rounded-xl p-6 text-sm">
            Nenhuma avaliação deste curso no período selecionado.
        </div>
    @else
        {{-- Atalhos de situação --}}
        @php
            $atalhos = [
                '' => ['Todos', $resumo['total']],
                'atencao' => ['Precisam de atenção', $resumo['precisamAtencao']],
                'regular' => ['Regulares', $resumo['porSituacao']['regular']],
                'destaque' => ['Destaques', $resumo['porSituacao']['destaque']],
                'sem_resultado' => ['Sem resultado', $resumo['porSituacao']['sem_resultado']],
            ];
        @endphp
        <nav class="flex flex-wrap gap-2 mb-4" aria-label="Filtrar por situação">
            @foreach ($atalhos as $chave => [$rotulo, $quantidade])
                @php $ativo = $filtros['situacao'] === $chave; @endphp
                <a href="{{ $linkSituacao($chave) }}" @if ($ativo) aria-current="true" @endif
                   class="inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-primary {{ $ativo ? 'bg-slate-800 border-slate-800 text-white' : 'bg-white border-slate-300 text-slate-700 hover:bg-slate-50' }}">
                    {{ $rotulo }} <span class="rounded-full px-2 text-xs font-bold {{ $ativo ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600' }}">{{ $quantidade }}</span>
                </a>
            @endforeach
        </nav>

        <p class="text-sm text-slate-500 mb-3" role="status">
            {{ $alunosPagina->total() }} aluno(s) na lista.
            Ausente = prova inteira em branco; ausentes não entram nas médias.
            @if ($categoria === '' && count($categorias) > 1)
                A média junta todas as categorias — escolha uma categoria para comparar provas do mesmo tipo.
            @endif
        </p>

        @if ($alunosPagina->isEmpty())
            <div class="bg-slate-50 border border-slate-200 text-slate-600 rounded-xl p-6 text-sm">
                Nenhum aluno encontrado com estes filtros.
            </div>
        @else
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">Alunos do curso, com presença, média e situação no período</caption>
                    <thead class="bg-slate-50 text-slate-500 text-left">
                        <tr>
                            <th scope="col" class="px-4 py-3">Aluno</th>
                            <th scope="col" class="px-4 py-3">Período / turma</th>
                            <th scope="col" class="px-4 py-3">Presença</th>
                            <th scope="col" class="px-4 py-3">Média</th>
                            <th scope="col" class="px-4 py-3">Última avaliação</th>
                            <th scope="col" class="px-4 py-3">Situação</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($alunosPagina as $a)
                            <tr class="align-top">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3 min-w-[220px]">
                                        @include('coordenador._avatar', ['nome' => $a['nome'] ?? $a['ra'], 'foto' => $a['foto'], 'tamanho' => 'w-10 h-10'])
                                        <div class="min-w-0">
                                            @if ($a['id'])
                                                <a href="{{ route('coordenador.alunos.show', [$a['id'], ...$manter]) }}" class="font-semibold text-slate-800 hover:text-emerald-700 hover:underline">{{ $a['nome'] ?: 'Aluno sem nome' }}</a>
                                            @else
                                                <span class="font-semibold text-slate-800">{{ $a['nome'] ?: 'Aluno sem cadastro' }}</span>
                                            @endif
                                            <p class="text-xs text-slate-500">RA {{ $a['ra'] ?: '—' }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-slate-600 whitespace-nowrap">
                                    {{ $a['periodoCursoRotulo'] ?? '—' }}
                                    @if ($a['turma'])<span class="block text-xs text-slate-500">{{ $a['turma'] }}</span>@endif
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    @if ($a['inscritos'] === 0)
                                        <span class="text-slate-500">—</span>
                                    @else
                                        <span class="font-semibold">{{ $a['presentes'] }}/{{ $a['inscritos'] }}</span>
                                        <span class="block text-xs {{ $a['faltas'] >= CoordenadorAlunosService::FALTAS_ALERTA ? 'text-amber-700 font-semibold' : 'text-slate-500' }}">{{ $a['faltas'] }} falta(s)</span>
                                    @endif
                                </td>
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
                                <td class="px-4 py-3 text-slate-600">
                                    @if ($a['ultima'])
                                        <span class="font-semibold {{ CorDesempenho::classeTextoLegivel($a['ultima']['pc']) }}">{{ $fmt($a['ultima']['pc']) }}%</span>
                                        @if ($a['tendencia'])
                                            <span class="text-xs font-semibold {{ $a['tendencia']['delta'] >= 0 ? 'text-emerald-700' : 'text-amber-700' }}" title="Variação frente à avaliação anterior da mesma categoria">
                                                <i class="ph-bold {{ $a['tendencia']['delta'] >= 0 ? 'ph-trend-up' : 'ph-trend-down' }}" aria-hidden="true"></i>
                                                {{ $a['tendencia']['delta'] > 0 ? '+' : '' }}{{ $fmt($a['tendencia']['delta']) }} pp
                                            </span>
                                        @endif
                                        <span class="block text-xs text-slate-500 truncate max-w-[200px]" title="{{ $a['ultima']['nome'] }}">{{ $a['ultima']['nome'] }}</span>
                                    @else
                                        <span class="text-slate-500">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @include('coordenador._situacao', ['situacao' => $a['situacao']])
                                    @if (! empty($a['motivos']) && $a['situacao'] !== 'sem_resultado')
                                        <ul class="mt-1.5 space-y-0.5 text-xs text-slate-600 max-w-[260px]">
                                            @foreach ($a['motivos'] as $motivo)
                                                <li>{{ $motivo }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $alunosPagina->links() }}
            </div>
        @endif
    @endif
@endif
@endsection
