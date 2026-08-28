{{--
    Detalhe completo de uma única avaliação (gabarito, métricas) — aberto a
    partir do card resumido da tela de Resultados, na mesma aba, deixando
    espaço pra outras análises que serão adicionadas aqui no futuro.
--}}
@extends('layouts.portal')

@php
    $nomeAvaliacao = $r['avaliacao']->nome ?? "Avaliação #{$r['avaliacao']->codigo}";
    $siteTitle = \App\Models\Configuracao::valor('site_title', 'Resultados DI');
    $paramsVoltar = request()->has('periodo_letivo') ? ['periodo_letivo' => request('periodo_letivo')] : [];
@endphp

@section('title', "{$nomeAvaliacao} — {$aluno->ra}")
@section('container-class', 'max-w-5xl')

@section('content')
<div class="flex flex-col sm:flex-row sm:items-center justify-between mb-6 gap-4 fade-in">
    <a href="{{ route('portal.resultados', $paramsVoltar) }}" class="inline-flex items-center text-sm font-medium text-slate-500 hover:text-primary transition-colors bg-white px-4 py-2 rounded-full shadow-sm border border-slate-200">
        <i class="ph-bold ph-arrow-left mr-2"></i> Voltar aos resultados
    </a>
    <button type="button" onclick="portalExportarPdfAvaliacao()" class="btn-pdf-avaliacao
            inline-flex items-center justify-center bg-slate-800 hover:bg-slate-700 text-white rounded-lg px-4 py-2 text-sm font-medium">
        <i class="ph-bold ph-file-pdf mr-2 text-red-400"></i> Baixar PDF desta avaliação
    </button>
</div>

<div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 fade-in">
    <div id="pdf-conteudo" data-avaliacao-nome="{{ $nomeAvaliacao }}">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                    <i class="ph-fill ph-exam text-primary"></i> {{ $nomeAvaliacao }}
                </h1>
                <p class="text-sm text-slate-500 mt-0.5">
                    RA: <span class="font-bold text-slate-700">{{ $aluno->ra }}</span> — {{ $aluno->nome }}<br>
                    @if ($r['avaliacao']->data_avaliacao)
                        {{ $r['avaliacao']->data_avaliacao->format('d/m/Y') }} &middot;
                    @endif
                    Período: {{ $r['periodo'] !== '' ? $r['periodo'] : '—' }}
                </p>
            </div>
            @if ($estado['nota_geral']['visivelAluno'] && $r['total'] > 0)
                <div class="flex items-center gap-3 shrink-0">
                    <div class="text-right hidden sm:block">
                        <div class="text-xs text-slate-500 font-medium">{{ $r['acertos'] }}/{{ $r['total'] }}</div>
                        <div class="text-xs text-slate-500 font-medium">acertos</div>
                    </div>
                    @include('portal._anel_progresso', ['percentual' => $r['percentual'], 'tamanho' => 84, 'espessura' => 9, 'tamanhoTexto' => 'text-lg'])
                </div>
            @endif
        </div>

        @if ($r['avaliacao']->link_comentado)
            <a href="{{ $r['avaliacao']->link_comentado }}" target="_blank" rel="noopener"
               class="inline-flex items-center mb-4 text-sm font-medium text-primary hover:underline">
                <i class="ph-bold ph-link mr-1.5"></i> Acessar gabarito comentado
            </a>
        @endif

        @php
            $metricaTotal = $estado['metricas_nomeadas']['visivelAluno']
                ? $r['metricas']->first(fn ($m) => mb_strtolower(trim($m->nome_metrica), 'UTF-8') === 'total')
                : null;
            $outrasMetricas = $estado['metricas_nomeadas']['visivelAluno']
                ? ($metricaTotal ? $r['metricas']->reject(fn ($m) => $m->is($metricaTotal)) : $r['metricas'])
                : collect();
        @endphp

        @if ($metricaTotal)
            <div class="mb-4 bg-gradient-to-br from-primary/10 to-primary/5 border-2 border-primary/30 rounded-xl p-5 flex items-center justify-between gap-4">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-11 h-11 rounded-lg bg-primary/15 flex items-center justify-center shrink-0">
                        <i class="ph-fill ph-trophy text-primary text-2xl"></i>
                    </div>
                    <div class="text-sm font-bold text-primary uppercase tracking-wide truncate">{{ $metricaTotal->nome_metrica }}</div>
                </div>
                <div class="text-3xl font-black text-primary shrink-0">{{ $metricaTotal->valor }}</div>
            </div>
        @endif

        @if ($outrasMetricas->isNotEmpty())
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
                @foreach ($outrasMetricas as $metrica)
                    <div class="bg-slate-50 border border-slate-100 rounded-lg p-3">
                        <div class="text-xs font-bold text-slate-500 uppercase truncate" title="{{ $metrica->nome_metrica }}">{{ $metrica->nome_metrica }}</div>
                        <div class="text-lg font-black text-slate-800">{{ $metrica->valor }}</div>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($estado['desempenho_area']['visivelAluno'] && ! empty($desempenhoAreaContagem))
            <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-3 flex items-center gap-1.5">
                <i class="ph-bold ph-chart-bar text-primary"></i> Total por área
            </p>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
                @foreach (collect($desempenhoAreaContagem)->sortByDesc(fn ($d) => $d['percentual']) as $area => $dados)
                    <div class="bg-slate-50 border border-slate-100 rounded-lg p-3">
                        <div class="text-xs font-bold text-slate-500 uppercase truncate" title="{{ $area }}">{{ $area }}</div>
                        <div class="text-lg font-black text-slate-800">
                            {{ $dados['acertos'] }}<span class="text-xs font-normal text-slate-400"> ({{ $dados['percentual'] }}%)</span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($estado['grade_questoes']['visivelAluno'])
            @php
                $areasDetalhe = $r['questoesMeta']->pluck('area')->filter()->unique()->sort()->values();
                $temasDetalhe = $r['questoesMeta']->pluck('tema')->filter()->unique()->sort()->values();
            @endphp
            <div class="flex items-center justify-between flex-wrap gap-3 mb-3">
                <p class="text-xs font-bold text-slate-400 uppercase tracking-wide flex items-center gap-1.5">
                    <i class="ph-bold ph-squares-four text-primary"></i> Detalhamento das respostas
                </p>
                @if ($areasDetalhe->isNotEmpty() || $temasDetalhe->isNotEmpty())
                    <div class="flex flex-wrap gap-2">
                        @if ($areasDetalhe->isNotEmpty())
                            <select id="filtro-detalhe-area" onchange="portalAreaFiltroMudou()" class="text-xs rounded-lg border border-slate-300 px-2 py-1.5">
                                <option value="">Todas as áreas</option>
                                @foreach ($areasDetalhe as $area)
                                    <option value="{{ $area }}">{{ $area }}</option>
                                @endforeach
                            </select>
                        @endif
                        @if ($temasDetalhe->isNotEmpty())
                            <select id="filtro-detalhe-tema" onchange="portalFiltrarDetalheQuestoes()" class="text-xs rounded-lg border border-slate-300 px-2 py-1.5">
                                <option value="">Todos os temas</option>
                                @foreach ($temasDetalhe as $tema)
                                    <option value="{{ $tema }}">{{ $tema }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                @endif
            </div>
            <div class="grid grid-cols-5 sm:grid-cols-8 md:grid-cols-10 gap-2 mb-6">
                @foreach ($r['respostas'] as $resposta)
                    @php
                        $anuladaModo = $r['anuladas'][$resposta->questao_numero] ?? null;
                        $correta = $r['gabaritos'][$resposta->questao_numero] ?? null;
                        $marcada = $resposta->resposta ?: '';
                        $meta = $r['questoesMeta'][$resposta->questao_numero] ?? null;
                        $cor = 'bg-slate-400';
                        $statusIcone = null; // sinal além da cor, pra quem tem daltonismo
                        if ($correta !== null && $correta !== '') {
                            $acertou = \App\Support\Anulacao::acertou($marcada, $correta, $anuladaModo);
                            if ($acertou) {
                                $cor = 'bg-green-500';
                                $statusIcone = 'ph-check';
                            } elseif ($marcada !== '') {
                                $cor = 'bg-red-500';
                                $statusIcone = 'ph-x';
                            }
                        }
                    @endphp
                    <button type="button" onclick="portalAbrirDetalheQuestao({{ $resposta->questao_numero }})"
                            data-area="{{ $meta['area'] ?? '' }}" data-tema="{{ $meta['tema'] ?? '' }}"
                            class="detalhe-questao-item rounded-lg overflow-hidden border border-slate-200 shadow-sm text-left cursor-pointer hover:shadow-md hover:-translate-y-0.5 transition-all"
                            @if ($anuladaModo) title="Questão anulada — não conta na nota" @endif>
                        <div class="{{ $cor }} text-white text-[10px] text-center font-bold py-1 flex items-center justify-center gap-0.5">
                            <span>Q{{ $resposta->questao_numero }}{{ $anuladaModo ? '*' : '' }}</span>
                            @if ($statusIcone)
                                <i class="ph-bold {{ $statusIcone }}" aria-hidden="true"></i>
                            @endif
                        </div>
                        <div class="bg-white text-center font-bold text-sm py-1.5 {{ $marcada === '' ? 'text-slate-300' : 'text-slate-700' }}">
                            {{ $marcada !== '' ? $marcada : '-' }}
                        </div>
                    </button>
                @endforeach
            </div>
            @if ($r['anuladas']->isNotEmpty())
                <p class="text-xs text-slate-400 -mt-4 mb-6">* Questão anulada — não conta na nota.</p>
            @endif
        @endif

        @php
            $temComparativoTurma = $estado['comparativo_turma']['visivelAluno'] && $comparativoTurma;
            $temRankingPercentil = $estado['ranking_percentil']['visivelAluno'] && $rankingPercentil;
            $temRadar = $estado['radar_disciplina']['visivelAluno'] && ! empty($radarDisciplina);
            $temArea = $estado['desempenho_area']['visivelAluno'] && ! empty($desempenhoArea);
            $temBloom = $estado['desempenho_bloom']['visivelAluno'] && ! empty($desempenhoBloom);
            $temMiller = $estado['desempenho_miller']['visivelAluno'] && ! empty($desempenhoMiller);
            $temEvolucao = $estado['evolucao_categoria']['visivelAluno'] && ! empty($evolucaoHistorica) && count($evolucaoHistorica) >= 2;
            $temAlgumPainel = $temComparativoTurma || $temRankingPercentil || $temRadar || $temArea || $temBloom || $temMiller || $temEvolucao;
            $temLacunasConsolidados = $estado['lacunas_conhecimentos']['visivelAluno']
                && ! empty($lacunasConsolidados)
                && (! empty($lacunasConsolidados['lacunas']) || ! empty($lacunasConsolidados['consolidados']));
        @endphp

        @if ($temAlgumPainel)
            <div class="grid sm:grid-cols-2 gap-4 mb-6">
                @if ($temComparativoTurma)
                    <div class="bg-slate-50 border border-slate-100 rounded-xl p-4">
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-3 flex items-center gap-1.5">
                            <i class="ph-bold ph-users-three text-primary"></i> Comparativo com a turma {{ $comparativoTurma['turma'] }}
                        </p>
                        <canvas id="grafico-comparativo-turma" height="110"></canvas>
                        <p class="text-[11px] text-slate-400 mt-2">{{ $comparativoTurma['respondentesTurma'] }} respondente(s) na turma</p>
                    </div>
                @endif

                @if ($temRankingPercentil)
                    <div class="bg-slate-50 border border-slate-100 rounded-xl p-4 flex items-center gap-4">
                        <div class="w-14 h-14 rounded-full bg-primary/15 flex items-center justify-center shrink-0">
                            <i class="ph-fill ph-medal text-primary text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-1">Posição relativa</p>
                            <p class="text-sm text-slate-600">
                                Você está entre os <span class="font-black text-primary text-base">top {{ round(100 - $rankingPercentil['percentil']) }}%</span>
                                — posição {{ $rankingPercentil['posicao'] }} de {{ $rankingPercentil['totalRespondentes'] }}.
                            </p>
                        </div>
                    </div>
                @endif

                @if ($temRadar)
                    <div class="bg-slate-50 border border-slate-100 rounded-xl p-4">
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-3 flex items-center gap-1.5">
                            <i class="ph-bold ph-chart-polar text-primary"></i> Desempenho por disciplina
                        </p>
                        <canvas id="grafico-radar-disciplina" height="200"></canvas>
                    </div>
                @endif

                @if ($temArea)
                    <div class="bg-slate-50 border border-slate-100 rounded-xl p-4">
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-3 flex items-center gap-1.5">
                            <i class="ph-bold ph-chart-polar text-primary"></i> Desempenho por área
                        </p>
                        <canvas id="grafico-area" height="200"></canvas>
                    </div>
                @endif

                @if ($temBloom)
                    <div class="bg-slate-50 border border-slate-100 rounded-xl p-4">
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-3 flex items-center gap-1.5">
                            <i class="ph-bold ph-brain text-primary"></i> Desempenho por nível de Bloom
                        </p>
                        <canvas id="grafico-bloom" height="180"></canvas>
                    </div>
                @endif

                @if ($temMiller)
                    <div class="bg-slate-50 border border-slate-100 rounded-xl p-4">
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-3 flex items-center gap-1.5">
                            <i class="ph-bold ph-stethoscope text-primary"></i> Desempenho por nível de Miller
                        </p>
                        <canvas id="grafico-miller" height="180"></canvas>
                    </div>
                @endif

                @if ($temEvolucao)
                    <div class="bg-slate-50 border border-slate-100 rounded-xl p-4">
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-3 flex items-center gap-1.5">
                            <i class="ph-bold ph-trend-up text-primary"></i> Evolução histórica na categoria
                        </p>
                        <canvas id="grafico-evolucao" height="180"></canvas>
                    </div>
                @endif
            </div>
        @endif

        @if ($temLacunasConsolidados)
            <div class="grid sm:grid-cols-2 gap-4 mb-6">
                @if (! empty($lacunasConsolidados['lacunas']))
                    <div class="bg-red-50 border border-red-100 rounded-xl p-4">
                        <p class="text-xs font-bold text-red-600 uppercase tracking-wide mb-3 flex items-center gap-1.5">
                            <i class="ph-bold ph-warning-circle"></i> Lacunas de aprendizagem
                        </p>
                        <ul class="space-y-3">
                            @foreach ($lacunasConsolidados['lacunas'] as $card)
                                <li>
                                    <p class="text-sm font-bold text-slate-700">{{ $card['area'] }}</p>
                                    <p class="text-xs text-slate-600 mt-0.5">{{ $card['texto'] }}</p>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (! empty($lacunasConsolidados['consolidados']))
                    <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-4">
                        <p class="text-xs font-bold text-emerald-700 uppercase tracking-wide mb-3 flex items-center gap-1.5">
                            <i class="ph-bold ph-check-circle"></i> Conhecimentos consolidados
                        </p>
                        <ul class="space-y-3">
                            @foreach ($lacunasConsolidados['consolidados'] as $card)
                                <li>
                                    <p class="text-sm font-bold text-slate-700">{{ $card['area'] }}</p>
                                    <p class="text-xs text-slate-600 mt-0.5">{{ $card['texto'] }}</p>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif

        @if ($estado['comparativo_questao']['visivelAluno'] && ! empty($comparativoQuestao))
            <div>
                <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-3 flex items-center gap-1.5">
                    <i class="ph-bold ph-table text-primary"></i> Sua resposta x turma, por questão
                </p>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-slate-500 text-left">
                            <tr><th class="px-3 py-2">Questão</th><th class="px-3 py-2">Sua resposta</th><th class="px-3 py-2">Gabarito</th><th class="px-3 py-2">% turma acertou</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($comparativoQuestao as $q)
                                <tr @if ($q['anulada']) title="Questão anulada — não conta na nota" @endif>
                                    <td class="px-3 py-2 font-mono">Q{{ $q['numero'] }}{{ $q['anulada'] ? '*' : '' }}</td>
                                    <td class="px-3 py-2 {{ $q['acertou'] ? 'text-green-600 font-bold' : 'text-red-600 font-bold' }}">{{ $q['sua_resposta'] ?: '—' }}</td>
                                    <td class="px-3 py-2">{{ $q['gabarito'] }}</td>
                                    <td class="px-3 py-2">{{ $q['taxa_acerto_turma'] }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</div>

<div id="modal-detalhe-questao" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4"
     role="dialog" aria-modal="true" aria-labelledby="modal-detalhe-titulo"
     onclick="if (event.target === this) portalFecharDetalheQuestao()">
    <div class="bg-white rounded-2xl shadow-xl max-w-sm w-full p-5">
        <div class="flex items-center justify-between mb-4">
            <h3 id="modal-detalhe-titulo" class="font-bold text-slate-800"></h3>
            <button type="button" id="modal-detalhe-fechar" onclick="portalFecharDetalheQuestao()" aria-label="Fechar" class="text-slate-400 hover:text-slate-600">
                <i class="ph-bold ph-x text-lg"></i>
            </button>
        </div>
        <div id="modal-detalhe-anulada" class="hidden text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mb-3">
            Questão anulada — não conta na nota.
        </div>
        <dl class="space-y-2 text-sm">
            <div id="modal-detalhe-area-linha" class="hidden flex justify-between gap-3">
                <dt class="text-slate-500">Área</dt>
                <dd id="modal-detalhe-area" class="font-medium text-slate-700 text-right"></dd>
            </div>
            <div id="modal-detalhe-tema-linha" class="hidden flex justify-between gap-3">
                <dt class="text-slate-500">Tema</dt>
                <dd id="modal-detalhe-tema" class="font-medium text-slate-700 text-right"></dd>
            </div>
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500">Sua resposta</dt>
                <dd id="modal-detalhe-sua-resposta" class="font-bold text-right"></dd>
            </div>
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500">Resposta correta</dt>
                <dd id="modal-detalhe-gabarito" class="font-bold text-emerald-700 text-right"></dd>
            </div>
        </dl>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1"></script>
<script>
@if ($estado['comparativo_turma']['visivelAluno'] && $comparativoTurma)
new Chart(document.getElementById('grafico-comparativo-turma'), {
    type: 'bar',
    data: {
        labels: ['Você', 'Média da turma'],
        datasets: [{
            data: [{{ $comparativoTurma['suaMedia'] }}, {{ $comparativoTurma['mediaTurma'] }}],
            backgroundColor: ['#00b48d', '#94a3b8'],
            borderRadius: 4,
            maxBarThickness: 28,
        }],
    },
    options: {
        indexAxis: 'y',
        scales: { x: { beginAtZero: true, max: 100, grid: { color: '#f1f5f9' } }, y: { grid: { display: false } } },
        plugins: { legend: { display: false } },
    },
});
@endif

@if ($estado['radar_disciplina']['visivelAluno'] && ! empty($radarDisciplina))
new Chart(document.getElementById('grafico-radar-disciplina'), {
    type: 'radar',
    data: {
        labels: {{ Js::from(array_keys($radarDisciplina)) }},
        datasets: [{ label: '% de acerto', data: {{ Js::from(array_values($radarDisciplina)) }}, backgroundColor: 'rgba(0,180,141,0.2)', borderColor: '#00b48d' }],
    },
    options: { scales: { r: { beginAtZero: true, max: 100 } } },
});
@endif

@if ($estado['desempenho_area']['visivelAluno'] && ! empty($desempenhoArea))
new Chart(document.getElementById('grafico-area'), {
    type: 'radar',
    data: {
        labels: {{ Js::from(array_keys($desempenhoArea)) }},
        datasets: [{ label: '% de acerto', data: {{ Js::from(array_values($desempenhoArea)) }}, backgroundColor: 'rgba(0,180,141,0.2)', borderColor: '#00b48d' }],
    },
    options: { scales: { r: { beginAtZero: true, max: 100 } } },
});
@endif

@if ($estado['desempenho_bloom']['visivelAluno'] && ! empty($desempenhoBloom))
new Chart(document.getElementById('grafico-bloom'), {
    type: 'bar',
    data: {
        labels: {{ Js::from(array_keys($desempenhoBloom)) }},
        datasets: [{ label: '% de acerto', data: {{ Js::from(array_values($desempenhoBloom)) }}, backgroundColor: '#00b48d', borderRadius: 4, maxBarThickness: 24 }],
    },
    options: { indexAxis: 'y', scales: { x: { beginAtZero: true, max: 100 } }, plugins: { legend: { display: false } } },
});
@endif

@if ($estado['desempenho_miller']['visivelAluno'] && ! empty($desempenhoMiller))
new Chart(document.getElementById('grafico-miller'), {
    type: 'bar',
    data: {
        labels: {{ Js::from(array_keys($desempenhoMiller)) }},
        datasets: [{ label: '% de acerto', data: {{ Js::from(array_values($desempenhoMiller)) }}, backgroundColor: '#00b48d', borderRadius: 4, maxBarThickness: 24 }],
    },
    options: { indexAxis: 'y', scales: { x: { beginAtZero: true, max: 100 } }, plugins: { legend: { display: false } } },
});
@endif

@if ($estado['evolucao_categoria']['visivelAluno'] && ! empty($evolucaoHistorica) && count($evolucaoHistorica) >= 2)
new Chart(document.getElementById('grafico-evolucao'), {
    type: 'line',
    data: {
        labels: {{ Js::from(array_column($evolucaoHistorica, 'nome')) }},
        datasets: [{ label: '% de acerto', data: {{ Js::from(array_column($evolucaoHistorica, 'percentual')) }}, borderColor: '#00b48d', backgroundColor: 'rgba(0,180,141,0.2)', tension: 0.2 }],
    },
    options: { scales: { y: { beginAtZero: true, max: 100 } } },
});
@endif
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
const PORTAL_SITE_TITLE = @json($siteTitle);
const PORTAL_ALUNO = {nome: @json($aluno->nome), ra: @json($aluno->ra)};
const PORTAL_QUESTOES = {{ Js::from(
    $r['respostas']->mapWithKeys(function ($resposta) use ($r) {
        $meta = $r['questoesMeta'][$resposta->questao_numero] ?? null;

        return [$resposta->questao_numero => [
            'area' => $meta['area'] ?? null,
            'tema' => $meta['tema'] ?? null,
            'suaResposta' => $resposta->resposta ?: null,
            'gabarito' => $r['gabaritos'][$resposta->questao_numero] ?? null,
            'anulada' => ($r['anuladas'][$resposta->questao_numero] ?? null) !== null,
        ]];
    })
) }};

let portalUltimoFocoAntesDoModal = null;

function portalAbrirDetalheQuestao(numero) {
    const q = PORTAL_QUESTOES[numero];
    if (!q) return;

    document.getElementById('modal-detalhe-titulo').textContent = 'Questão ' + numero;
    document.getElementById('modal-detalhe-anulada').classList.toggle('hidden', !q.anulada);

    const areaLinha = document.getElementById('modal-detalhe-area-linha');
    areaLinha.classList.toggle('hidden', !q.area);
    if (q.area) document.getElementById('modal-detalhe-area').textContent = q.area;

    const temaLinha = document.getElementById('modal-detalhe-tema-linha');
    temaLinha.classList.toggle('hidden', !q.tema);
    if (q.tema) document.getElementById('modal-detalhe-tema').textContent = q.tema;

    const suaResposta = document.getElementById('modal-detalhe-sua-resposta');
    suaResposta.textContent = q.suaResposta || 'Em branco';
    suaResposta.className = 'font-bold text-right ' + ((q.anulada || q.suaResposta === q.gabarito) ? 'text-emerald-700' : 'text-red-600');

    document.getElementById('modal-detalhe-gabarito').textContent = q.gabarito || '—';

    const modal = document.getElementById('modal-detalhe-questao');
    modal.classList.remove('hidden');
    modal.classList.add('flex');

    // Guarda o elemento com foco (o card clicado) pra devolver o foco a ele
    // ao fechar, e move o foco pra dentro do diálogo — sem isso um leitor de
    // tela nunca anuncia que um diálogo abriu.
    portalUltimoFocoAntesDoModal = document.activeElement;
    document.getElementById('modal-detalhe-fechar').focus();
}

function portalFecharDetalheQuestao() {
    const modal = document.getElementById('modal-detalhe-questao');
    modal.classList.add('hidden');
    modal.classList.remove('flex');

    if (portalUltimoFocoAntesDoModal) {
        portalUltimoFocoAntesDoModal.focus();
        portalUltimoFocoAntesDoModal = null;
    }
}

document.addEventListener('keydown', function (event) {
    const modal = document.getElementById('modal-detalhe-questao');
    if (modal.classList.contains('hidden')) return;

    if (event.key === 'Escape') {
        portalFecharDetalheQuestao();

        return;
    }

    // Trava o foco dentro do diálogo — o único controle focável hoje é o
    // botão de fechar, então Tab/Shift+Tab só mantêm o foco nele em vez de
    // escapar pro conteúdo atrás do overlay.
    if (event.key === 'Tab') {
        event.preventDefault();
        document.getElementById('modal-detalhe-fechar').focus();
    }
});

function portalTemasDaArea(area) {
    const temas = new Set();
    Object.values(PORTAL_QUESTOES).forEach(function (q) {
        if (q.tema && (!area || q.area === area)) temas.add(q.tema);
    });
    return Array.from(temas).sort();
}

// Ao trocar a área, os temas do outro <select> são restritos aos que
// realmente existem naquela área — senão dá pra escolher uma combinação
// impossível (área X + tema de outra área) que nunca bate com nada no grid.
function portalAreaFiltroMudou() {
    const areaSel = document.getElementById('filtro-detalhe-area');
    const temaSel = document.getElementById('filtro-detalhe-tema');

    if (temaSel) {
        const temaAtual = temaSel.value;
        const temas = portalTemasDaArea(areaSel ? areaSel.value : '');

        temaSel.innerHTML = '';
        const optionTodos = document.createElement('option');
        optionTodos.value = '';
        optionTodos.textContent = 'Todos os temas';
        temaSel.appendChild(optionTodos);

        temas.forEach(function (tema) {
            const option = document.createElement('option');
            option.value = tema;
            option.textContent = tema;
            temaSel.appendChild(option);
        });

        temaSel.value = temas.includes(temaAtual) ? temaAtual : '';
    }

    portalFiltrarDetalheQuestoes();
}

function portalFiltrarDetalheQuestoes() {
    const areaSel = document.getElementById('filtro-detalhe-area');
    const temaSel = document.getElementById('filtro-detalhe-tema');
    const area = areaSel ? areaSel.value : '';
    const tema = temaSel ? temaSel.value : '';

    document.querySelectorAll('.detalhe-questao-item').forEach(function (item) {
        const matchArea = !area || item.dataset.area === area;
        const matchTema = !tema || item.dataset.tema === tema;
        item.style.display = (matchArea && matchTema) ? '' : 'none';
    });
}

function portalExportarPdfAvaliacao() {
    const conteudo = document.getElementById('pdf-conteudo');
    const btn = document.querySelector('.btn-pdf-avaliacao');
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="ph-bold ph-spinner-gap animate-spin mr-2"></i> Gerando...';
    btn.disabled = true;

    const cabecalho = document.createElement('div');
    cabecalho.className = 'flex justify-between items-center mb-6 pb-4 border-b-2 border-primary';
    cabecalho.innerHTML = '<div>'
        + '<p class="font-black text-lg text-slate-800">' + PORTAL_SITE_TITLE + '</p>'
        + '<p class="text-xs text-slate-500 mt-0.5">Relatório de Resultado</p>'
        + '</div>'
        + '<div class="text-right">'
        + '<p class="text-sm font-bold text-slate-700">' + (PORTAL_ALUNO.nome || '') + '</p>'
        + '<p class="text-xs text-slate-500">RA: ' + PORTAL_ALUNO.ra + ' &middot; Gerado em '
        + new Date().toLocaleDateString('pt-BR') + ' ' + new Date().toLocaleTimeString('pt-BR') + '</p>'
        + '</div>';
    conteudo.insertBefore(cabecalho, conteudo.firstChild);

    const nomeAvaliacaoArquivo = (conteudo.dataset.avaliacaoNome || 'avaliacao').replace(/[^a-z0-9]+/gi, '-').toLowerCase();

    // Página única do tamanho exato do conteúdo, em vez do formato A4 fixo:
    // com A4 fixo, o html2pdf pagina o conteúdo em várias páginas cortando
    // no meio de cards/linhas sempre que ele passa da altura de uma folha.
    const margemMm = 10;
    const larguraA4Mm = 210;
    const larguraUtilMm = larguraA4Mm - margemMm * 2;
    const proporcao = conteudo.scrollHeight / conteudo.scrollWidth;
    const alturaPaginaMm = larguraUtilMm * proporcao + margemMm * 2;

    html2pdf().from(conteudo).set({
        margin: margemMm,
        filename: 'resultado-' + PORTAL_ALUNO.ra + '-' + nomeAvaliacaoArquivo + '.pdf',
        image: {type: 'jpeg', quality: 0.98},
        html2canvas: {scale: 2, useCORS: true, logging: false},
        jsPDF: {unit: 'mm', format: [larguraA4Mm, alturaPaginaMm], orientation: 'portrait'},
        pagebreak: {mode: 'avoid-all'},
    }).save().then(function () {
        cabecalho.remove();
        btn.innerHTML = originalHtml;
        btn.disabled = false;
    }).catch(function (err) {
        console.error('Erro ao gerar PDF: ', err);
        alert('Ocorreu um erro ao gerar o PDF.');
        cabecalho.remove();
        btn.innerHTML = originalHtml;
        btn.disabled = false;
    });
}
</script>
@endsection
