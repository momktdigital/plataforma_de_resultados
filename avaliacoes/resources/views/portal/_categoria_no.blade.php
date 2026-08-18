{{-- $no: ['categoria' => Categoria, 'resultados' => [...], 'subcategorias' => [...]] --}}
<li class="categoria-no border border-slate-200 rounded-xl bg-white overflow-hidden">
    <button type="button" onclick="portalToggleCategoria(this)"
            class="w-full flex items-center justify-between px-4 py-3 text-left font-semibold hover:bg-slate-50">
        <span>{{ $no['categoria']->nome }}</span>
        <span class="text-slate-400 text-sm">&#9662;</span>
    </button>
    <div class="categoria-conteudo px-4 pb-4 space-y-4" hidden>
        @if (! empty($no['subcategorias']))
            <ul class="space-y-3">
                @foreach ($no['subcategorias'] as $filho)
                    @include('portal._categoria_no', ['no' => $filho])
                @endforeach
            </ul>
        @endif

        @foreach ($no['resultados'] as $r)
            @include('portal._prova_card', ['r' => $r])
        @endforeach
    </div>
</li>
