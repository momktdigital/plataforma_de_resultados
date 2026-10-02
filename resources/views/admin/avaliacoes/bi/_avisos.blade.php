{{-- Dashboard: avisos de "sem gabarito", "sem resultados" e "nenhum visual habilitado". --}}
@if (! empty($dados['semGabarito']))
    <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-xl p-6 text-sm mb-6">
        Esta avaliação ainda não tem gabarito cadastrado — importe ou cadastre as questões antes de ver o painel.
    </div>
@elseif (! empty($dados['semRespostas']))
    <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-xl p-6 text-sm mb-6">
        Nenhum resultado importado ainda para este filtro.
    </div>
@endif

@php
    $temAlgumVisivel = collect($estado)->contains(fn ($item) => $item['visivelAdmin']);
@endphp

@if (! $temAlgumVisivel && empty($dados['semGabarito']) && empty($dados['semRespostas']))
    <div class="bg-slate-50 border border-slate-200 text-slate-500 rounded-xl p-6 text-sm">
        Nenhum visual está habilitado para o administrativo nesta avaliação.
        @unless ($somenteLeitura)
            <a href="{{ route('avaliacoes.visualizacoes.edit', $avaliacao) }}" class="text-emerald-700 font-medium hover:underline">Configure aqui.</a>
        @endunless
    </div>
@endif
