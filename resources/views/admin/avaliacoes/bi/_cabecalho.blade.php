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
<h1 class="text-2xl font-bold mt-2 mb-6">Dashboard</h1>
