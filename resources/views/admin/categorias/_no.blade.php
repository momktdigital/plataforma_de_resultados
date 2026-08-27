{{-- $categoria, $porPai, $opcoesSelect vêm do include pai --}}
<li>
    <div class="flex items-center justify-between py-1.5 gap-2">
        <span class="text-sm">
            {{ $categoria->nome }}
            <span class="text-xs text-slate-400">({{ $categoria->avaliacoes_count }} avaliação(ões))</span>
        </span>
        <div class="flex items-center gap-3 shrink-0">
            <a href="{{ route('categorias.edit', $categoria) }}" class="text-xs text-emerald-700 hover:underline">Editar</a>
            <form method="POST" action="{{ route('categorias.destroy', $categoria) }}" class="inline flex items-center gap-1"
                  onsubmit="return confirm('Excluir a categoria {{ $categoria->nome }}?{{ $categoria->avaliacoes_count > 0 ? ' As avaliações vinculadas serão movidas para o destino escolhido ao lado.' : '' }}');">
                @csrf
                @method('DELETE')
                @if ($categoria->avaliacoes_count > 0)
                    <select name="mover_avaliacoes_para" class="text-xs border border-slate-300 rounded px-1 py-0.5"
                            title="Pra onde mover as {{ $categoria->avaliacoes_count }} avaliação(ões) vinculada(s)">
                        <option value="">— Sem categoria —</option>
                        @foreach ($opcoesSelect as $opcao)
                            @if ($opcao['id'] !== $categoria->id)
                                <option value="{{ $opcao['id'] }}">{{ $opcao['label'] }}</option>
                            @endif
                        @endforeach
                    </select>
                @endif
                <button type="submit" class="text-xs text-red-500 hover:text-red-700">Excluir</button>
            </form>
        </div>
    </div>

    @if ($porPai->has($categoria->id))
        <ul class="pl-5 border-l border-slate-200 ml-1">
            @foreach ($porPai->get($categoria->id) as $filho)
                @include('admin.categorias._no', ['categoria' => $filho, 'porPai' => $porPai, 'opcoesSelect' => $opcoesSelect])
            @endforeach
        </ul>
    @endif
</li>
