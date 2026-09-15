{{--
    Botão "o que isso significa" — abre um popover com a explicação didática
    do visual (o que ele mede e como analisar, texto fixo) e a LEITURA do
    resultado que está na tela agora.

    Espera $explicacao = ['generico' => string, 'leitura' => ?string].
    Quem chama: os painéis do BI (App\Services\ExplicacaoBiService) e os do
    boletim do aluno, via portal/_explicacao_visual.blade.php, que traduz o
    'pessoal' dele para 'leitura'.

    O comportamento (abrir/fechar/posicionar) vive em _viz.blade.php, num
    listener delegado — este parcial se repete dezenas de vezes por página e
    um listener por botão seria desperdício.

    position:fixed (não absolute) DE PROPÓSITO: os cards têm overflow-hidden
    para arredondar os cantos, e um popover absolute era cortado por eles.

    O atributo HTML `hidden` (não a classe Tailwind `hidden`) é o que controla
    show/hide: o JS alterna `conteudo.hidden`. Pôr a CLASSE `hidden` aqui
    seria o bug clássico de "clico e não acontece nada" — a regra
    `.hidden{display:none!important}` continuaria escondendo o popover mesmo
    depois de o JS remover o atributo.
--}}
@if (! empty($explicacao) && ! empty($explicacao['generico']))
    <button type="button" class="explicacao-toggle shrink-0 text-slate-300 hover:text-amber-500 transition-colors"
            aria-label="O que este visual significa e como analisá-lo">
        <i class="ph-fill ph-lightbulb text-base"></i>
    </button>
    <div class="explicacao-conteudo fixed z-50 w-72 sm:w-80 bg-white border border-slate-200 rounded-lg shadow-lg p-3 text-left normal-case tracking-normal" hidden>
        <p class="text-xs text-slate-600 font-normal leading-relaxed">{{ $explicacao['generico'] }}</p>
        @if (! empty($explicacao['leitura']))
            <p class="text-xs font-semibold text-slate-800 mt-2 pt-2 border-t border-slate-100 leading-relaxed">
                <span class="text-amber-600">Nesta avaliação:</span> {{ $explicacao['leitura'] }}
            </p>
        @endif
    </div>
@endif
