{{--
    Botão "o que isso significa" — abre um popover com a explicação genérica
    do visual (o que aquele tipo de gráfico mede, igual pra todo mundo) e a
    leitura pessoal do resultado deste aluno especificamente, calculada por
    App\Services\Portal\ExplicacaoVisualService. $no: nó da árvore com
    'explicacoes' já anexado; $chave: chave dentro de $no['explicacoes'].

    position:fixed (não absolute) DE PROPÓSITO: cada painel de categoria
    fica dentro de um card com overflow-hidden (pros cantos arredondados) —
    um popover absolute cortava/sumia dependendo de qual painel o botão
    estava. Fixed escapa desse corte; a posição (top/left) é calculada em JS
    no momento do clique — ver portalPosicionarExplicacao() em
    resultados.blade.php, que também fecha o popover ao rolar a página
    (senão ele ficaria "grudado" na tela depois que o botão já rolou pra
    outro lugar).

    O atributo HTML `hidden` (não a classe Tailwind `hidden`) é o que
    controla show/hide aqui, porque o JS alterna `conteudo.hidden = bool`
    (a propriedade do atributo). Colocar a CLASSE `hidden` no class="" ao
    lado seria o bug real por trás de "clico e não acontece nada": a regra
    `.hidden{display:none!important}` do Tailwind casa pela classe, não
    pelo atributo, e o `!important` continuaria escondendo o popover pra
    sempre mesmo depois do JS remover o atributo `hidden`.
--}}
@php $explicacao = $no['explicacoes'][$chave] ?? null; @endphp
@if ($explicacao)
    <button type="button" class="explicacao-toggle shrink-0 text-slate-300 hover:text-amber-500 transition-colors" aria-label="O que este gráfico significa">
        <i class="ph-fill ph-lightbulb text-base"></i>
    </button>
    <div class="explicacao-conteudo fixed z-50 w-64 sm:w-72 bg-white border border-slate-200 rounded-lg shadow-lg p-3 text-left normal-case tracking-normal" hidden>
        <p class="text-xs text-slate-600">{{ $explicacao['generico'] }}</p>
        @if ($explicacao['pessoal'])
            <p class="text-xs font-semibold text-slate-800 mt-2 pt-2 border-t border-slate-100">{{ $explicacao['pessoal'] }}</p>
        @endif
    </div>
@endif
