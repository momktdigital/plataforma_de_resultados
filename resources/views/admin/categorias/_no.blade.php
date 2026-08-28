{{-- $categoria, $porPai, $opcoesSelect vêm do include pai --}}
<li>
    <div class="flex items-center justify-between py-1.5 gap-2">
        <span class="text-sm">
            {{ $categoria->nome }}
            <span class="text-xs text-slate-400">({{ $categoria->avaliacoes_count }} avaliação(ões))</span>
        </span>
        <div class="flex items-center gap-3 shrink-0">
            <a href="{{ route('categorias.edit', $categoria) }}" class="text-xs text-emerald-700 hover:underline">Editar</a>
            <form method="POST" action="{{ route('categorias.destroy', $categoria) }}" class="inline">
                @csrf
                @method('DELETE')
                @if ($categoria->avaliacoes_count > 0)
                    <button type="button" class="categoria-excluir-btn text-xs text-red-500 hover:text-red-700"
                            data-categoria-id="{{ $categoria->id }}"
                            data-categoria-nome="{{ $categoria->nome }}"
                            data-avaliacoes-count="{{ $categoria->avaliacoes_count }}">
                        Excluir
                    </button>
                @else
                    <button type="submit" class="text-xs text-red-500 hover:text-red-700"
                            onclick="return confirm('Excluir a categoria {{ $categoria->nome }}?');">
                        Excluir
                    </button>
                @endif
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
