@extends('layouts.app')

@section('title', "Painel BI — Avaliação #{$avaliacao->codigo}")

@section('content')
<div class="flex items-start justify-between gap-4 mb-2">
    <a href="{{ route('avaliacoes.show', $avaliacao) }}" class="text-sm text-slate-500 hover:underline">&larr; Avaliacao #{{ $avaliacao->codigo }}</a>
    <a href="{{ route('avaliacoes.visualizacoes.edit', $avaliacao) }}" class="text-sm text-slate-500 hover:underline">Configurar visualizações &rarr;</a>
</div>
<h1 class="text-2xl font-bold mt-2 mb-6">Painel BI</h1>

<form method="GET" action="{{ route('avaliacoes.bi', $avaliacao) }}" class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 mb-6 flex gap-3">
    <select name="periodo" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <option value="">Todos os períodos</option>
        @foreach ($periodosDisponiveis as $p)
            <option value="{{ $p }}" {{ $periodo === $p ? 'selected' : '' }}>{{ $p === '' ? '(sem período)' : $p }}</option>
        @endforeach
    </select>
    <button type="submit" class="bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-lg px-4 py-2 text-sm">
        Filtrar
    </button>
</form>

@if (! empty($dados['semGabarito']))
    <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-xl p-6 text-sm mb-6">
        Esta avaliação ainda não tem gabarito cadastrado — importe ou cadastre as questões antes de ver o painel.
    </div>
@elseif (! empty($dados['semRespostas']))
    <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-xl p-6 text-sm mb-6">
        Nenhum resultado importado ainda para este filtro.
    </div>
@endif

@php
    $temAlgumVisivel = collect($estado)->contains(fn ($item) => $item['visivelAdmin']);
@endphp

@if (! $temAlgumVisivel)
    <div class="bg-slate-50 border border-slate-200 text-slate-500 rounded-xl p-6 text-sm">
        Nenhum visual está habilitado para o administrativo nesta avaliação.
        <a href="{{ route('avaliacoes.visualizacoes.edit', $avaliacao) }}" class="text-emerald-700 font-medium hover:underline">Configure aqui.</a>
    </div>
@endif

@if ($estado['histograma']['visivelAdmin'] || $estado['top5']['visivelAdmin'] || $estado['radar_disciplina']['visivelAdmin'])
    @if (empty($dados['semGabarito']) && empty($dados['semRespostas']) && ! empty($dados))
        <div class="grid lg:grid-cols-2 gap-6 mb-6">
            @if ($estado['histograma']['visivelAdmin'])
                <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
                    <h2 class="font-semibold mb-4">Distribuição de acertos ({{ $dados['totalRespondentes'] }} respondente(s))</h2>
                    <canvas id="grafico-histograma" height="220"></canvas>
                </div>
            @endif

            @if ($estado['radar_disciplina']['visivelAdmin'])
                <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
                    <h2 class="font-semibold mb-4">Desempenho médio por disciplina</h2>
                    @if (empty($dados['radar']))
                        <p class="text-sm text-slate-400">Nenhuma questão desta avaliação tem disciplina cadastrada na matriz.</p>
                    @else
                        <canvas id="grafico-radar" height="220"></canvas>
                    @endif
                </div>
            @endif
        </div>

        @if ($estado['top5']['visivelAdmin'])
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden mb-6">
                <div class="px-6 py-4 border-b border-slate-100 font-semibold">Top 5</div>
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-slate-500 text-left">
                        <tr>
                            <th class="px-4 py-3">RA</th>
                            <th class="px-4 py-3">CPF</th>
                            <th class="px-4 py-3">Período</th>
                            <th class="px-4 py-3">Acertos</th>
                            <th class="px-4 py-3">%</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($dados['top5'] as $r)
                            <tr>
                                <td class="px-4 py-3 font-medium">{{ $r['ra'] ?: '—' }}</td>
                                <td class="px-4 py-3">{{ $r['cpf'] ?: '—' }}</td>
                                <td class="px-4 py-3">{{ $r['periodo'] !== '' ? $r['periodo'] : '—' }}</td>
                                <td class="px-4 py-3">{{ $r['acertos'] }}/{{ $r['total'] }}</td>
                                <td class="px-4 py-3 font-bold text-emerald-700">{{ $r['percentual'] }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
@endif

@if ($estado['ranking_completo']['visivelAdmin'] && $rankingCompleto !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-slate-100 font-semibold">Ranking completo ({{ count($rankingCompleto) }} respondente(s))</div>
        <div class="max-h-96 overflow-y-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-left sticky top-0">
                    <tr>
                        <th class="px-4 py-3">#</th>
                        <th class="px-4 py-3">Aluno</th>
                        <th class="px-4 py-3">RA</th>
                        <th class="px-4 py-3">Turma</th>
                        <th class="px-4 py-3">Acertos</th>
                        <th class="px-4 py-3">%</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($rankingCompleto as $i => $r)
                        <tr>
                            <td class="px-4 py-3 text-slate-400">{{ $i + 1 }}</td>
                            <td class="px-4 py-3 font-medium">{{ $r['aluno_nome'] ?: '—' }}</td>
                            <td class="px-4 py-3">{{ $r['ra'] ?: '—' }}</td>
                            <td class="px-4 py-3">{{ $r['turma'] ?: '—' }}</td>
                            <td class="px-4 py-3">{{ $r['acertos'] }}/{{ $r['total'] }}</td>
                            <td class="px-4 py-3 font-bold text-emerald-700">{{ $r['percentual'] }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

@if ($estado['distribuicao_turma']['visivelAdmin'] && $distribuicaoTurma !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <h2 class="font-semibold mb-4">Distribuição de notas por turma</h2>
        @if (empty($distribuicaoTurma))
            <p class="text-sm text-slate-400">Sem dados suficientes.</p>
        @else
            <canvas id="grafico-turma" height="220"></canvas>
        @endif
    </div>
@endif

@if ($estado['curva_dificuldade']['visivelAdmin'] && $curvaDificuldade !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <h2 class="font-semibold mb-1">Dificuldade pedagógica: esperado x observado</h2>
        <p class="text-sm text-slate-500 mb-4">% de acerto observado por nível de dificuldade cadastrado nas questões.</p>
        @if (empty($curvaDificuldade))
            <p class="text-sm text-slate-400">Sem dados suficientes.</p>
        @else
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-left">
                    <tr><th class="px-4 py-2">Dificuldade esperada</th><th class="px-4 py-2">Questões</th><th class="px-4 py-2">% de acerto observado</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($curvaDificuldade as $linha)
                        <tr>
                            <td class="px-4 py-2">{{ $linha['esperado'] }}</td>
                            <td class="px-4 py-2">{{ $linha['questoes'] }}</td>
                            <td class="px-4 py-2 font-bold">{{ $linha['observado'] }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endif

@if ($estado['dispersao_tri']['visivelAdmin'] && $dispersaoTri !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <h2 class="font-semibold mb-1">Dispersão TRI x taxa de acerto observada</h2>
        <p class="text-sm text-slate-500 mb-4">Cada ponto é uma questão — eixo X: dificuldade TRI cadastrada, eixo Y: % de acerto observado.</p>
        @if (empty($dispersaoTri))
            <p class="text-sm text-slate-400">Sem dados suficientes.</p>
        @else
            <canvas id="grafico-tri" height="220"></canvas>
        @endif
    </div>
@endif

@if ($estado['heatmap_habilidade_turma']['visivelAdmin'] && $heatmapHabilidadeTurma !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6 overflow-x-auto">
        <h2 class="font-semibold mb-4">Mapa de calor: habilidade x turma (% de acerto)</h2>
        @if (empty($heatmapHabilidadeTurma))
            <p class="text-sm text-slate-400">Sem dados suficientes.</p>
        @else
            @php $todasTurmas = collect($heatmapHabilidadeTurma)->flatMap(fn ($t) => array_keys($t))->unique()->sort()->values(); @endphp
            <table class="text-sm border-collapse">
                <thead>
                    <tr>
                        <th class="px-3 py-2 text-left text-slate-500">Habilidade</th>
                        @foreach ($todasTurmas as $turma)
                            <th class="px-3 py-2 text-slate-500 whitespace-nowrap">{{ $turma }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($heatmapHabilidadeTurma as $habilidade => $porTurma)
                        <tr>
                            <td class="px-3 py-2 font-medium whitespace-nowrap">{{ $habilidade }}</td>
                            @foreach ($todasTurmas as $turma)
                                @php $valor = $porTurma[$turma] ?? null; @endphp
                                <td class="px-3 py-2 text-center text-white font-semibold"
                                    style="background-color: {{ $valor === null ? '#e2e8f0' : 'rgba(16,185,129,'.max(0.15, $valor / 100).')' }}; color: {{ $valor === null ? '#94a3b8' : '#0f172a' }}">
                                    {{ $valor !== null ? $valor.'%' : '—' }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endif

@if ($estado['perfil_demografico']['visivelAdmin'] && $perfilDemografico !== null)
    <div class="grid sm:grid-cols-3 gap-6 mb-6">
        @foreach ([['sexo', 'Sexo'], ['cor_raca', 'Cor/raça'], ['uf', 'UF']] as [$campo, $titulo])
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
                <h2 class="font-semibold mb-3">{{ $titulo }}</h2>
                @if (empty($perfilDemografico[$campo]))
                    <p class="text-sm text-slate-400">Sem dados.</p>
                @else
                    <ul class="text-sm space-y-1">
                        @foreach ($perfilDemografico[$campo] as $valor => $total)
                            <li class="flex justify-between"><span class="text-slate-600">{{ $valor }}</span><span class="font-bold">{{ $total }}</span></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>
@endif

@if (($estado['desempenho_bloom']['visivelAdmin'] || $estado['desempenho_miller']['visivelAdmin']) && ($mediaPorBloom !== null || $mediaPorMiller !== null))
    <div class="grid lg:grid-cols-2 gap-6 mb-6">
        @if ($estado['desempenho_bloom']['visivelAdmin'] && $mediaPorBloom !== null)
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
                <h2 class="font-semibold mb-4">Desempenho médio por nível de Bloom</h2>
                @if (empty($mediaPorBloom))
                    <p class="text-sm text-slate-400">Sem dados suficientes.</p>
                @else
                    <canvas id="grafico-bloom" height="220"></canvas>
                @endif
            </div>
        @endif
        @if ($estado['desempenho_miller']['visivelAdmin'] && $mediaPorMiller !== null)
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
                <h2 class="font-semibold mb-4">Desempenho médio por nível de Miller</h2>
                @if (empty($mediaPorMiller))
                    <p class="text-sm text-slate-400">Sem dados suficientes.</p>
                @else
                    <canvas id="grafico-miller" height="220"></canvas>
                @endif
            </div>
        @endif
    </div>
@endif

@if ($estado['analise_alternativas']['visivelAdmin'] && $analiseAlternativas !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6 overflow-x-auto">
        <h2 class="font-semibold mb-4">Análise de alternativas por questão</h2>
        @if (empty($analiseAlternativas))
            <p class="text-sm text-slate-400">Sem dados suficientes.</p>
        @else
            <table class="text-sm w-full">
                <thead class="bg-slate-50 text-slate-500 text-left"><tr><th class="px-3 py-2">Questão</th><th class="px-3 py-2">Alternativas marcadas</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($analiseAlternativas as $q)
                        <tr>
                            <td class="px-3 py-2 font-mono align-top">Q{{ $q['numero'] }}</td>
                            <td class="px-3 py-2">
                                @foreach ($q['alternativas'] as $alternativa => $total)
                                    <span class="inline-block mr-3 mb-1"><span class="font-bold">{{ $alternativa }}</span>: {{ $total }}</span>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endif

@if ($estado['correlacao_metricas']['visivelAdmin'] && $correlacaoMetricas !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <h2 class="font-semibold mb-1">Correlação entre nota total e métricas nomeadas</h2>
        <p class="text-sm text-slate-500 mb-4">Coeficiente de Pearson entre o percentual de acerto e cada métrica (ex.: nota de redação). Próximo de 1 ou -1 = correlação forte.</p>
        @if (empty($correlacaoMetricas))
            <p class="text-sm text-slate-400">Sem dados suficientes.</p>
        @else
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-left"><tr><th class="px-4 py-2">Métrica</th><th class="px-4 py-2">N</th><th class="px-4 py-2">Correlação</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($correlacaoMetricas as $m)
                        <tr>
                            <td class="px-4 py-2">{{ $m['nome_metrica'] }}</td>
                            <td class="px-4 py-2">{{ $m['n'] }}</td>
                            <td class="px-4 py-2 font-bold">{{ $m['correlacao'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endif

@if ($estado['evolucao_categoria']['visivelAdmin'] && $evolucaoCategoria !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <h2 class="font-semibold mb-4">Evolução da média da turma na categoria</h2>
        @if (count($evolucaoCategoria) < 2)
            <p class="text-sm text-slate-400">Sem dados suficientes.</p>
        @else
            <canvas id="grafico-evolucao" height="220"></canvas>
        @endif
    </div>
@endif

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
@if (! empty($dados) && empty($dados['semGabarito']) && empty($dados['semRespostas']))
    @if ($estado['histograma']['visivelAdmin'])
    new Chart(document.getElementById('grafico-histograma'), {
        type: 'bar',
        data: {
            labels: [@foreach($dados['histograma'] as $i => $c) '{{ $i * 10 }}-{{ $i * 10 + 9 }}%', @endforeach],
            datasets: [{ label: 'Respondentes', data: {{ Js::from($dados['histograma']) }}, backgroundColor: '#10b981' }],
        },
        options: { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { display: false } } },
    });
    @endif

    @if ($estado['radar_disciplina']['visivelAdmin'] && ! empty($dados['radar']))
    new Chart(document.getElementById('grafico-radar'), {
        type: 'radar',
        data: {
            labels: {{ Js::from(array_keys($dados['radar'])) }},
            datasets: [{ label: '% de acerto', data: {{ Js::from(array_values($dados['radar'])) }}, backgroundColor: 'rgba(16,185,129,0.2)', borderColor: '#10b981' }],
        },
        options: { scales: { r: { beginAtZero: true, max: 100 } } },
    });
    @endif
@endif

@if (! empty($distribuicaoTurma))
new Chart(document.getElementById('grafico-turma'), {
    type: 'bar',
    data: {
        labels: {{ Js::from(array_column($distribuicaoTurma, 'turma')) }},
        datasets: [{ label: 'Média (%)', data: {{ Js::from(array_column($distribuicaoTurma, 'media')) }}, backgroundColor: '#10b981' }],
    },
    options: { scales: { y: { beginAtZero: true, max: 100 } }, plugins: { legend: { display: false } } },
});
@endif

@if (! empty($dispersaoTri))
new Chart(document.getElementById('grafico-tri'), {
    type: 'scatter',
    data: {
        datasets: [{
            label: 'Questões',
            data: {{ Js::from(array_map(fn ($p) => ['x' => $p['dificuldade_tri'], 'y' => $p['taxa_acerto']], $dispersaoTri)) }},
            backgroundColor: '#10b981',
        }],
    },
    options: {
        scales: {
            x: { title: { display: true, text: 'Dificuldade TRI' } },
            y: { title: { display: true, text: '% de acerto observado' }, min: 0, max: 100 },
        },
    },
});
@endif

@if (! empty($mediaPorBloom))
new Chart(document.getElementById('grafico-bloom'), {
    type: 'bar',
    data: {
        labels: {{ Js::from(array_keys($mediaPorBloom)) }},
        datasets: [{ label: '% de acerto', data: {{ Js::from(array_values($mediaPorBloom)) }}, backgroundColor: '#10b981' }],
    },
    options: { indexAxis: 'y', scales: { x: { beginAtZero: true, max: 100 } }, plugins: { legend: { display: false } } },
});
@endif

@if (! empty($mediaPorMiller))
new Chart(document.getElementById('grafico-miller'), {
    type: 'bar',
    data: {
        labels: {{ Js::from(array_keys($mediaPorMiller)) }},
        datasets: [{ label: '% de acerto', data: {{ Js::from(array_values($mediaPorMiller)) }}, backgroundColor: '#10b981' }],
    },
    options: { indexAxis: 'y', scales: { x: { beginAtZero: true, max: 100 } }, plugins: { legend: { display: false } } },
});
@endif

@if (! empty($evolucaoCategoria) && count($evolucaoCategoria) >= 2)
new Chart(document.getElementById('grafico-evolucao'), {
    type: 'line',
    data: {
        labels: {{ Js::from(array_column($evolucaoCategoria, 'nome')) }},
        datasets: [{ label: 'Média (%)', data: {{ Js::from(array_column($evolucaoCategoria, 'media')) }}, borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.2)', tension: 0.2 }],
    },
    options: { scales: { y: { beginAtZero: true, max: 100 } } },
});
@endif
</script>
@endsection
