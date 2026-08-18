{{-- $r: uma entrada do array retornado por ResultadoConsultaService --}}
<div class="prova-card bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-4" data-data="{{ $r['prova']->data_prova?->format('Y-m-d') ?? '' }}">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="font-bold">{{ $r['prova']->nome ?? "Prova #{$r['prova']->codigo}" }}</h3>
            <p class="text-sm text-slate-500">
                @if ($r['prova']->data_prova)
                    {{ $r['prova']->data_prova->format('d/m/Y') }} &middot;
                @endif
                Período: {{ $r['periodo'] !== '' ? $r['periodo'] : '—' }}
            </p>
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
