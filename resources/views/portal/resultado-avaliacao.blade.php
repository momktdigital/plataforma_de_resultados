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
@section('container-class', 'max-w-3xl')

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
                <div class="text-right shrink-0">
                    <div class="text-3xl font-black text-primary">{{ $r['percentual'] }}%</div>
                    <div class="text-xs text-slate-500 font-medium">{{ $r['acertos'] }}/{{ $r['total'] }} acertos</div>
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

        @if ($estado['grade_questoes']['visivelAluno'])
            <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-3">Detalhamento das respostas</p>
            <div class="grid grid-cols-5 sm:grid-cols-8 md:grid-cols-10 gap-2 mb-6">
                @foreach ($r['respostas'] as $resposta)
                    @php
                        $correta = $r['gabaritos'][$resposta->questao_numero] ?? null;
                        $marcada = $resposta->resposta ?: '';
                        $cor = 'bg-slate-400';
                        if ($correta !== null && $correta !== '') {
                            $cor = $marcada === $correta ? 'bg-green-500' : ($marcada === '' ? 'bg-slate-400' : 'bg-red-500');
                        }
                    @endphp
                    <div class="rounded-lg overflow-hidden border border-slate-200 shadow-sm">
                        <div class="{{ $cor }} text-white text-[10px] text-center font-bold py-1">Q{{ $resposta->questao_numero }}</div>
                        <div class="bg-white text-center font-bold text-sm py-1.5 {{ $marcada === '' ? 'text-slate-300' : 'text-slate-700' }}">
                            {{ $marcada !== '' ? $marcada : '-' }}
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($estado['comparativo_turma']['visivelAluno'] && $comparativoTurma)
            <div class="mb-6 bg-slate-50 border border-slate-100 rounded-xl p-4">
                <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-2">Comparativo com a turma {{ $comparativoTurma['turma'] }}</p>
                <div class="flex items-center gap-6 text-sm">
                    <div><span class="text-slate-500">Você:</span> <span class="font-black text-primary">{{ $comparativoTurma['suaMedia'] }}%</span></div>
                    <div><span class="text-slate-500">Média da turma:</span> <span class="font-black text-slate-700">{{ $comparativoTurma['mediaTurma'] }}%</span></div>
                    <div class="text-xs text-slate-400">({{ $comparativoTurma['respondentesTurma'] }} respondente(s))</div>
                </div>
            </div>
        @endif

        @if ($estado['ranking_percentil']['visivelAluno'] && $rankingPercentil)
            <div class="mb-6 bg-slate-50 border border-slate-100 rounded-xl p-4">
                <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-2">Posição relativa</p>
                <p class="text-sm">
                    Você está no percentil <span class="font-black text-primary">{{ $rankingPercentil['percentil'] }}</span> —
                    posição {{ $rankingPercentil['posicao'] }} de {{ $rankingPercentil['totalRespondentes'] }} respondente(s).
                </p>
            </div>
        @endif

        @if ($estado['radar_disciplina']['visivelAluno'] && ! empty($radarDisciplina))
            <div class="mb-6">
                <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-3">Desempenho por disciplina</p>
                <canvas id="grafico-radar-disciplina" height="220"></canvas>
            </div>
        @endif

        @if (($estado['desempenho_bloom']['visivelAluno'] && ! empty($desempenhoBloom)) || ($estado['desempenho_miller']['visivelAluno'] && ! empty($desempenhoMiller)))
            <div class="grid sm:grid-cols-2 gap-4 mb-6">
                @if ($estado['desempenho_bloom']['visivelAluno'] && ! empty($desempenhoBloom))
                    <div>
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-3">Desempenho por nível de Bloom</p>
                        <canvas id="grafico-bloom" height="200"></canvas>
                    </div>
                @endif
                @if ($estado['desempenho_miller']['visivelAluno'] && ! empty($desempenhoMiller))
                    <div>
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-3">Desempenho por nível de Miller</p>
                        <canvas id="grafico-miller" height="200"></canvas>
                    </div>
                @endif
            </div>
        @endif

        @if ($estado['evolucao_categoria']['visivelAluno'] && ! empty($evolucaoHistorica) && count($evolucaoHistorica) >= 2)
            <div class="mb-6">
                <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-3">Evolução histórica na categoria</p>
                <canvas id="grafico-evolucao" height="200"></canvas>
            </div>
        @endif

        @if ($estado['comparativo_questao']['visivelAluno'] && ! empty($comparativoQuestao))
            <div>
                <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-3">Sua resposta x turma, por questão</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-slate-500 text-left">
                            <tr><th class="px-3 py-2">Questão</th><th class="px-3 py-2">Sua resposta</th><th class="px-3 py-2">Gabarito</th><th class="px-3 py-2">% turma acertou</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($comparativoQuestao as $q)
                                <tr>
                                    <td class="px-3 py-2 font-mono">Q{{ $q['numero'] }}</td>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
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

@if ($estado['desempenho_bloom']['visivelAluno'] && ! empty($desempenhoBloom))
new Chart(document.getElementById('grafico-bloom'), {
    type: 'bar',
    data: {
        labels: {{ Js::from(array_keys($desempenhoBloom)) }},
        datasets: [{ label: '% de acerto', data: {{ Js::from(array_values($desempenhoBloom)) }}, backgroundColor: '#00b48d' }],
    },
    options: { indexAxis: 'y', scales: { x: { beginAtZero: true, max: 100 } }, plugins: { legend: { display: false } } },
});
@endif

@if ($estado['desempenho_miller']['visivelAluno'] && ! empty($desempenhoMiller))
new Chart(document.getElementById('grafico-miller'), {
    type: 'bar',
    data: {
        labels: {{ Js::from(array_keys($desempenhoMiller)) }},
        datasets: [{ label: '% de acerto', data: {{ Js::from(array_values($desempenhoMiller)) }}, backgroundColor: '#00b48d' }],
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
