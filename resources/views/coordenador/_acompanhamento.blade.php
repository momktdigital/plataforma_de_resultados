{{--
    Selo do último acompanhamento do aluno (contatado / em acompanhamento / resolvido). Variável: $acompanhamento (?array
    com status, rotulo e em — ver AcompanhamentoService::ultimos()); sem registro, não mostra nada.
--}}
@if (! empty($acompanhamento))
    @php
        $estiloAcomp = match ($acompanhamento['status']) {
            'resolvido' => ['bg-emerald-50 text-emerald-800 border-emerald-200', 'ph-check-circle'],
            'em_acompanhamento' => ['bg-sky-50 text-sky-800 border-sky-200', 'ph-hourglass-medium'],
            default => ['bg-slate-100 text-slate-700 border-slate-200', 'ph-phone-call'],
        };
    @endphp
    <span class="inline-flex items-center gap-1 whitespace-nowrap rounded-full border px-2 py-0.5 text-xs font-semibold {{ $estiloAcomp[0] }}" title="Último registro em {{ \Illuminate\Support\Carbon::parse($acompanhamento['em'])->format('d/m/Y') }}">
        <i class="ph-bold {{ $estiloAcomp[1] }}" aria-hidden="true"></i> {{ $acompanhamento['rotulo'] }}
    </span>
@endif
