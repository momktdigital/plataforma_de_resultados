{{--
    Evolução histórica + análise consolidada de UMA categoria — anexada em
    $no['analise'] por PortalController::anexarAnaliseNaArvore(), escopada só
    às avaliações desse nó (nunca mistura com outra categoria). Incluída
    dentro de _categoria_no.blade.php, que já começa oculta (hidden) até o
    aluno expandir a pasta — ver portalToggleCategoria() em resultados.blade.php,
    que dá um resize() nos gráficos no momento em que a categoria abre.
--}}
@php
    $analise = $no['analise'];
    $idSufixo = $no['categoria']->id;
    $temEvolucao = count($analise['evolucaoHistorica']) >= 2;
    $temAlgumPainel = ! empty($analise['comparativoTurma']) || ! empty($analise['curvaDificuldade'])
        || ! empty($analise['dispersaoTri']) || ! empty($analise['coberturaHabilidade'])
        || ! empty($analise['bloom']) || ! empty($analise['miller']);
@endphp

@if ($temEvolucao || $temAlgumPainel || ! empty($analise['divergentes']))
    <div class="bg-slate-50 border border-slate-100 rounded-xl p-4 space-y-4">
        @if ($temEvolucao)
            <div>
                <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-3 flex items-center gap-1.5">
                    <i class="ph-bold ph-trend-up text-primary"></i> Evolução histórica nesta categoria
                </p>
                <canvas id="grafico-evolucao-{{ $idSufixo }}" height="90"></canvas>
            </div>
        @endif

        @if ($temAlgumPainel)
            <div class="grid sm:grid-cols-2 gap-3">
                @if (! empty($analise['comparativoTurma']))
                    <div class="bg-white border border-slate-200 rounded-lg p-3">
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-2 flex items-center gap-1.5">
                            <i class="ph-bold ph-users-three text-primary"></i> Você x turma {{ $analise['comparativoTurma']['turma'] }}
                        </p>
                        <canvas id="grafico-turma-{{ $idSufixo }}" height="90"></canvas>
                        <p class="text-[11px] text-slate-400 mt-2">Média de {{ $analise['comparativoTurma']['avaliacoesComparadas'] }} avaliação(ões) comparável(eis)</p>
                    </div>
                @endif

                @if (! empty($analise['curvaDificuldade']))
                    @php
                        $facilPct = $analise['curvaDificuldade']['facil']['percentual'] ?? null;
                        $dificilPct = $analise['curvaDificuldade']['dificil']['percentual'] ?? null;
                    @endphp
                    <div class="bg-white border border-slate-200 rounded-lg p-3">
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-2 flex items-center gap-1.5">
                            <i class="ph-bold ph-gauge text-primary"></i> Dificuldade pedagógica
                        </p>
                        <canvas id="grafico-dificuldade-{{ $idSufixo }}" height="90"></canvas>
                        @if ($facilPct !== null && $dificilPct !== null && $facilPct < $dificilPct)
                            <p class="text-[11px] text-amber-600 mt-2 flex items-center gap-1">
                                <i class="ph-bold ph-warning-circle"></i> Acerto em fáceis menor que em difíceis.
                            </p>
                        @endif
                    </div>
                @endif

                @if (! empty($analise['dispersaoTri']))
                    <div class="bg-white border border-slate-200 rounded-lg p-3">
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-2 flex items-center gap-1.5">
                            <i class="ph-bold ph-chart-scatter text-primary"></i> Dificuldade (TRI) x acerto
                        </p>
                        <canvas id="grafico-tri-{{ $idSufixo }}" height="90"></canvas>
                    </div>
                @endif

                @if (! empty($analise['coberturaHabilidade']))
                    <div class="bg-white border border-slate-200 rounded-lg p-3">
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-2 flex items-center gap-1.5">
                            <i class="ph-bold ph-target text-primary"></i> Habilidades a reforçar
                        </p>
                        <canvas id="grafico-habilidade-{{ $idSufixo }}" height="{{ max(90, count($analise['coberturaHabilidade']) * 24) }}"></canvas>
                    </div>
                @endif

                @if (! empty($analise['bloom']))
                    <div class="bg-white border border-slate-200 rounded-lg p-3">
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-2 flex items-center gap-1.5">
                            <i class="ph-bold ph-brain text-primary"></i> Nível de Bloom
                        </p>
                        <canvas id="grafico-bloom-{{ $idSufixo }}" height="110"></canvas>
                    </div>
                @endif

                @if (! empty($analise['miller']))
                    <div class="bg-white border border-slate-200 rounded-lg p-3">
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-2 flex items-center gap-1.5">
                            <i class="ph-bold ph-stethoscope text-primary"></i> Nível de Miller
                        </p>
                        <canvas id="grafico-miller-{{ $idSufixo }}" height="110"></canvas>
                    </div>
                @endif
            </div>
        @endif

        @if (! empty($analise['divergentes']))
            <div class="bg-white border border-slate-200 rounded-lg p-3">
                <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-2 flex items-center gap-1.5">
                    <i class="ph-bold ph-warning-circle text-primary"></i> Temas onde você mais diverge da turma
                </p>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-slate-500 text-left">
                            <tr><th class="px-3 py-2">Área</th><th class="px-3 py-2">Tema</th><th class="px-3 py-2">Vezes que errou</th><th class="px-3 py-2">% turma acertou</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($analise['divergentes'] as $d)
                                <tr>
                                    <td class="px-3 py-2">{{ $d['area'] }}</td>
                                    <td class="px-3 py-2">{{ $d['tema'] }}</td>
                                    <td class="px-3 py-2 font-bold text-red-600">{{ $d['ocorrencias'] }}</td>
                                    <td class="px-3 py-2">{{ $d['taxaAcertoTurmaMedia'] }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>

    <script>
    (function () {
        if (typeof Chart === 'undefined') return;

        @if ($temEvolucao)
        new Chart(document.getElementById('grafico-evolucao-{{ $idSufixo }}'), {
            type: 'line',
            data: {
                labels: {{ Js::from(array_map(fn ($p) => "{$p['nome']} — {$p['data']}", $analise['evolucaoHistorica'])) }},
                datasets: [{
                    label: '% de acerto',
                    data: {{ Js::from(array_column($analise['evolucaoHistorica'], 'percentual')) }},
                    borderColor: '#00b48d',
                    backgroundColor: 'rgba(0,180,141,0.12)',
                    borderWidth: 2,
                    pointRadius: 3,
                    pointBackgroundColor: '#00b48d',
                    fill: true,
                    tension: 0.3,
                }],
            },
            options: {
                scales: { y: { beginAtZero: true, max: 100, grid: { color: '#f1f5f9' } }, x: { grid: { display: false } } },
                plugins: { legend: { display: false } },
            },
        });
        @endif

        @if (! empty($analise['comparativoTurma']))
        new Chart(document.getElementById('grafico-turma-{{ $idSufixo }}'), {
            type: 'bar',
            data: {
                labels: ['Você', 'Média da turma'],
                datasets: [{
                    data: [{{ $analise['comparativoTurma']['suaMedia'] }}, {{ $analise['comparativoTurma']['mediaTurma'] }}],
                    backgroundColor: ['#00b48d', '#94a3b8'],
                    borderRadius: 4,
                    maxBarThickness: 24,
                }],
            },
            options: {
                indexAxis: 'y',
                scales: { x: { beginAtZero: true, max: 100, grid: { color: '#f1f5f9' } }, y: { grid: { display: false } } },
                plugins: { legend: { display: false } },
            },
        });
        @endif

        @if (! empty($analise['curvaDificuldade']))
        new Chart(document.getElementById('grafico-dificuldade-{{ $idSufixo }}'), {
            type: 'bar',
            data: {
                labels: {{ Js::from(array_column($analise['curvaDificuldade'], 'label')) }},
                datasets: [{ label: '% de acerto', data: {{ Js::from(array_column($analise['curvaDificuldade'], 'percentual')) }}, backgroundColor: '#00b48d', borderRadius: 4, maxBarThickness: 24 }],
            },
            options: { scales: { y: { beginAtZero: true, max: 100 } }, plugins: { legend: { display: false } } },
        });
        @endif

        @if (! empty($analise['dispersaoTri']))
        new Chart(document.getElementById('grafico-tri-{{ $idSufixo }}'), {
            type: 'scatter',
            data: {
                datasets: [
                    {
                        label: 'Acertou',
                        data: {{ Js::from(collect($analise['dispersaoTri'])->filter(fn ($p) => $p['acertou'])->map(fn ($p) => ['x' => $p['dificuldade_tri'], 'y' => 1])->values()) }},
                        backgroundColor: '#00b48d',
                    },
                    {
                        label: 'Errou',
                        data: {{ Js::from(collect($analise['dispersaoTri'])->filter(fn ($p) => ! $p['acertou'])->map(fn ($p) => ['x' => $p['dificuldade_tri'], 'y' => 0])->values()) }},
                        backgroundColor: '#ef4444',
                    },
                ],
            },
            options: {
                scales: {
                    y: { min: -0.5, max: 1.5, ticks: { stepSize: 1, callback: function (v) { return v === 1 ? 'Acertou' : (v === 0 ? 'Errou' : ''); } } },
                    x: { title: { display: true, text: 'Dificuldade TRI' } },
                },
            },
        });
        @endif

        @if (! empty($analise['coberturaHabilidade']))
        new Chart(document.getElementById('grafico-habilidade-{{ $idSufixo }}'), {
            type: 'bar',
            data: {
                labels: {{ Js::from(array_keys($analise['coberturaHabilidade'])) }},
                datasets: [{ label: '% de acerto', data: {{ Js::from(array_values($analise['coberturaHabilidade'])) }}, backgroundColor: '#00b48d', borderRadius: 4, maxBarThickness: 18 }],
            },
            options: { indexAxis: 'y', scales: { x: { beginAtZero: true, max: 100 } }, plugins: { legend: { display: false } } },
        });
        @endif

        @if (! empty($analise['bloom']))
        new Chart(document.getElementById('grafico-bloom-{{ $idSufixo }}'), {
            type: 'bar',
            data: {
                labels: {{ Js::from(array_keys($analise['bloom'])) }},
                datasets: [{ label: '% de acerto', data: {{ Js::from(array_values($analise['bloom'])) }}, backgroundColor: '#00b48d', borderRadius: 4, maxBarThickness: 20 }],
            },
            options: { indexAxis: 'y', scales: { x: { beginAtZero: true, max: 100 } }, plugins: { legend: { display: false } } },
        });
        @endif

        @if (! empty($analise['miller']))
        new Chart(document.getElementById('grafico-miller-{{ $idSufixo }}'), {
            type: 'bar',
            data: {
                labels: {{ Js::from(array_keys($analise['miller'])) }},
                datasets: [{ label: '% de acerto', data: {{ Js::from(array_values($analise['miller'])) }}, backgroundColor: '#00b48d', borderRadius: 4, maxBarThickness: 20 }],
            },
            options: { indexAxis: 'y', scales: { x: { beginAtZero: true, max: 100 } }, plugins: { legend: { display: false } } },
        });
        @endif
    })();
    </script>
@endif
