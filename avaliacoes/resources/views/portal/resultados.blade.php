@extends('layouts.portal')

@section('title', "Boletim — {$aluno->ra}")

@section('content')
<div class="flex items-center justify-between mb-8">
    <div>
        <h1 class="text-2xl font-bold">Boletim</h1>
        <p class="text-slate-500 mt-1">RA: <span class="font-bold text-slate-700">{{ $aluno->ra }}</span> — {{ $aluno->nome }}</p>
    </div>
    <div class="flex gap-2">
        <button onclick="exportarPdf()" class="border border-slate-300 text-slate-700 hover:bg-slate-50 rounded-lg px-4 py-2 text-sm font-medium">
            Exportar PDF
        </button>
        <a href="{{ route('portal.consulta') }}" class="border border-slate-300 text-slate-700 hover:bg-slate-50 rounded-lg px-4 py-2 text-sm font-medium">
            Nova consulta
        </a>
    </div>
</div>

<div id="boletim">
    @forelse ($resultados as $r)
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="font-bold text-lg">{{ $r['prova']->nome ?? "Prova #{$r['prova']->codigo}" }}</h2>
                    <p class="text-sm text-slate-500">Período: {{ $r['periodo'] !== '' ? $r['periodo'] : '—' }}</p>
                </div>
                @if ($r['total'] > 0)
                    <div class="text-right">
                        <div class="text-2xl font-black text-emerald-700">{{ $r['percentual'] }}%</div>
                        <div class="text-xs text-slate-500">{{ $r['acertos'] }}/{{ $r['total'] }} acertos</div>
                    </div>
                @endif
            </div>

            @if ($r['prova']->link_comentado)
                <a href="{{ $r['prova']->link_comentado }}" target="_blank" rel="noopener"
                   class="inline-block mb-4 text-sm text-emerald-700 hover:underline">
                    Acessar gabarito comentado &nearr;
                </a>
            @endif

            @if ($r['metricas']->isNotEmpty())
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
                    @foreach ($r['metricas'] as $metrica)
                        <div class="bg-slate-50 border border-slate-100 rounded-lg p-3">
                            <div class="text-xs font-bold text-slate-500 uppercase truncate" title="{{ $metrica->nome_metrica }}">{{ $metrica->nome_metrica }}</div>
                            <div class="text-lg font-black text-slate-800">{{ $metrica->valor }}</div>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="grid grid-cols-5 sm:grid-cols-8 md:grid-cols-10 gap-2">
                @foreach ($r['respostas'] as $resposta)
                    @php
                        $correta = $r['gabaritos'][$resposta->questao_numero] ?? null;
                        $marcada = $resposta->resposta ?: '';
                        $cor = 'bg-slate-400';
                        if ($correta !== null && $correta !== '') {
                            $cor = $marcada === $correta ? 'bg-green-500' : ($marcada === '' ? 'bg-slate-400' : 'bg-red-500');
                        }
                    @endphp
                    <div class="rounded overflow-hidden border border-slate-200">
                        <div class="{{ $cor }} text-white text-[10px] text-center font-bold py-1">Q{{ $resposta->questao_numero }}</div>
                        <div class="bg-white text-center font-bold text-sm py-1.5 {{ $marcada === '' ? 'text-slate-300' : 'text-slate-700' }}">
                            {{ $marcada !== '' ? $marcada : '-' }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-8 text-center text-slate-500">
            Você não possui resultados cadastrados no momento.
        </div>
    @endforelse
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function exportarPdf() {
    html2pdf().from(document.getElementById('boletim')).set({
        filename: 'boletim-{{ $aluno->ra }}.pdf',
        margin: 10,
    }).save();
}
</script>
@endsection
