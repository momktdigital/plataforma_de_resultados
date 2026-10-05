{{--
    Seletor com busca e árvore para a barra de filtros do painel da reitoria (categorias em árvore, avaliações em lista).
    Substitui o <select> nativo, que não indenta, não busca e não recolhe. O comportamento vive em _base.blade.php
    (`[data-combo]`): abrir/fechar, busca sem acento, expandir/recolher as filhas, teclado (setas, Enter, Esc, ←/→ para
    recolher/expandir) e envio do formulário ao escolher.

    Variáveis:
      $id          id do botão (único na página)
      $nome        name do campo escondido que vai na URL
      $rotulo      texto do rótulo (acima do campo)
      $itens       array de ['valor', 'rotulo', 'extra' => ?string, 'caminho' => ?string, 'nivel' => int, 'id' => ?string, 'pai' => ?string]
                   (o primeiro, valor '', é o "todas")
      $selecionado valor escolhido ('' = todas)
      $limpa       (opcional) nomes de campos do formulário que voltam a '' ao escolher aqui, separados por vírgula
      $busca       placeholder da busca
--}}
@php
    $atual = collect($itens)->firstWhere('valor', (string) $selecionado) ?? $itens[0];
@endphp
<div class="relative" data-combo @if (! empty($limpa)) data-limpa="{{ $limpa }}" @endif>
    <span class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1" id="{{ $id }}-rotulo">{{ $rotulo }}</span>
    <input type="hidden" name="{{ $nome }}" value="{{ $selecionado }}" data-combo-valor>

    <button type="button" id="{{ $id }}" role="combobox" aria-haspopup="listbox" aria-expanded="false" aria-controls="{{ $id }}-lista" aria-labelledby="{{ $id }}-rotulo {{ $id }}"
            class="flex w-full items-center justify-between gap-2 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-left text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
        <span class="truncate" data-combo-texto>{{ $atual['rotulo'] }}</span>
        <i class="ph ph-caret-down shrink-0 text-slate-500" aria-hidden="true"></i>
    </button>

    <div hidden data-combo-painel class="absolute left-0 z-40 mt-1 w-[26rem] max-w-[92vw] rounded-xl border border-slate-200 bg-white shadow-lg">
        <div class="border-b border-slate-100 p-2">
            <label class="sr-only" for="{{ $id }}-busca">{{ $busca }}</label>
            <div class="relative">
                <i class="ph ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-500" aria-hidden="true"></i>
                <input id="{{ $id }}-busca" type="search" autocomplete="off" placeholder="{{ $busca }}" data-combo-busca
                       class="w-full rounded-lg border border-slate-300 py-1.5 pl-9 pr-3 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
            </div>
        </div>

        <div class="max-h-80 overflow-y-auto">
            <div data-combo-selecionado class="border-b border-slate-100 px-3 py-2">
                <p class="text-[11px] font-bold uppercase tracking-wide text-slate-600">Selecionado</p>
                <p class="mt-1 flex items-center gap-2 text-sm font-semibold text-slate-800"><i class="ph-bold ph-check-square text-emerald-700" aria-hidden="true"></i> <span data-combo-selecionado-texto></span></p>
            </div>

            <p class="px-3 pt-2 text-[11px] font-bold uppercase tracking-wide text-slate-600" data-combo-titulo-lista>Todas as opções</p>
            <ul id="{{ $id }}-lista" role="listbox" aria-labelledby="{{ $id }}-rotulo" class="p-1" data-combo-lista>
                @foreach ($itens as $item)
                    <li role="option" tabindex="-1" aria-selected="{{ (string) $item['valor'] === (string) $selecionado ? 'true' : 'false' }}"
                        data-valor="{{ $item['valor'] }}" data-rotulo="{{ $item['rotulo'] }}" data-id="{{ $item['id'] ?? '' }}" data-pai="{{ $item['pai'] ?? '' }}"
                        data-texto="{{ $item['caminho'] ?? $item['rotulo'] }}"
                        class="flex cursor-pointer items-center gap-1 rounded-lg py-1.5 pr-2 text-sm text-slate-700 hover:bg-slate-100 aria-selected:font-semibold"
                        style="padding-left: {{ 0.25 + ($item['nivel'] ?? 0) * 1.25 }}rem">
                        <button type="button" hidden data-combo-seta tabindex="-1" aria-label="Mostrar ou esconder as filhas de {{ $item['rotulo'] }}" aria-expanded="false"
                                class="flex h-6 w-6 shrink-0 items-center justify-center rounded text-slate-600 hover:bg-slate-200">
                            <i class="ph-bold ph-caret-right text-xs transition-transform" aria-hidden="true"></i>
                        </button>
                        <span data-combo-recuo class="inline-block h-6 w-6 shrink-0" aria-hidden="true"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate">{{ $item['rotulo'] }}</span>
                            <span hidden data-combo-caminho class="block truncate text-xs text-slate-500">{{ $item['caminho'] ?? '' }}</span>
                        </span>
                        @if (! empty($item['extra']))
                            <span class="shrink-0 text-xs text-slate-600">{{ $item['extra'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
            <p hidden data-combo-vazio class="px-3 py-3 text-sm text-slate-600">Nada encontrado para essa busca.</p>
        </div>
    </div>
</div>
