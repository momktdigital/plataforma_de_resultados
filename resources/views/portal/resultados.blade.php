@extends('layouts.portal')

@section('title', "Resultados — {$aluno->ra}")
@section('container-class', 'max-w-6xl')

@php
    $nomeCompleto = $aluno->nome ? mb_convert_case(mb_strtolower(trim($aluno->nome), 'UTF-8'), MB_CASE_TITLE, 'UTF-8') : $aluno->ra;
@endphp

@section('content')
<div class="mb-6 fade-in">
    <div class="relative overflow-hidden rounded-3xl shadow-lg" style="background: linear-gradient(135deg, #00b48d 0%, #009e7d 55%, #007a61 100%);">
        <div class="absolute inset-0 opacity-10 pointer-events-none" style="background-image: radial-gradient(circle at 85% 15%, white 0, transparent 45%), radial-gradient(circle at 10% 90%, white 0, transparent 40%);"></div>

        <div class="relative p-6 sm:p-8 flex flex-col sm:flex-row sm:items-center gap-6">
            <div class="shrink-0">
                @if ($aluno->fotoUrl())
                    <img src="{{ $aluno->fotoUrl(150) }}" alt="Foto de {{ $aluno->nome ?: $aluno->ra }}"
                         class="w-20 h-20 sm:w-24 sm:h-24 rounded-full object-cover border-4 border-white/40 shadow-md"
                         onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                    <div style="display:none" class="w-20 h-20 sm:w-24 sm:h-24 rounded-full border-4 border-white/40 shadow-md bg-white/20 items-center justify-center text-white text-3xl font-bold">
                        {{ mb_strtoupper(mb_substr($aluno->nome ?: $aluno->ra, 0, 1)) }}
                    </div>
                @else
                    <div class="w-20 h-20 sm:w-24 sm:h-24 rounded-full border-4 border-white/40 shadow-md bg-white/20 flex items-center justify-center text-white text-3xl font-bold">
                        {{ mb_strtoupper(mb_substr($aluno->nome ?: $aluno->ra, 0, 1)) }}
                    </div>
                @endif
            </div>

            <div class="min-w-0 flex-1">
                <p id="saudacao-portal" class="text-white/80 text-sm font-medium uppercase tracking-wide">Olá</p>
                <h1 class="text-2xl sm:text-3xl font-black text-white truncate">{{ $nomeCompleto }}</h1>
                <p class="text-white/85 text-sm mt-1">
                    RA {{ $aluno->ra }}
                    @if ($aluno->curso) &middot; {{ $aluno->curso }} @endif
                    @if ($aluno->periodo) &middot; {{ $aluno->periodo }} período @endif
                </p>
            </div>

            <div class="flex gap-3 sm:gap-4 shrink-0">
                <div class="bg-white/15 backdrop-blur-sm rounded-2xl px-4 py-3 text-center min-w-[84px]">
                    <div class="text-2xl font-black text-white">{{ $totalAvaliacoes }}</div>
                    <div class="text-[11px] text-white/80 font-medium uppercase tracking-wide">Avaliações</div>
                </div>
                <div class="bg-white/15 backdrop-blur-sm rounded-2xl px-4 py-3 text-center min-w-[84px]">
                    <div class="text-2xl font-black text-white">{{ $mediaGeral !== null ? $mediaGeral.'%' : '—' }}</div>
                    <div class="text-[11px] text-white/80 font-medium uppercase tracking-wide">Média geral</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var hora = new Date().getHours();
    var saudacao = hora < 12 ? 'Bom dia' : (hora < 18 ? 'Boa tarde' : 'Boa noite');
    var el = document.getElementById('saudacao-portal');
    if (el) el.textContent = saudacao + ',';
})();
</script>

@if (! empty($insights))
    <div class="grid sm:grid-cols-2 gap-3 mb-6 fade-in">
        @foreach ($insights as $insight)
            @php
                $estiloInsight = match ($insight['tom']) {
                    'positivo' => ['bg' => 'bg-emerald-50', 'borda' => 'border-emerald-100', 'icone' => 'text-emerald-600'],
                    'atencao' => ['bg' => 'bg-amber-50', 'borda' => 'border-amber-100', 'icone' => 'text-amber-600'],
                    default => ['bg' => 'bg-slate-50', 'borda' => 'border-slate-100', 'icone' => 'text-slate-600'],
                };
            @endphp
            <div class="{{ $estiloInsight['bg'] }} border {{ $estiloInsight['borda'] }} rounded-xl p-4 flex items-start gap-3">
                <i class="ph-bold {{ $insight['icone'] }} {{ $estiloInsight['icone'] }} text-xl shrink-0 mt-0.5"></i>
                <p class="text-sm text-slate-700">{{ $insight['texto'] }}</p>
            </div>
        @endforeach
    </div>
@endif

@if ($temAnaliseNaArvore)
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1"></script>
@endif

@if (! empty($resumoPorCategoria))
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5 mb-6 fade-in">
        <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-3 flex items-center gap-1.5">
            <i class="ph-bold ph-chart-bar-horizontal text-primary"></i> Desempenho por categoria
        </p>
        <div class="grid sm:grid-cols-2 gap-x-6 gap-y-3">
            @foreach ($resumoPorCategoria as $c)
                <div>
                    <div class="flex items-center justify-between text-xs mb-1">
                        <span class="font-medium text-slate-600 truncate">{{ $c['nome'] }}</span>
                        <span class="font-bold text-slate-700 shrink-0 ml-2">{{ $c['media'] }}%</span>
                    </div>
                    <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
                        <div class="h-full rounded-full bg-primary" style="width: {{ max(3, $c['media']) }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif

@if (! empty($periodosDisponiveis) || ! empty($arvore) || ! empty($semCategoria))
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 mb-6 flex flex-wrap items-end gap-3">
        @if (! empty($periodosDisponiveis))
            <form method="GET" action="{{ route('portal.resultados') }}">
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1" for="periodo-letivo">Período letivo</label>
                <select id="periodo-letivo" name="periodo_letivo" onchange="this.form.submit()"
                        class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white min-w-[140px]">
                    <option value="" {{ $periodoSelecionado === '' ? 'selected' : '' }}>Todos</option>
                    @foreach ($periodosDisponiveis as $p)
                        <option value="{{ $p }}" {{ $periodoSelecionado === $p ? 'selected' : '' }}>{{ $p }}</option>
                    @endforeach
                </select>
            </form>
        @endif

        @if (! empty($arvore) || ! empty($semCategoria))
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1" for="filtro-data-inicio">De</label>
                <input id="filtro-data-inicio" type="date" oninput="portalAplicarFiltro()"
                       class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1" for="filtro-data-fim">Até</label>
                <input id="filtro-data-fim" type="date" oninput="portalAplicarFiltro()"
                       class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
            </div>
            <button type="button" onclick="portalLimparFiltro()" class="text-sm text-slate-500 hover:text-primary underline">
                Limpar filtro
            </button>
            <p id="filtro-vazio-aviso" class="hidden text-sm text-slate-500 ml-auto">Nenhuma avaliação no período selecionado.</p>
        @endif
    </div>
@endif

<p class="text-sm text-slate-500 mb-4">Clique numa avaliação para ver o detalhamento e baixar o PDF dela.</p>

<div id="resultados-lista">
    @if (empty($arvore) && empty($semCategoria))
        <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-12 text-center">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-slate-100 mb-4">
                <i class="ph ph-exam text-3xl text-slate-400"></i>
            </div>
            @if (! empty($periodosDisponiveis) && $periodoSelecionado !== '')
                <p class="text-slate-500 mb-3">Nenhum resultado encontrado para o período letivo "{{ $periodoSelecionado }}".</p>
                <a href="{{ route('portal.resultados', ['periodo_letivo' => '']) }}" class="text-primary font-semibold hover:underline text-sm">
                    Ver todos os períodos
                </a>
            @else
                <p class="text-slate-500">Você não possui resultados cadastrados no momento.</p>
            @endif
        </div>
    @else
        @if (! empty($arvore))
            <ul class="space-y-3 mb-4">
                @foreach ($arvore as $no)
                    @include('portal._categoria_no', ['no' => $no])
                @endforeach
            </ul>
        @endif

        @foreach ($semCategoria as $r)
            @include('portal._avaliacao_card', ['r' => $r])
        @endforeach
    @endif
</div>

<script>
function portalToggleCategoria(botao) {
    const conteudo = botao.nextElementSibling;
    conteudo.hidden = !conteudo.hidden;
    botao.querySelector('.categoria-seta').classList.toggle('rotate-180', !conteudo.hidden);

    if (!conteudo.hidden) portalRedimensionarGraficos(conteudo);
}

// Os gráficos da categoria são criados enquanto o conteúdo ainda está hidden
// (display:none) — o canvas reporta tamanho zero nesse momento, e nem todo
// navegador/versão do Chart.js recalcula sozinho quando o elemento aparece
// depois. resize() força o gráfico a se ajustar ao tamanho real assim que a
// categoria é expandida — seja pelo clique (portalToggleCategoria) ou pelo
// filtro de data expandindo automaticamente (portalAplicarFiltro).
function portalRedimensionarGraficos(conteudo) {
    if (typeof Chart === 'undefined') return;

    conteudo.querySelectorAll('canvas').forEach(function (canvas) {
        const grafico = Chart.getChart(canvas);
        if (grafico) grafico.resize();
    });
}

function portalAplicarFiltro() {
    const inicio = document.getElementById('filtro-data-inicio').value;
    const fim = document.getElementById('filtro-data-fim').value;

    document.querySelectorAll('.avaliacao-card').forEach(function (card) {
        const data = card.dataset.data;
        let visivel = true;
        if (data) {
            if (inicio && data < inicio) visivel = false;
            if (fim && data > fim) visivel = false;
        }
        card.classList.toggle('hidden', !visivel);
    });

    let algumVisivel = false;
    document.querySelectorAll('.categoria-no').forEach(function (no) {
        const temCartaoVisivel = !!no.querySelector('.avaliacao-card:not(.hidden)');
        no.classList.toggle('hidden', !temCartaoVisivel);
        if (temCartaoVisivel) algumVisivel = true;
        // Expande automaticamente quando o filtro restringe o resultado, pra não parecer vazio.
        if (temCartaoVisivel && (inicio || fim)) {
            const conteudo = no.querySelector('.categoria-conteudo');
            if (conteudo.hidden) {
                conteudo.hidden = false;
                portalRedimensionarGraficos(conteudo);
            }
        }
    });
    document.querySelectorAll('#resultados-lista > .avaliacao-card').forEach(function (card) {
        if (!card.classList.contains('hidden')) algumVisivel = true;
    });

    const aviso = document.getElementById('filtro-vazio-aviso');
    if (aviso) aviso.classList.toggle('hidden', algumVisivel);
}

function portalLimparFiltro() {
    document.getElementById('filtro-data-inicio').value = '';
    document.getElementById('filtro-data-fim').value = '';
    portalAplicarFiltro();
}

</script>
@endsection
