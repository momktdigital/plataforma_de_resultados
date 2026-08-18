{{-- $no: ['categoria' => Categoria, 'resultados' => [...], 'subcategorias' => [...]] --}}
<li class="categoria-no border border-slate-200 rounded-2xl bg-white shadow-sm overflow-hidden">
    <button type="button" onclick="portalToggleCategoria(this)"
            class="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-slate-50 transition-colors">
        <span class="font-bold text-slate-800 flex items-center gap-2">
            <i class="ph-fill ph-folder text-primary"></i> {{ $no['categoria']->nome }}
        </span>
        <i class="ph-bold ph-caret-down categoria-seta text-slate-400 transition-transform"></i>
    </button>
    <div class="categoria-conteudo px-5 pb-5 space-y-4 border-t border-slate-100 pt-4" hidden>
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
