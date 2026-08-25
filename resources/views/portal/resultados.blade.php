@extends('layouts.portal')

@section('title', "Boletim — {$aluno->ra}")
@section('container-class', 'max-w-5xl')

@php
    $primeiroNome = $aluno->nome ? mb_convert_case(explode(' ', trim($aluno->nome))[0], MB_CASE_TITLE, 'UTF-8') : $aluno->ra;
@endphp

@section('content')
<div class="mb-6 fade-in">
    <a href="{{ route('portal.sair') }}" class="inline-flex items-center text-sm font-medium text-slate-500 hover:text-primary transition-colors bg-white px-4 py-2 rounded-full shadow-sm border border-slate-200 mb-4">
        <i class="ph-bold ph-arrow-left mr-2"></i> Nova consulta
    </a>

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
                <h1 class="text-2xl sm:text-3xl font-black text-white truncate">{{ $primeiroNome }}</h1>
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
