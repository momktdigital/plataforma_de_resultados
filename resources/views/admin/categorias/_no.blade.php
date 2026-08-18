{{-- $categoria, $porPai vêm do include pai --}}
<li>
    <div class="flex items-center justify-between py-1.5">
        <span class="text-sm">
            {{ $categoria->nome }}
            <span class="text-xs text-slate-400">({{ $categoria->provas_count }} prova(s))</span>
        </span>
        <form method="POST" action="{{ route('categorias.destroy', $categoria) }}" class="inline"
              onsubmit="return confirm('Excluir a categoria {{ $categoria->nome }}?');">
            @csrf
            @method('DELETE')
            <button type="submit" class="text-xs text-red-500 hover:text-red-700">Excluir</button>
        </form>
    </div>

    @if ($porPai->has($categoria->id))
        <ul class="pl-5 border-l border-slate-200 ml-1">
            @foreach ($porPai->get($categoria->id) as $filho)
                @include('admin.categorias._no', ['categoria' => $filho, 'porPai' => $porPai])
            @endforeach
        </ul>
    @endif
</li>
