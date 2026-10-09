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
    $temAlgumPainel = ! empty($analise['dispersaoTri']) || ! empty($analise['coberturaHabilidade']) || ! empty($analise['miller']);

    // Uma barra por avaliação da categoria: acerto total x mínimo esperado (ver AnaliseConsolidadaService::metaPorPeriodo()).
    $meta = $analise['metaPeriodo'] ?? null;
    $avaliacoesMeta = $meta['avaliacoes'] ?? [];
@endphp

@if ($temEvolucao || $avaliacoesMeta !== [] || $temAlgumPainel)
    <div class="bg-slate-50 border border-slate-100 rounded-xl p-4 space-y-4">
        @if ($temEvolucao)
            <div>
                <div class="flex items-center justify-between gap-2 mb-3">
                    <p class="text-xs font-bold text-slate-500 uppercase tracking-wide flex items-center gap-1.5">
                        <i class="ph-bold ph-trend-up text-primary"></i> Evolução histórica nesta categoria
                    </p>
                    @include('portal._explicacao_visual', ['no' => $no, 'chave' => 'evolucaoHistorica'])
                </div>
                <canvas id="grafico-evolucao-{{ $idSufixo }}" height="90"></canvas>
            </div>
        @endif

        @if ($avaliacoesMeta !== [])
            <div class="bg-white border border-slate-200 rounded-lg p-3">
                <div class="flex items-center justify-between gap-2 mb-2">
                    <p class="text-xs font-bold text-slate-500 uppercase tracking-wide flex items-center gap-1.5">
                        <i class="ph-bold ph-target text-primary" aria-hidden="true"></i> Seu rendimento x mínimo esperado
                    </p>
                    @include('portal._explicacao_visual', ['no' => $no, 'chave' => 'metaPeriodo'])
                </div>
                <div class="relative" style="height: {{ max(96, count($avaliacoesMeta) * 48 + 36) }}px">
                    <canvas id="grafico-meta-{{ $idSufixo }}"
                            data-titulo="Seu acerto total em cada avaliação e o mínimo esperado, em percentual"></canvas>
                </div>
                @include('portal._legenda_barras_minimo')

                @if (! empty($analise['leituraRapida']))
                    <div class="mt-3 pt-3 border-t border-slate-100">
                        <p class="text-xs font-bold text-slate-500 uppercase tracking-wide flex items-center gap-1.5 mb-1.5">
                            <i class="ph-bold ph-lightbulb text-primary" aria-hidden="true"></i> Leitura rápida
                        </p>
                        <ul class="space-y-1 text-sm text-slate-700">
                            @foreach ($analise['leituraRapida'] as $frase)
                                <li>{{ $frase }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif
        @if ($temAlgumPainel)
            <div class="grid sm:grid-cols-2 gap-3">
                @if (! empty($analise['dispersaoTri']))
                    <div class="bg-white border border-slate-200 rounded-lg p-3">
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <p class="text-xs font-bold text-slate-500 uppercase tracking-wide flex items-center gap-1.5">
                                <i class="ph-bold ph-chart-scatter text-primary"></i> Dificuldade (TRI) x acerto
                            </p>
                            @include('portal._explicacao_visual', ['no' => $no, 'chave' => 'dispersaoTri'])
                        </div>
                        <canvas id="grafico-tri-{{ $idSufixo }}" height="90"></canvas>
                    </div>
                @endif

                @if (! empty($analise['coberturaHabilidade']))
                    <div class="bg-white border border-slate-200 rounded-lg p-3">
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <p class="text-xs font-bold text-slate-500 uppercase tracking-wide flex items-center gap-1.5">
                                <i class="ph-bold ph-target text-primary"></i> Habilidades a reforçar
                            </p>
                            @include('portal._explicacao_visual', ['no' => $no, 'chave' => 'coberturaHabilidade'])
                        </div>
                        <canvas id="grafico-habilidade-{{ $idSufixo }}" height="{{ max(90, count($analise['coberturaHabilidade']) * 24) }}"></canvas>
                    </div>
                @endif

                @if (! empty($analise['miller']))
                    <div class="bg-white border border-slate-200 rounded-lg p-3">
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <p class="text-xs font-bold text-slate-500 uppercase tracking-wide flex items-center gap-1.5">
                                <i class="ph-bold ph-stethoscope text-primary"></i> Nível de Miller
                            </p>
                            @include('portal._explicacao_visual', ['no' => $no, 'chave' => 'miller'])
                        </div>
                        <canvas id="grafico-miller-{{ $idSufixo }}" height="110"></canvas>
                    </div>
                @endif
            </div>
        @endif

        @if (! empty($analise['mapaDominio']))
            @php
                $mapa = $analise['mapaDominio'];
                // Verde = no mínimo esperado ou acima; amarelo = abaixo (o mínimo vem de AnaliseConsolidadaService::mapaDominio()).
                $verdeDominio = '#3fb99b';
                $amareloDominio = '#fbbf24';
            @endphp
            <div class="bg-white border border-slate-200 rounded-lg p-3">
                <div class="flex items-center justify-between gap-2 mb-2">
                    <p class="text-xs font-bold text-slate-500 uppercase tracking-wide flex items-center gap-1.5">
                        <i class="ph-bold ph-grid-nine text-primary"></i> Mapa de domínio por área
                    </p>
                    @include('portal._explicacao_visual', ['no' => $no, 'chave' => 'mapaDominio'])
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm border-separate" style="border-spacing: 2px; min-width: {{ 160 + count($mapa['avaliacoes']) * 86 }}px">
                        <thead>
                            <tr>
                                <th class="text-left text-[11px] font-medium text-slate-500 px-1 pb-1">Área</th>
                                @foreach ($mapa['avaliacoes'] as $av)
                                    <th class="text-[11px] font-medium text-slate-500 px-1 pb-1 text-center">
                                        {{ \Illuminate\Support\Str::limit($av['nome'] ?: 'Avaliação '.$av['codigo'], 14) }}
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($mapa['areas'] as $linha)
                                <tr>
                                    <th scope="row" class="text-left text-xs font-medium text-slate-700 pr-2 whitespace-nowrap">{{ $linha['area'] }}</th>
                                    @foreach ($mapa['avaliacoes'] as $av)
                                        @php
                                            $valor = $linha['valores'][$av['codigo']] ?? null;
                                            $minimo = $linha['esperados'][$av['codigo']] ?? null;
                                            $abaixo = $valor !== null && $minimo !== null && $valor < $minimo;
                                        @endphp
                                        <td class="text-center rounded-md py-1.5 tabular-nums"
                                            style="background-color: {{ $valor === null ? '#f1f5f9' : ($abaixo ? $amareloDominio : $verdeDominio) }}; color: {{ $valor === null ? '#64748b' : '#0f1720' }}"
                                            title="{{ $linha['area'] }} — {{ $av['nome'] ?: 'Avaliação '.$av['codigo'] }}: {{ $valor === null ? 'sem questão desta área' : $valor.'% de acerto — '.($abaixo ? 'abaixo' : 'no mínimo ou acima').' do mínimo esperado ('.$minimo.'%)' }}">
                                            @if ($valor === null)
                                                <span class="text-xs font-bold">—</span>
                                            @else
                                                <span class="block text-xs font-bold leading-tight">{{ round($valor) }}%</span>
                                                <span class="block text-[10px] leading-tight">mín. {{ round($minimo) }}%</span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2 text-[11px] text-slate-500">
                    <span class="inline-flex items-center gap-1.5"><span class="inline-block w-3 h-3 rounded-sm" style="background-color: {{ $verdeDominio }}"></span> no mínimo esperado ou acima</span>
                    <span class="inline-flex items-center gap-1.5"><span class="inline-block w-3 h-3 rounded-sm" style="background-color: {{ $amareloDominio }}"></span> abaixo do mínimo</span>
                    <span>mín. = mínimo esperado para o seu período (60% quando a prova não traz a meta)</span>
                    <span>— vazio: a avaliação não tinha questão dessa área</span>
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

        @if ($avaliacoesMeta !== [])
        Viz.barrasComMinimo(document.getElementById('grafico-meta-{{ $idSufixo }}'), {
            rotulos: {{ Js::from(array_column($avaliacoesMeta, 'nome')) }},
            acertos: {{ Js::from(array_column($avaliacoesMeta, 'percentual')) }},
            minimos: {{ Js::from(array_column($avaliacoesMeta, 'minimo')) }},
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
            options: {
                indexAxis: 'y',
                scales: {
                    x: { beginAtZero: true, max: 100 },
                    // Nomes de habilidade podem ser longos (ex.: "E3 —
                    // Avaliação e Julgamento Ético-Profissional") — sem
                    // truncar, o Chart.js não quebra a linha e o rótulo
                    // vaza pra fora do card, cortado pelo overflow. O
                    // texto completo continua acessível: passa inteiro em
                    // `labels` (só o tick exibido é encurtado), então o
                    // tooltip ao passar o mouse mostra o nome completo.
                    y: { ticks: { autoSkip: false, callback: function (valor) {
                        const rotulo = this.getLabelForValue(valor);
                        return rotulo.length > 26 ? rotulo.slice(0, 25) + '…' : rotulo;
                    } } },
                },
                plugins: { legend: { display: false } },
            },
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
