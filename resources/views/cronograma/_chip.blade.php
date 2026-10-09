{{--
    Uma atividade dentro do calendário: rotina, projeto e a situação (do curso do coordenador; ou o resumo, na visão do
    colaborador). Variáveis: $item, $rotaItem (nome da rota), $cursosDoUsuario (null = todos os cursos).
--}}
@php
    $classe = match ($item->rotina) {
        'ROD' => 'bg-sky-50 border-sky-300 text-sky-900 hover:bg-sky-100',
        'ROC' => 'bg-violet-50 border-violet-300 text-violet-900 hover:bg-violet-100',
        default => 'bg-amber-50 border-amber-300 text-amber-900 hover:bg-amber-100',
    };
    $situacao = $item->resumoStatus($cursosDoUsuario);
@endphp
<a href="{{ route($rotaItem, $item) }}" title="{{ $item->descricao }}"
   class="block rounded-md border-l-4 border px-1.5 py-1 text-xs leading-snug focus:outline-none focus-visible:ring-2 focus-visible:ring-primary {{ $classe }}">
    <span class="font-bold">{{ $item->rotina }}</span>
    <span class="break-words">{{ $item->projeto }}</span>
    @if ($situacao)
        <span class="mt-0.5 block">@include('cronograma._status', ['status' => $situacao, 'pequeno' => true])</span>
    @endif
</a>
