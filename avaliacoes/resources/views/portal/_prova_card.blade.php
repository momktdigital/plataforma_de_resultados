{{--
    $r: uma entrada do array retornado por ResultadoConsultaService.
    Card resumido (nome, data, % de acerto) que abre o detalhe completo
    (gabarito, métricas, PDF) numa aba nova — deixando espaço pra outras
    análises que serão adicionadas ali no futuro, sem apertar num popup.
--}}
<a href="{{ route('portal.resultados.prova', ['prova' => $r['prova']->codigo, 'periodo' => $r['periodo']]) }}"
   target="_blank" rel="noopener"
   class="prova-card w-full text-left bg-white rounded-xl border border-slate-200 shadow-sm hover:border-primary hover:shadow-md transition-all p-4 flex items-center justify-between gap-4 mb-3"
   data-data="{{ $r['prova']->data_prova?->format('Y-m-d') ?? '' }}">
    <div class="flex items-center gap-3 min-w-0">
        <div class="w-11 h-11 rounded-lg bg-emerald-50 flex items-center justify-center shrink-0">
            <i class="ph-fill ph-exam text-primary text-xl"></i>
        </div>
        <div class="min-w-0">
            <div class="font-bold text-slate-800 truncate">{{ $r['prova']->nome ?? "Prova #{$r['prova']->codigo}" }}</div>
            <div class="text-xs text-slate-500 mt-0.5">
                @if ($r['prova']->data_prova)
                    {{ $r['prova']->data_prova->format('d/m/Y') }} &middot;
                @endif
                Período: {{ $r['periodo'] !== '' ? $r['periodo'] : '—' }}
            </div>
        </div>
    </div>
    <div class="flex items-center gap-3 shrink-0">
        @if ($r['total'] > 0)
            <div class="text-right">
                <div class="text-xl font-black text-primary">{{ $r['percentual'] }}%</div>
                <div class="text-[11px] text-slate-500 font-medium">{{ $r['acertos'] }}/{{ $r['total'] }}</div>
            </div>
        @endif
        <i class="ph-bold ph-arrow-square-out text-slate-300 text-lg"></i>
    </div>
</a>
