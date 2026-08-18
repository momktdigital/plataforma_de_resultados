@extends('layouts.portal')

@section('title', "Boletim — {$aluno->ra}")

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold">Boletim</h1>
        <p class="text-slate-500 mt-1">RA: <span class="font-bold text-slate-700">{{ $aluno->ra }}</span> — {{ $aluno->nome }}</p>
    </div>
    <div class="flex gap-2">
        <button onclick="portalExportarPdf()" class="border border-slate-300 text-slate-700 hover:bg-slate-50 rounded-lg px-4 py-2 text-sm font-medium">
            Exportar PDF
        </button>
        <a href="{{ route('portal.consulta') }}" class="border border-slate-300 text-slate-700 hover:bg-slate-50 rounded-lg px-4 py-2 text-sm font-medium">
            Nova consulta
        </a>
    </div>
</div>

@if (! empty($arvore) || ! empty($semCategoria))
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 mb-6 flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs font-medium mb-1" for="filtro-data-inicio">De</label>
            <input id="filtro-data-inicio" type="date" oninput="portalAplicarFiltro()"
                   class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" for="filtro-data-fim">Até</label>
            <input id="filtro-data-fim" type="date" oninput="portalAplicarFiltro()"
                   class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
        </div>
        <button type="button" onclick="portalLimparFiltro()" class="text-sm text-slate-500 hover:text-slate-700 underline">
            Limpar filtro
        </button>
        <p id="filtro-vazio-aviso" class="hidden text-sm text-slate-500 ml-auto">Nenhuma prova no período selecionado.</p>
    </div>
@endif

<div id="boletim">
    @if (empty($arvore) && empty($semCategoria))
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-8 text-center text-slate-500">
            Você não possui resultados cadastrados no momento.
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
            @include('portal._prova_card', ['r' => $r])
        @endforeach
    @endif
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function portalToggleCategoria(botao) {
    const conteudo = botao.nextElementSibling;
    conteudo.hidden = !conteudo.hidden;
}

function portalAplicarFiltro() {
    const inicio = document.getElementById('filtro-data-inicio').value;
    const fim = document.getElementById('filtro-data-fim').value;

    document.querySelectorAll('.prova-card').forEach(function (card) {
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
        const temCartaoVisivel = !!no.querySelector('.prova-card:not(.hidden)');
        no.classList.toggle('hidden', !temCartaoVisivel);
        if (temCartaoVisivel) algumVisivel = true;
        // Expande automaticamente quando o filtro restringe o resultado, pra não parecer vazio.
        if (temCartaoVisivel && (inicio || fim)) {
            no.querySelector('.categoria-conteudo').hidden = false;
        }
    });
    document.querySelectorAll('#boletim > .prova-card').forEach(function (card) {
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

function portalExportarPdf() {
    // Expande tudo temporariamente pra sair no PDF, mesmo o que estava colapsado.
    const conteudos = document.querySelectorAll('.categoria-conteudo');
    const estadoAnterior = Array.from(conteudos).map(function (el) { return el.hidden; });
    conteudos.forEach(function (el) { el.hidden = false; });

    html2pdf().from(document.getElementById('boletim')).set({
        filename: 'boletim-{{ $aluno->ra }}.pdf',
        margin: 10,
    }).save().then(function () {
        conteudos.forEach(function (el, i) { el.hidden = estadoAnterior[i]; });
    });
}
</script>
@endsection
