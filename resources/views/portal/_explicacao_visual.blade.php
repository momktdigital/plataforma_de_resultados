{{--
    Botão "o que isso significa" — abre um popover com a explicação genérica
    do visual (o que aquele tipo de gráfico mede, igual pra todo mundo) e a
    leitura pessoal do resultado deste aluno especificamente, calculada por
    App\Services\Portal\ExplicacaoVisualService. $no: nó da árvore com
    'explicacoes' já anexado; $chave: chave dentro de $no['explicacoes'].
    O toggle/fechamento é feito por um listener único e delegado em
    resultados.blade.php (portalRedimensionarGraficos/clique global) — não
    duplica listener por botão, já que este parcial é incluído várias vezes.
--}}
@php $explicacao = $no['explicacoes'][$chave] ?? null; @endphp
@if ($explicacao)
    <div class="relative shrink-0">
        <button type="button" class="explicacao-toggle text-slate-300 hover:text-amber-500 transition-colors" aria-label="O que este gráfico significa">
            <i class="ph-fill ph-lightbulb text-base"></i>
        </button>
        <div class="explicacao-conteudo hidden absolute right-0 top-full mt-1 w-64 sm:w-72 z-20 bg-white border border-slate-200 rounded-lg shadow-lg p-3 text-left normal-case tracking-normal">
            <p class="text-xs text-slate-600">{{ $explicacao['generico'] }}</p>
            @if ($explicacao['pessoal'])
                <p class="text-xs font-semibold text-slate-800 mt-2 pt-2 border-t border-slate-100">{{ $explicacao['pessoal'] }}</p>
            @endif
        </div>
    </div>
@endif
