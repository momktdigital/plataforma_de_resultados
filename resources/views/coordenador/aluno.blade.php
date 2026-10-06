@extends('layouts.app')

@section('title', 'Ficha do aluno')

@php
    use App\Support\CorDesempenho;

    $aluno = $ficha['aluno'];
    $nomeAluno = $aluno->nome ? mb_convert_case(mb_strtolower(trim($aluno->nome), 'UTF-8'), MB_CASE_TITLE, 'UTF-8') : 'RA '.$aluno->ra;
    $fmt = fn ($v, $casas = 1) => $v === null ? '—' : number_format($v, $casas, ',', '.');
    $semestre = $ficha['periodoSelecionado'];
    $manter = array_filter(['curso' => $painel['cursoSelecionado'] ?? ''], fn ($v) => $v !== '');
    $voltar = route('coordenador.alunos', [...$manter, 'periodo_letivo' => $semestre]);
    $estiloInsight = fn (string $tom) => match ($tom) {
        'positivo' => ['bg' => 'bg-emerald-50', 'borda' => 'border-emerald-100', 'icone' => 'text-emerald-600'],
        'atencao' => ['bg' => 'bg-amber-50', 'borda' => 'border-amber-100', 'icone' => 'text-amber-600'],
        default => ['bg' => 'bg-slate-50', 'borda' => 'border-slate-100', 'icone' => 'text-slate-600'],
    };
    $graficos = [];
@endphp

@section('content')
<a href="{{ $voltar }}" class="inline-flex items-center gap-1.5 text-sm font-semibold text-emerald-700 hover:underline mb-4 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">
    <i class="ph-bold ph-arrow-left" aria-hidden="true"></i> Alunos do curso
</a>

{{-- Cabeçalho do aluno --}}
<div class="relative overflow-hidden rounded-2xl shadow-lg mb-6" style="background: linear-gradient(135deg, #00b48d 0%, #009e7d 55%, #007a61 100%);">
    <div class="absolute inset-0 opacity-10 pointer-events-none" style="background-image: radial-gradient(circle at 85% 15%, white 0, transparent 45%), radial-gradient(circle at 10% 90%, white 0, transparent 40%);"></div>
    <div class="relative p-6 sm:p-8 flex flex-col sm:flex-row sm:items-center gap-6">
        <div class="shrink-0">
            @include('coordenador._avatar', ['nome' => $aluno->nome ?: $aluno->ra, 'foto' => $aluno->fotoUrl(150), 'tamanho' => 'w-20 h-20 sm:w-24 sm:h-24 text-3xl border-4 border-white/40 shadow-md'])
        </div>
        <div class="min-w-0 flex-1">
            <p class="text-white/85 text-sm font-medium uppercase tracking-wide">Ficha do aluno</p>
            <h1 class="text-2xl sm:text-3xl font-black text-white break-words">{{ $nomeAluno }}</h1>
            <p class="text-white/85 text-sm mt-1">
                RA {{ $aluno->ra ?: '—' }}
                @if ($ficha['curso']) &middot; {{ $ficha['curso'] }} @endif
                @if ($ficha['periodoCursoRotulo']) &middot; {{ $ficha['periodoCursoRotulo'] }} @endif
                @if ($ficha['turma']) &middot; turma {{ $ficha['turma'] }} @endif
            </p>
            @if ($ficha['statusMatricula'])
                <p class="text-white/85 text-xs mt-1">Matrícula: {{ \Illuminate\Support\Str::title(mb_strtolower($ficha['statusMatricula'])) }}</p>
            @endif
        </div>
        <div class="flex gap-3 sm:gap-4 shrink-0">
            <div class="bg-white/15 backdrop-blur-sm rounded-2xl px-4 py-3 text-center min-w-[84px]">
                <div class="text-2xl font-black text-white">{{ $ficha['presentes'] }}/{{ $ficha['inscritos'] }}</div>
                <div class="text-[11px] text-white/85 font-medium uppercase tracking-wide">Avaliações</div>
            </div>
            <div class="bg-white/15 backdrop-blur-sm rounded-2xl px-4 py-3 text-center min-w-[84px]">
                <div class="text-2xl font-black text-white">{{ $ficha['media'] !== null ? $fmt($ficha['media']).'%' : '—' }}</div>
                <div class="text-[11px] text-white/85 font-medium uppercase tracking-wide">Média</div>
            </div>
        </div>
    </div>
</div>

{{-- Acompanhamento do aluno pelo coordenador --}}
@php
    $ultimoAcomp = $acompanhamentos->first();
    $emVisaoDeCurso = (bool) ($usuario->emVisaoDeCurso ?? false);
@endphp
<section class="bg-white border border-slate-200 rounded-xl shadow-sm p-5 mb-6" aria-labelledby="titulo-acompanhamento">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
        <h2 id="titulo-acompanhamento" class="font-bold flex items-center gap-2"><i class="ph-bold ph-notebook text-primary" aria-hidden="true"></i> Acompanhamento</h2>
        @if ($ultimoAcomp)
            @include('coordenador._acompanhamento', ['acompanhamento' => ['status' => $ultimoAcomp->status, 'rotulo' => $ultimoAcomp->rotulo(), 'em' => (string) $ultimoAcomp->created_at]])
        @else
            <span class="text-sm text-slate-500">Nenhum registro ainda</span>
        @endif
    </div>

    @if (! $emVisaoDeCurso)
        <form method="POST" action="{{ route('coordenador.alunos.acompanhamento', array_filter(['aluno' => $aluno->id, 'curso' => $painel['cursoSelecionado'] ?? ''], fn ($v) => $v !== '')) }}" class="grid gap-3 sm:grid-cols-[14rem_1fr_auto] sm:items-end">
            @csrf
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1" for="acomp-status">Situação</label>
                <select id="acomp-status" name="status" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white">
                    @foreach (\App\Models\Acompanhamento::STATUS as $chaveAcomp => $rotuloAcomp)
                        <option value="{{ $chaveAcomp }}" @selected(old('status', $ultimoAcomp?->status === 'contatado' ? 'em_acompanhamento' : ($ultimoAcomp ? 'resolvido' : 'contatado')) === $chaveAcomp)>{{ $rotuloAcomp }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1" for="acomp-observacao">Observação (opcional)</label>
                <textarea id="acomp-observacao" name="observacao" rows="2" maxlength="{{ \App\Services\AcompanhamentoService::MAXIMO_OBSERVACAO }}" placeholder="O que foi conversado ou combinado" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ old('observacao') }}</textarea>
            </div>
            <button type="submit" class="rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white font-semibold px-4 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">Registrar</button>
        </form>
        <p class="mt-2 text-xs text-slate-600">As anotações ficam visíveis só para quem coordena este curso. Os registros não são editados: cada novo registro entra no histórico.</p>
    @endif

    @if ($acompanhamentos->isNotEmpty())
        <ol class="mt-4 space-y-3 border-t border-slate-100 pt-4" aria-label="Histórico de acompanhamento">
            @foreach ($acompanhamentos as $registro)
                <li class="flex gap-3">
                    <span class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full bg-emerald-600" aria-hidden="true"></span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-slate-800">{{ $registro->rotulo() }} <span class="font-normal text-slate-500">&middot; {{ $registro->created_at?->format('d/m/Y H:i') }} &middot; {{ $registro->admin?->username ?? 'usuário removido' }}</span></p>
                        @if ($registro->observacao)
                            <p class="text-sm text-slate-700 whitespace-pre-line break-words">{{ $registro->observacao }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</section>

{{-- Semestre --}}
@if (! empty($ficha['periodosDoAluno']))
    <form method="GET" action="{{ route('coordenador.alunos.show', $aluno->id) }}" class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 mb-6 flex flex-wrap items-end gap-3">
        @if (($painel['cursoSelecionado'] ?? '') !== '')
            <input type="hidden" name="curso" value="{{ $painel['cursoSelecionado'] }}">
        @endif
        <div>
            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1" for="periodo-letivo">Período letivo</label>
            <select id="periodo-letivo" name="periodo_letivo" onchange="this.form.submit()" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white min-w-[140px]">
                <option value="" {{ $semestre === '' ? 'selected' : '' }}>Todos</option>
                @foreach ($ficha['periodosDoAluno'] as $p)
                    <option value="{{ $p }}" {{ $semestre === $p ? 'selected' : '' }}>{{ $p }}</option>
                @endforeach
            </select>
        </div>
        <noscript><button type="submit" class="bg-slate-800 text-white font-semibold rounded-lg px-4 py-2 text-sm">Filtrar</button></noscript>
        <p class="text-sm text-slate-500 ml-auto">Mostrando só os resultados do curso {{ count($painel['cursosEmFoco']) > 1 ? 'dos seus cursos' : 'que você coordena' }}.</p>
    </form>
@endif

{{-- Situação --}}
<div class="grid gap-4 lg:grid-cols-3 mb-6">
    <section class="lg:col-span-1 bg-white border border-slate-200 rounded-xl shadow-sm p-5" aria-labelledby="titulo-situacao-aluno">
        <h2 id="titulo-situacao-aluno" class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Situação no período</h2>
        @include('coordenador._situacao', ['situacao' => $ficha['situacao']])
        @if (! empty($ficha['motivos']))
            <ul class="mt-3 space-y-1.5 text-sm text-slate-700">
                @foreach ($ficha['motivos'] as $motivo)
                    <li class="flex items-start gap-2"><i class="ph-bold ph-dot-outline text-lg shrink-0 leading-5" aria-hidden="true"></i> {{ $motivo }}</li>
                @endforeach
            </ul>
        @elseif ($ficha['situacao'] === 'destaque')
            <p class="mt-3 text-sm text-slate-700">Média de {{ $fmt($ficha['media']) }}% e nenhuma falta no período.</p>
        @elseif ($ficha['situacao'] === 'regular')
            <p class="mt-3 text-sm text-slate-700">Sem pontos de atenção neste período.</p>
        @endif
    </section>

    <div class="lg:col-span-2 grid gap-4 sm:grid-cols-3">
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Presença</p>
            <p class="text-3xl font-bold mt-2 tracking-tight">{{ $fmt($ficha['presenca']) }}<span class="text-lg font-medium text-slate-500">%</span></p>
            <p class="text-xs text-slate-500 mt-1">{{ $ficha['presentes'] }} de {{ $ficha['inscritos'] }} &middot; {{ $ficha['faltas'] }} falta(s)</p>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Média</p>
            <p class="text-3xl font-bold mt-2 tracking-tight {{ CorDesempenho::classeTexto($ficha['media']) }}">{{ $fmt($ficha['media']) }}<span class="text-lg font-medium text-slate-500">%</span></p>
            <p class="text-xs text-slate-500 mt-1">{{ $ficha['abaixo'] }} avaliação(ões) abaixo de {{ (int) \App\Services\CoordenadorAlunosService::LIMIAR_ADEQUADO }}%</p>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Última avaliação</p>
            @if ($ficha['ultima'])
                <p class="text-3xl font-bold mt-2 tracking-tight {{ CorDesempenho::classeTexto($ficha['ultima']['pc']) }}">{{ $fmt($ficha['ultima']['pc']) }}<span class="text-lg font-medium text-slate-500">%</span></p>
                <p class="text-xs text-slate-500 mt-1 truncate" title="{{ $ficha['ultima']['nome'] }}">{{ $ficha['ultima']['nome'] }}</p>
            @else
                <p class="text-3xl font-bold mt-2 tracking-tight text-slate-500">—</p>
                <p class="text-xs text-slate-500 mt-1">nenhuma prova feita</p>
            @endif
        </div>
    </div>
</div>

@if (! empty($ficha['resumoTexto']))
    <div class="grid sm:grid-cols-2 gap-3 mb-6 sm:[&>*:last-child:nth-child(odd)]:col-span-2">
        @foreach ($ficha['resumoTexto'] as $insight)
            @php $e = $estiloInsight($insight['tom']); @endphp
            <div class="{{ $e['bg'] }} border {{ $e['borda'] }} rounded-xl p-4 flex items-start gap-3">
                <i class="ph-bold {{ $insight['icone'] }} {{ $e['icone'] }} text-xl shrink-0 mt-0.5" aria-hidden="true"></i>
                <p class="text-sm text-slate-700">{{ $insight['texto'] }}</p>
            </div>
        @endforeach
    </div>
@endif

{{-- Uma seção por categoria de avaliação --}}
@forelse ($ficha['categorias'] as $i => $cat)
    <section class="mb-10" aria-labelledby="cat-{{ $i }}">
        <h2 id="cat-{{ $i }}" class="text-lg font-bold mb-3 flex items-center gap-2">
            <i class="ph-bold ph-folder-open text-primary" aria-hidden="true"></i> {{ $cat['nome'] }}
        </h2>

        @if (! empty($cat['grafico']))
            @php $graficos[] = ['id' => "grafico-aluno-{$i}", 'pontos' => $cat['grafico']]; @endphp
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-4">
                <h3 class="font-semibold mb-1">Evolução nesta categoria</h3>
                <p class="text-sm text-slate-500 mb-4">Nota do aluno em cada avaliação, comparada com a média do curso (só presentes). A linha tracejada marca os 60%.</p>
                <canvas id="grafico-aluno-{{ $i }}" height="110" data-titulo="Evolução do aluno em {{ $cat['nome'] }}"></canvas>
            </div>
        @endif

        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-x-auto mb-4">
            <table class="w-full text-sm">
                <caption class="sr-only">Avaliações de {{ $cat['nome'] }} feitas por {{ $nomeAluno }}</caption>
                <thead class="bg-slate-50 text-slate-500 text-left">
                    <tr>
                        <th scope="col" class="px-4 py-3">Avaliação</th>
                        <th scope="col" class="px-4 py-3">Data</th>
                        <th scope="col" class="px-4 py-3">Nota do aluno</th>
                        <th scope="col" class="px-4 py-3">Média do curso</th>
                        <th scope="col" class="px-4 py-3">Diferença</th>
                        <th scope="col" class="px-4 py-3">Posição</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($cat['avaliacoes'] as $a)
                        <tr>
                            <td class="px-4 py-3 font-medium">{{ $a['nome'] }} <span class="font-mono text-xs text-slate-500">#{{ $a['codigo'] }}</span></td>
                            <td class="px-4 py-3 text-slate-500 whitespace-nowrap">{{ $a['data'] ? \Illuminate\Support\Carbon::parse($a['data'])->format('d/m/Y') : '—' }}</td>
                            <td class="px-4 py-3">
                                @if ($a['ausente'])
                                    @include('coordenador._situacao', ['situacao' => 'ausente'])
                                @elseif ($a['percentual'] === null)
                                    <span class="text-slate-500">—</span>
                                @else
                                    <div class="flex items-center gap-2 min-w-[150px]">
                                        <div class="h-2 w-20 rounded-full bg-slate-100 overflow-hidden" aria-hidden="true">
                                            <div class="h-full rounded-full {{ CorDesempenho::classeBg($a['percentual']) }}" style="width: {{ max(3, min(100, $a['percentual'])) }}%"></div>
                                        </div>
                                        <span class="font-bold {{ CorDesempenho::classeTextoLegivel($a['percentual']) }}">{{ $fmt($a['percentual']) }}%</span>
                                        <span class="text-xs text-slate-500">({{ $a['acertos'] }}/{{ $a['total'] }})</span>
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ $a['mediaCurso'] !== null ? $fmt($a['mediaCurso']).'%' : '—' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if ($a['diferenca'] === null)
                                    <span class="text-slate-500">—</span>
                                @else
                                    <span class="font-semibold {{ $a['diferenca'] >= 0 ? 'text-emerald-700' : 'text-amber-700' }}">{{ $a['diferenca'] > 0 ? '+' : '' }}{{ $fmt($a['diferenca']) }} pp</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-600 whitespace-nowrap">
                                {{ $a['posicao'] !== null ? $a['posicao'].'º de '.$a['presentesCurso'] : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if (! empty($cat['areas']))
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
                <h3 class="font-semibold mb-1">Desempenho por área</h3>
                <p class="text-sm text-slate-500 mb-4">% de acerto do aluno em cada área, da mais fraca para a mais forte. O traço escuro marca a média do curso na área.</p>
                <div class="grid gap-x-10 gap-y-3 sm:grid-cols-2">
                    @foreach ($cat['areas'] as $area)
                        <div class="min-w-0">
                            <div class="flex items-center justify-between gap-2 text-xs mb-1">
                                <span class="font-medium text-slate-600 truncate" title="{{ $area['area'] }}">{{ $area['area'] }} <span class="text-slate-500">({{ $area['respostas'] }} questões)</span></span>
                                <span class="font-bold {{ CorDesempenho::classeTextoLegivel($area['percentual']) }} shrink-0">{{ $fmt($area['percentual']) }}%@if ($area['curso'] !== null) <span class="font-normal text-slate-500">· curso {{ $fmt($area['curso']) }}%</span>@endif</span>
                            </div>
                            <div class="relative h-2 rounded-full bg-slate-100 overflow-hidden" aria-hidden="true">
                                <div class="h-full rounded-full {{ CorDesempenho::classeBg($area['percentual']) }}" style="width: {{ max(3, min(100, $area['percentual'])) }}%"></div>
                                @if ($area['curso'] !== null)
                                    <div class="absolute top-0 bottom-0 w-0.5 bg-slate-700" style="left: {{ min(99.5, max(0, $area['curso'])) }}%"></div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </section>
@empty
    <div class="bg-slate-50 border border-slate-200 text-slate-600 rounded-xl p-6 text-sm mb-6">
        Nenhuma avaliação registrada para este aluno {{ $semestre !== '' ? 'no período '.$semestre : 'neste curso' }}.
    </div>
@endforelse

{{-- Matrículas no curso --}}
@if (! empty($ficha['matriculas']))
    <section class="mb-6" aria-labelledby="titulo-matriculas">
        <h2 id="titulo-matriculas" class="font-bold mb-3 flex items-center gap-2"><i class="ph-bold ph-identification-card text-primary" aria-hidden="true"></i> Matrículas neste curso</h2>
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-x-auto">
            <table class="w-full text-sm">
                <caption class="sr-only">Histórico de matrículas do aluno nos seus cursos</caption>
                <thead class="bg-slate-50 text-slate-500 text-left">
                    <tr>
                        <th scope="col" class="px-4 py-3">Período letivo</th>
                        <th scope="col" class="px-4 py-3">Curso</th>
                        <th scope="col" class="px-4 py-3">Período do curso</th>
                        <th scope="col" class="px-4 py-3">Turma</th>
                        <th scope="col" class="px-4 py-3">Situação da matrícula</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($ficha['matriculas'] as $m)
                        <tr>
                            <td class="px-4 py-3 font-medium">{{ $m['periodoLetivo'] ?: '—' }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $m['curso'] }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $m['periodo'] ?: '—' }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $m['turma'] ?: '—' }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $m['status'] ? \Illuminate\Support\Str::title(mb_strtolower($m['status'])) : 'Ativa' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

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
                        labels: pontos.map(function (p) { return p.nome; }),
                        datasets: [
                            // Verde a partir de 60%, amarelo abaixo; degradê quando a linha cruza os 60%.
                            LinhaDesempenho.serie({ label: 'Aluno (%)', data: pontos.map(function (p) { return p.aluno; }), pointRadius: 5 }),
                            {
                                label: 'Média do curso (%)',
                                data: pontos.map(function (p) { return p.curso; }),
                                borderColor: '#2563eb',
                                backgroundColor: '#2563eb',
                                pointRadius: 3,
                                borderWidth: 1.5,
                                tension: 0.2,
                            },
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
                        // A cor da linha do aluno é o desempenho dele (legenda neutra); a média do curso mantém a própria cor.
                        plugins: { legend: { position: 'bottom', labels: LinhaDesempenho.legenda({
                            generateLabels: function (chart) {
                                return Chart.defaults.plugins.legend.labels.generateLabels(chart).map(function (item, i) {
                                    if (i === 0) { item.fillStyle = LinhaDesempenho.NEUTRO; item.strokeStyle = LinhaDesempenho.NEUTRO; }
                                    return item;
                                });
                            },
                        }) } },
                    },
                });
            });
        })();
    </script>
@endif
@endsection
