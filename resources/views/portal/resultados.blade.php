@extends('layouts.portal')

@section('title', "Boletim — {$aluno->ra}")
@section('container-class', 'max-w-5xl')

@section('content')
<div class="flex flex-col sm:flex-row sm:items-center justify-between mb-8 gap-4 fade-in">
    <div>
        <a href="{{ route('portal.sair') }}" class="inline-flex items-center text-sm font-medium text-slate-500 hover:text-primary transition-colors bg-white px-4 py-2 rounded-full shadow-sm border border-slate-200 mb-3">
            <i class="ph-bold ph-arrow-left mr-2"></i> Nova consulta
        </a>
        <h1 class="text-3xl font-bold text-slate-800 flex items-center gap-2">
            <i class="ph-fill ph-chart-bar text-primary"></i> Meu Boletim
        </h1>
        <p class="text-slate-500 mt-1">RA: <span class="font-bold text-slate-700">{{ $aluno->ra }}</span> — {{ $aluno->nome }}</p>
    </div>
</div>

@if (! empty($arvore) || ! empty($semCategoria))
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 mb-6 flex flex-wrap items-end gap-3">
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
    </div>
@endif

<p class="text-sm text-slate-500 mb-4">Clique numa avaliação para abrir o detalhamento numa aba nova e baixar o PDF dela.</p>

<div id="boletim">
    @if (empty($arvore) && empty($semCategoria))
        <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-12 text-center">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-slate-100 mb-4">
                <i class="ph ph-exam text-3xl text-slate-400"></i>
            </div>
            <p class="text-slate-500">Você não possui resultados cadastrados no momento.</p>
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
            no.querySelector('.categoria-conteudo').hidden = false;
        }
    });
    document.querySelectorAll('#boletim > .avaliacao-card').forEach(function (card) {
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
