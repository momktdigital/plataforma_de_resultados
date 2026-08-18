{{-- $r: uma entrada do array retornado por ResultadoConsultaService --}}
<div class="prova-card bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden mb-4" data-data="{{ $r['prova']->data_prova?->format('Y-m-d') ?? '' }}">
    <div class="px-6 py-5 border-b border-slate-100 bg-slate-50 flex items-center justify-between gap-4">
        <div>
            <h3 class="font-bold text-slate-800 flex items-center gap-2">
                <i class="ph-fill ph-exam text-primary"></i>
                {{ $r['prova']->nome ?? "Prova #{$r['prova']->codigo}" }}
            </h3>
            <p class="text-sm text-slate-500 mt-0.5">
                @if ($r['prova']->data_prova)
                    {{ $r['prova']->data_prova->format('d/m/Y') }} &middot;
                @endif
                Período: {{ $r['periodo'] !== '' ? $r['periodo'] : '—' }}
            </p>
        </div>
        @if ($r['total'] > 0)
            <div class="text-right shrink-0">
                <div class="text-3xl font-black text-primary">{{ $r['percentual'] }}%</div>
                <div class="text-xs text-slate-500 font-medium">{{ $r['acertos'] }}/{{ $r['total'] }} acertos</div>
            </div>
        @endif
    </div>

    <div class="p-6">
        @if ($r['prova']->link_comentado)
            <a href="{{ $r['prova']->link_comentado }}" target="_blank" rel="noopener"
               class="inline-flex items-center mb-4 text-sm font-medium text-primary hover:underline">
                <i class="ph-bold ph-link mr-1.5"></i> Acessar gabarito comentado
            </a>
        @endif

        @if ($r['metricas']->isNotEmpty())
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
                @foreach ($r['metricas'] as $metrica)
                    <div class="bg-slate-50 border border-slate-100 rounded-lg p-3">
                        <div class="text-xs font-bold text-slate-500 uppercase truncate" title="{{ $metrica->nome_metrica }}">{{ $metrica->nome_metrica }}</div>
                        <div class="text-lg font-black text-slate-800">{{ $metrica->valor }}</div>
                    </div>
                @endforeach
            </div>
        @endif

        <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-3">Detalhamento das respostas</p>
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
                <div class="rounded-lg overflow-hidden border border-slate-200 shadow-sm">
                    <div class="{{ $cor }} text-white text-[10px] text-center font-bold py-1">Q{{ $resposta->questao_numero }}</div>
                    <div class="bg-white text-center font-bold text-sm py-1.5 {{ $marcada === '' ? 'text-slate-300' : 'text-slate-700' }}">
                        {{ $marcada !== '' ? $marcada : '-' }}
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
