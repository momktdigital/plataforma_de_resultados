{{--
    Lista de cursos com checkbox e filtro por texto.
    Variáveis: $nome (name do input, sem []), $opcoes (array de nomes), $selecionados (array de nomes), $id (prefixo único).
--}}
@php
    $marcados = old($nome) !== null ? collect(old($nome)) : collect($selecionados ?? []);
    $marcados = $marcados->map(fn ($c) => \App\Support\NomeCurso::chave($c))->all();
    // Cursos já marcados que não existem mais na lista (ex.: curso sem aluno) continuam visíveis;
    // grafias que só diferem em acento/caixa são o mesmo curso.
    $opcoes = collect(\App\Support\NomeCurso::unicos([...$opcoes, ...($selecionados ?? [])]));
@endphp
<div>
    <input type="text" id="{{ $id }}-filtro" placeholder="Filtrar cursos..." autocomplete="off"
           class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm mb-2"
           oninput="(function(t){document.querySelectorAll('#{{ $id }}-lista label').forEach(function(l){l.classList.toggle('hidden', l.textContent.toLowerCase().indexOf(t.toLowerCase()) === -1);});})(this.value)">
    <div id="{{ $id }}-lista" class="max-h-48 overflow-y-auto rounded-lg border border-slate-200 divide-y divide-slate-100">
        @forelse ($opcoes as $curso)
            <label class="flex items-center gap-2 px-3 py-1.5 text-sm hover:bg-slate-50 cursor-pointer">
                <input type="checkbox" name="{{ $nome }}[]" value="{{ $curso }}" class="rounded border-slate-300"
                       {{ in_array(\App\Support\NomeCurso::chave($curso), $marcados, true) ? 'checked' : '' }}>
                <span>{{ $curso }}</span>
            </label>
        @empty
            <p class="px-3 py-2 text-sm text-slate-500">Nenhum curso cadastrado ainda — importe a matrícula dos alunos.</p>
        @endforelse
    </div>
</div>
