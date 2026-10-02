{{--
    Foto do aluno (Jacad) com a inicial como reserva quando a foto não existe ou não carrega. Decorativa: o nome
    sempre aparece ao lado. Variáveis: $nome (?string), $foto (?string), $tamanho (classes de tamanho, ex.: "w-10 h-10").
--}}
@php
    $inicial = mb_strtoupper(mb_substr(trim((string) $nome) !== '' ? trim($nome) : '?', 0, 1));
    $tamanho = $tamanho ?? 'w-10 h-10';
    $reserva = 'shrink-0 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-100 font-bold items-center justify-center '.$tamanho;
@endphp
@if (! empty($foto))
    <img src="{{ $foto }}" alt="" loading="lazy" class="shrink-0 rounded-full object-cover bg-slate-100 {{ $tamanho }}"
         onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
    <div style="display:none" class="{{ $reserva }}" aria-hidden="true">{{ $inicial }}</div>
@else
    <div class="flex {{ $reserva }}" aria-hidden="true">{{ $inicial }}</div>
@endif
