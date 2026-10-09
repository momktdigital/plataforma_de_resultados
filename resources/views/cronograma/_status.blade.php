{{--
    Selo da situação de um curso numa atividade (ou de uma pendência). A situação sai SEMPRE em texto + ícone — a cor sozinha
    não basta para quem não distingue cores.
    Variáveis: $status (aguardando | em_acompanhamento | pendente | resolvido), $pequeno (opcional).
--}}
@php
    [$icone, $classe] = match ($status) {
        'pendente' => ['ph-warning', 'bg-amber-100 text-amber-800'],
        'em_acompanhamento' => ['ph-eye', 'bg-sky-100 text-sky-800'],
        'resolvido' => ['ph-check-circle', 'bg-emerald-100 text-emerald-800'],
        default => ['ph-clock', 'bg-slate-100 text-slate-700'],
    };
@endphp
<span class="inline-flex items-center gap-1 rounded-full font-semibold whitespace-nowrap {{ ($pequeno ?? false) ? 'px-1.5 py-0.5 text-[11px]' : 'px-2.5 py-0.5 text-xs' }} {{ $classe }}">
    <i class="ph-bold {{ $icone }}" aria-hidden="true"></i>{{ \App\Models\CronogramaItem::rotuloDoStatus($status) }}
</span>
