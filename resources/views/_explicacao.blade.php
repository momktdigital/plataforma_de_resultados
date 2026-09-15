{{--
    Botão "o que isso significa" — abre um popover com a explicação didática
    do visual (o que ele mede e como analisar, texto fixo) e a LEITURA do
    resultado que está na tela agora, com o tom dela: quem abre precisa
    entender em um segundo se aquele número é bom ou é problema.

    Espera:
    - $explicacao = ['generico' => string, 'leitura' => ?string, 'tom' => ?string]
      onde tom ∈ bom | atencao | ruim | null (neutro).
    - $id (opcional): prefixo de id para o JS conseguir reescrever a leitura
      sem recarregar a página — usado pelos visuais que mudam com clique ou
      filtro, como a curva característica do item selecionado.

    UMA explicação POR VISUAL, nunca por área da página: o cartão de KR-20 e o
    de média medem coisas diferentes e precisam de leituras diferentes.

    O comportamento (abrir/fechar/posicionar) vive em _viz.blade.php, num
    listener delegado — este parcial se repete dezenas de vezes por página.

    position:fixed (não absolute) DE PROPÓSITO: os cards têm overflow-hidden
    para arredondar os cantos, e um popover absolute era cortado por eles.

    O atributo HTML `hidden` (não a classe Tailwind `hidden`) é o que controla
    show/hide: o JS alterna `conteudo.hidden`. Pôr a CLASSE `hidden` aqui
    seria o bug clássico de "clico e não acontece nada" — a regra
    `.hidden{display:none!important}` continuaria escondendo o popover mesmo
    depois de o JS remover o atributo.
--}}
@php
    $explicacaoId = $id ?? null;
    $tomAtual = $explicacao['tom'] ?? null;
    $tons = [
        'bom' => ['rotulo' => 'Bom sinal', 'classe' => 'text-emerald-700 bg-emerald-50'],
        'atencao' => ['rotulo' => 'Atenção', 'classe' => 'text-amber-700 bg-amber-50'],
        'ruim' => ['rotulo' => 'Precisa de ação', 'classe' => 'text-red-700 bg-red-50'],
    ];
@endphp
@if (! empty($explicacao) && ! empty($explicacao['generico']))
    <button type="button" class="explicacao-toggle shrink-0 text-slate-300 hover:text-amber-500 transition-colors"
            aria-label="O que este visual significa e como analisá-lo">
        <i class="ph-fill ph-lightbulb text-base"></i>
    </button>
    <div class="explicacao-conteudo fixed z-50 w-72 sm:w-80 bg-white border border-slate-200 rounded-lg shadow-lg p-3 text-left normal-case tracking-normal" hidden>
        <p class="text-xs text-slate-600 font-normal leading-relaxed">{{ $explicacao['generico'] }}</p>

        <div @if ($explicacaoId) id="{{ $explicacaoId }}-leitura" @endif
             class="mt-2 pt-2 border-t border-slate-100 {{ empty($explicacao['leitura']) ? 'hidden' : '' }}">
            <span @if ($explicacaoId) id="{{ $explicacaoId }}-tom" @endif
                  class="explicacao-tom inline-block text-[10px] font-bold uppercase tracking-wide px-1.5 py-0.5 rounded {{ $tons[$tomAtual]['classe'] ?? 'text-slate-600 bg-slate-100' }} {{ $tomAtual === null ? 'hidden' : '' }}">
                {{ $tons[$tomAtual]['rotulo'] ?? '' }}
            </span>
            <p @if ($explicacaoId) id="{{ $explicacaoId }}-texto" @endif
               class="text-xs font-semibold text-slate-800 leading-relaxed mt-1">{{ $explicacao['leitura'] ?? '' }}</p>
        </div>
    </div>
@endif
