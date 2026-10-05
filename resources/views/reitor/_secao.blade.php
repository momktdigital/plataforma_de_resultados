{{-- Título de seção numerado do painel da reitoria. Variáveis: $numero (opcional), $titulo, $id. --}}
<h2 id="{{ $id }}" class="flex items-center gap-3 text-sm font-bold uppercase tracking-wide text-[#1e3a5f] mb-3 mt-8">
    @if (! empty($numero))
        <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-[#1e3a5f] text-white text-xs font-bold" aria-hidden="true">{{ $numero }}</span>
    @endif
    {{ $titulo }}
</h2>
