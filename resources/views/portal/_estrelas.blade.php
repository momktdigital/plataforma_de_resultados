{{-- Estrelas de participação (App\Support\EstrelasDeParticipacao): 5 = fez todas as provas previstas; meia estrela vale 10%. --}}
@php
    $descricao = 'Participação: '.str_replace('.', ',', (string) $estrelas['estrelas']).' de 5 estrelas. '
        .'Você fez '.$estrelas['feitas'].' de '.$estrelas['previstas'].($estrelas['previstas'] === 1 ? ' prova prevista' : ' provas previstas').'.';
    $moldeEstrela = 'M12 2.5l2.9 6.1 6.6.8-4.9 4.6 1.3 6.6L12 17.3 6.1 20.6l1.3-6.6L2.5 9.4l6.6-.8z';
@endphp
<div data-tour="estrelas" class="mt-1.5 flex items-center gap-2" title="{{ $descricao }}">
    <span class="flex items-center gap-0.5" role="img" aria-label="{{ $descricao }}">
        @for ($i = 0; $i < \App\Support\EstrelasDeParticipacao::TOTAL; $i++)
            @php $preenchimento = $i < $estrelas['cheias'] ? 100 : ($i === $estrelas['cheias'] && $estrelas['meia'] ? 50 : 0); @endphp
            <span class="relative inline-block w-5 h-5 sm:w-6 sm:h-6" aria-hidden="true">
                <svg viewBox="0 0 24 24" class="absolute inset-0 w-full h-full" fill="rgba(255,255,255,.25)" stroke="rgba(255,255,255,.7)" stroke-width="1.2" stroke-linejoin="round"><path d="{{ $moldeEstrela }}"/></svg>
                @if ($preenchimento > 0)
                    <svg viewBox="0 0 24 24" class="absolute inset-0 w-full h-full" style="clip-path: inset(0 {{ 100 - $preenchimento }}% 0 0)" fill="#fcd34d" stroke="#fcd34d" stroke-width="1.2" stroke-linejoin="round"><path d="{{ $moldeEstrela }}"/></svg>
                @endif
            </span>
        @endfor
    </span>
    <span class="text-white/85 text-xs font-medium">
        Participação: {{ $estrelas['feitas'] }} de {{ $estrelas['previstas'] }} {{ $estrelas['previstas'] === 1 ? 'prova' : 'provas' }}
    </span>
</div>
