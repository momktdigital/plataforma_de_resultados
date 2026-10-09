{{-- Dashboard da avaliação: links de navegação e título. --}}
<div class="flex items-start justify-between gap-4 mb-2">
    @if ($somenteLeitura)
        <a href="{{ route('avaliacoes.index') }}" class="text-sm text-slate-500 hover:underline">&larr; Avaliações</a>
        <span class="text-sm text-slate-500">Avaliação #{{ $avaliacao->codigo }}@if ($avaliacao->nome) — {{ $avaliacao->nome }}@endif</span>
    @else
        <a href="{{ route('avaliacoes.show', $avaliacao) }}" class="text-sm text-slate-500 hover:underline">&larr; Avaliacao #{{ $avaliacao->codigo }}</a>
        <a href="{{ route('avaliacoes.visualizacoes.edit', $avaliacao) }}" class="text-sm text-slate-500 hover:underline">Configurar visualizações &rarr;</a>
    @endif
</div>
<div class="flex flex-wrap items-center gap-x-6 gap-y-2 mt-2 mb-6">
    <h1 class="text-2xl font-bold">Dashboard</h1>
    <div class="flex flex-wrap items-center gap-3">
        @if ($avaliacao->link_comentado)
            <a href="{{ $avaliacao->link_comentado }}" target="_blank" rel="noopener"
               class="inline-flex items-center gap-1.5 border border-slate-300 text-slate-700 hover:bg-slate-50 font-semibold rounded-lg px-3 py-1.5 text-sm">
                <i class="ph-bold ph-arrow-square-out text-emerald-600" aria-hidden="true"></i> Gabarito comentado
            </a>
        @endif
        @unless ($somenteLeitura)
            <a href="{{ route('avaliacoes.show', $avaliacao) }}#gabarito"
               class="inline-flex items-center gap-1.5 border border-slate-300 text-slate-700 hover:bg-slate-50 font-semibold rounded-lg px-3 py-1.5 text-sm">
                <i class="ph-bold ph-list-checks text-emerald-600" aria-hidden="true"></i> Gabarito da avaliação
            </a>
        @endunless
    </div>
</div>
