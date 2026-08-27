{{--
    $r: uma entrada do array retornado por ResultadoConsultaService.
    Card resumido (nome, data, % de acerto) que abre o detalhe completo
    (gabarito, métricas, PDF) na mesma aba — deixando espaço pra outras
    análises que serão adicionadas ali no futuro, sem apertar num popup.
--}}
@php
    $paramsAvaliacao = ['avaliacao' => $r['avaliacao']->codigo, 'periodo' => $r['periodo']];
    if (request()->has('periodo_letivo')) {
        // Só repassa o filtro de período letivo se o aluno mexeu nele — assim
        // o link "Voltar" na página de detalhe volta pro mesmo estado
        // (filtrado ou não), em vez de sempre cair no período mais recente.
        $paramsAvaliacao['periodo_letivo'] = request('periodo_letivo');
    }
@endphp
<a href="{{ route('portal.resultados.avaliacao', $paramsAvaliacao) }}"
   class="avaliacao-card w-full text-left bg-white rounded-xl border border-slate-200 shadow-sm hover:border-primary hover:shadow-md transition-all p-4 flex items-center justify-between gap-4 mb-3"
   data-data="{{ $r['avaliacao']->data_avaliacao?->format('Y-m-d') ?? '' }}">
    <div class="flex items-center gap-3 min-w-0">
        <div class="w-11 h-11 rounded-lg bg-emerald-50 flex items-center justify-center shrink-0">
            <i class="ph-fill ph-exam text-primary text-xl"></i>
        </div>
        <div class="min-w-0">
            <div class="font-bold text-slate-800 truncate">{{ $r['avaliacao']->nome ?? "Avaliacao #{$r['avaliacao']->codigo}" }}</div>
            <div class="text-xs text-slate-500 mt-0.5">
                @if ($r['avaliacao']->data_avaliacao)
                    {{ $r['avaliacao']->data_avaliacao->format('d/m/Y') }} &middot;
                @endif
                Período: {{ $r['periodo'] !== '' ? $r['periodo'] : '—' }}
            </div>
        </div>
    </div>
    <div class="flex items-center gap-3 shrink-0">
        @if ($r['total'] > 0)
            <div class="flex items-center gap-2.5">
                @include('portal._anel_progresso', ['percentual' => $r['percentual'], 'tamanho' => 44, 'espessura' => 5, 'tamanhoTexto' => 'text-[11px]'])
                <div class="text-[11px] text-slate-500 font-medium hidden sm:block">{{ $r['acertos'] }}/{{ $r['total'] }}<br>acertos</div>
            </div>
        @endif
        <i class="ph-bold ph-caret-right text-slate-300 text-lg"></i>
    </div>
</a>
