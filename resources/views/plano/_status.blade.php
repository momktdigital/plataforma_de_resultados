{{--
    Selo da situação de um plano de ação. A situação sai SEMPRE em texto + ícone (a cor sozinha não basta).
    Variáveis: $status (PlanoAcao::STATUS), $pequeno (opcional).
--}}
@php
    [$icone, $classe] = match ($status) {
        'rascunho' => ['ph-pencil-simple', 'bg-slate-100 text-slate-700'],
        'em_analise' => ['ph-hourglass-medium', 'bg-sky-100 text-sky-800'],
        'ajustes' => ['ph-pencil-line', 'bg-amber-100 text-amber-800'],
        'aprovado' => ['ph-play-circle', 'bg-emerald-100 text-emerald-800'],
        'recusado' => ['ph-x-circle', 'bg-red-100 text-red-800'],
        'concluido' => ['ph-flag-checkered', 'bg-emerald-100 text-emerald-800'],
        default => ['ph-prohibit', 'bg-slate-100 text-slate-700'],
    };
@endphp
<span class="inline-flex items-center gap-1 rounded-full font-semibold whitespace-nowrap {{ ($pequeno ?? false) ? 'px-1.5 py-0.5 text-[11px]' : 'px-2.5 py-0.5 text-xs' }} {{ $classe }}">
    <i class="ph-bold {{ $icone }}" aria-hidden="true"></i>{{ \App\Models\PlanoAcao::STATUS[$status] ?? $status }}
</span>
