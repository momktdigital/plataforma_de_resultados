@extends('layouts.portal')

@section('title', "Boletim — {$aluno->ra}")
@section('container-class', 'max-w-5xl')

@php
    $siteTitle = \App\Models\Configuracao::valor('site_title', 'Resultados DI');
@endphp

@section('content')
<div class="flex flex-col sm:flex-row sm:items-center justify-between mb-8 gap-4 fade-in">
    <div>
        <a href="{{ route('portal.consulta') }}" class="inline-flex items-center text-sm font-medium text-slate-500 hover:text-primary transition-colors bg-white px-4 py-2 rounded-full shadow-sm border border-slate-200 mb-3">
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
        <p id="filtro-vazio-aviso" class="hidden text-sm text-slate-500 ml-auto">Nenhuma prova no período selecionado.</p>
    </div>
@endif

<p class="text-sm text-slate-500 mb-4">Clique numa prova para ver o detalhamento e baixar o PDF dela.</p>

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
            @include('portal._prova_card', ['r' => $r])
        @endforeach
    @endif
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
const PORTAL_SITE_TITLE = @json($siteTitle);
const PORTAL_ALUNO = {nome: @json($aluno->nome), ra: @json($aluno->ra)};

function portalToggleCategoria(botao) {
    const conteudo = botao.nextElementSibling;
    conteudo.hidden = !conteudo.hidden;
    botao.querySelector('.categoria-seta').classList.toggle('rotate-180', !conteudo.hidden);
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

function portalAbrirDetalhe(id) {
    document.querySelectorAll('.prova-modal').forEach(function (m) {
        m.classList.add('hidden');
        m.classList.remove('flex');
    });
    const modal = document.getElementById('modal-' + id);
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.style.overflow = 'hidden';
}

function portalFecharDetalhe(id) {
    const modal = document.getElementById('modal-' + id);
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('.prova-modal:not(.hidden)').forEach(function (m) {
        m.classList.add('hidden');
        m.classList.remove('flex');
    });
    document.body.style.overflow = '';
});

function portalExportarPdfProva(id) {
    const conteudo = document.getElementById('pdf-conteudo-' + id);
    const btn = document.querySelector('#modal-' + id + ' .btn-pdf-prova');
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="ph-bold ph-spinner-gap animate-spin mr-2"></i> Gerando...';
    btn.disabled = true;

    const cabecalho = document.createElement('div');
    cabecalho.className = 'flex justify-between items-center mb-6 pb-4 border-b-2 border-primary';
    cabecalho.innerHTML = '<div>'
        + '<p class="font-black text-lg text-slate-800">' + PORTAL_SITE_TITLE + '</p>'
        + '<p class="text-xs text-slate-500 mt-0.5">Boletim de Resultado</p>'
        + '</div>'
        + '<div class="text-right">'
        + '<p class="text-sm font-bold text-slate-700">' + (PORTAL_ALUNO.nome || '') + '</p>'
        + '<p class="text-xs text-slate-500">RA: ' + PORTAL_ALUNO.ra + ' &middot; Gerado em '
        + new Date().toLocaleDateString('pt-BR') + ' ' + new Date().toLocaleTimeString('pt-BR') + '</p>'
        + '</div>';
    conteudo.insertBefore(cabecalho, conteudo.firstChild);

    const nomeProvaArquivo = (conteudo.dataset.provaNome || 'prova').replace(/[^a-z0-9]+/gi, '-').toLowerCase();

    html2pdf().from(conteudo).set({
        margin: 10,
        filename: 'boletim-' + PORTAL_ALUNO.ra + '-' + nomeProvaArquivo + '.pdf',
        image: {type: 'jpeg', quality: 0.98},
        html2canvas: {scale: 2, useCORS: true, logging: false},
        jsPDF: {unit: 'mm', format: 'a4', orientation: 'portrait'},
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
