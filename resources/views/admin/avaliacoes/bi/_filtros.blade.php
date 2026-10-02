{{-- Dashboard: filtros de período/turma/demografia e a nota de quais visuais eles afetam. --}}
<form method="GET" action="{{ route('avaliacoes.bi', $avaliacao) }}" class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 mb-2 flex flex-wrap gap-3 items-end">
    <div>
        <label class="block text-xs font-medium text-slate-500 mb-1" for="filtro-periodo">Período</label>
        <select id="filtro-periodo" name="periodo" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">Todos</option>
            @foreach ($periodosDisponiveis as $p)
                <option value="{{ $p }}" {{ $periodo === $p ? 'selected' : '' }}>{{ $p === '' ? '(sem período)' : $p }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-500 mb-1" for="filtro-turma">Turma</label>
        <select id="filtro-turma" name="turma" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">Todas</option>
            @foreach ($opcoesFiltro['turmas'] as $t)
                <option value="{{ $t }}" {{ $filtro->turma === $t ? 'selected' : '' }}>{{ $t }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-500 mb-1" for="filtro-sexo">Sexo</label>
        <select id="filtro-sexo" name="sexo" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">Todos</option>
            @foreach ($opcoesFiltro['sexos'] as $s)
                <option value="{{ $s }}" {{ $filtro->sexo === $s ? 'selected' : '' }}>{{ $s }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-500 mb-1" for="filtro-cor-raca">Cor/raça</label>
        <select id="filtro-cor-raca" name="cor_raca" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">Todas</option>
            @foreach ($opcoesFiltro['corRacas'] as $c)
                <option value="{{ $c }}" {{ $filtro->corRaca === $c ? 'selected' : '' }}>{{ $c }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-500 mb-1" for="filtro-faixa-etaria">Faixa etária</label>
        <select id="filtro-faixa-etaria" name="faixa_etaria" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">Todas</option>
            @foreach ($opcoesFiltro['faixasEtarias'] as $f)
                <option value="{{ $f }}" {{ $filtro->faixaEtaria === $f ? 'selected' : '' }}>{{ $f }}</option>
            @endforeach
        </select>
    </div>
    <button type="submit" class="bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-lg px-4 py-2 text-sm">
        Filtrar
    </button>
    @if (! $filtro->vazio() || $periodo !== '')
        <a href="{{ route('avaliacoes.bi', $avaliacao) }}" class="text-sm text-slate-500 hover:underline px-1 py-2">Limpar filtros</a>
    @endif
</form>
<p class="text-xs text-slate-500 mb-6">
    Turma e demografia (sexo, cor/raça, faixa etária) se aplicam a: Distribuição de acertos, Distribuição por turma,
    Mapa de calor, Análise de alternativas e Correlação com métricas. Os demais visuais respeitam apenas o período.
</p>
