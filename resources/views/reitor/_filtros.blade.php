{{--
    Barra de filtros do painel da reitoria: período letivo, categoria ("todas" por padrão), avaliação (só as da
    categoria escolhida; "todas" por padrão) e os cursos que entram na visão. Vai antes do conteúdo; o formulário é um
    GET para a tela atual, então o recorte vira parte da URL (dá para guardar/compartilhar o endereço) e é mantido ao
    trocar de aba. Trocar o período ou a categoria volta a avaliação para "todas" (ela pode não existir no novo recorte).
    Variáveis: $ctx, $exportar (bool: mostra o link da planilha).
--}}
@php
    $manterExport = array_filter($ctx['filtro'], fn ($v) => $v !== '') + ($ctx['filtrando'] ? ['cursos' => $ctx['cursosSelecionados']] : []);
    $campo = 'w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white';
    $rotuloCampo = 'block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1';
    $filtro = $ctx['filtro'];
@endphp
<form method="GET" action="{{ url()->current() }}" class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 mb-6 flex flex-wrap items-end gap-3 print:hidden" aria-label="Filtros do painel">
    <div class="w-36">
        <label class="{{ $rotuloCampo }}" for="filtro-periodo">Período letivo</label>
        <select id="filtro-periodo" name="periodo" onchange="this.form.categoria.value = ''; this.form.avaliacao.value = ''; this.form.submit()" class="{{ $campo }}">
            <option value="*" @selected($filtro['periodo'] === '*')>Todos os períodos</option>
            @foreach ($ctx['periodos'] as $periodo)
                <option value="{{ $periodo === '' ? '-' : $periodo }}" @selected(($periodo === '' ? '-' : $periodo) === $filtro['periodo'])>{{ $periodo === '' ? 'Sem período letivo' : $periodo }}</option>
            @endforeach
        </select>
    </div>

    @php
        $itensCategoria = [['valor' => '', 'rotulo' => 'Todas as categorias', 'nivel' => 0]];
        foreach ($ctx['categoriasDoPeriodo'] as $valor => $categoria) {
            $itensCategoria[] = ['valor' => (string) $valor, 'rotulo' => $categoria['rotulo'], 'caminho' => $categoria['caminho'], 'nivel' => $categoria['nivel'], 'id' => (string) $valor, 'pai' => $categoria['pai'], 'extra' => $categoria['avaliacoes'] === ($categoria['totalAvaliacoes'] ?? $categoria['avaliacoes']) ? (string) $categoria['avaliacoes'] : $categoria['avaliacoes'].' de '.$categoria['totalAvaliacoes']];
        }
        $itensAvaliacao = [['valor' => '', 'rotulo' => 'Todas as avaliações ('.count($ctx['avaliacoesDaCategoria']).')', 'nivel' => 0]];
        foreach ($ctx['avaliacoesDaCategoria'] as $a) {
            $itensAvaliacao[] = ['valor' => $a['valor'], 'rotulo' => $a['rotulo'], 'caminho' => $a['rotulo'].' '.$a['categoria'], 'nivel' => 0, 'extra' => $a['extra']];
        }
    @endphp

    <div class="min-w-[230px] flex-1 max-w-xs">
        @include('reitor._combo', ['id' => 'filtro-categoria', 'nome' => 'categoria', 'rotulo' => 'Categoria', 'itens' => $itensCategoria, 'selecionado' => $filtro['categoria'], 'limpa' => 'avaliacao', 'busca' => 'Buscar categoria...'])
    </div>

    <div class="min-w-[260px] flex-1 max-w-md">
        @include('reitor._combo', ['id' => 'filtro-avaliacao', 'nome' => 'avaliacao', 'rotulo' => 'Avaliação', 'itens' => $itensAvaliacao, 'selecionado' => $filtro['avaliacao'], 'busca' => 'Buscar avaliação ou curso...'])
    </div>

    <div>
        <span class="{{ $rotuloCampo }}" id="rotulo-filtro-cursos">Cursos</span>
        <details class="relative" data-seletor-cursos>
            <summary aria-labelledby="rotulo-filtro-cursos" class="list-none cursor-pointer rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm min-w-[200px] flex items-center justify-between gap-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                <span data-seletor-rotulo>Todos os cursos ({{ count($ctx['cursosDisponiveis']) }})</span>
                <i class="ph ph-caret-down text-slate-500" aria-hidden="true"></i>
            </summary>
            <div class="absolute z-30 mt-1 w-80 max-w-[90vw] rounded-xl border border-slate-200 bg-white shadow-lg p-3">
                <div class="flex items-center justify-between mb-2 text-xs">
                    <button type="button" data-marcar-todos class="font-semibold text-emerald-700 hover:underline">Marcar todos</button>
                    <button type="button" data-limpar class="font-semibold text-slate-600 hover:underline">Limpar</button>
                </div>
                <label class="sr-only" for="busca-cursos">Buscar curso</label>
                <input id="busca-cursos" type="search" autocomplete="off" placeholder="Buscar curso..." data-cursos-busca class="mb-2 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
                <ul class="max-h-64 overflow-y-auto space-y-1">
                    @foreach ($ctx['cursosDisponiveis'] as $chave => $nome)
                        <li data-curso-item="{{ $nome }}">
                            <label class="flex items-center gap-2 text-sm rounded px-1 py-1 hover:bg-slate-50 cursor-pointer">
                                <input type="checkbox" name="cursos[]" value="{{ $chave }}" @checked($ctx['filtrando'] && in_array($chave, $ctx['cursosSelecionados'], true))>
                                <span class="inline-block h-2.5 w-2.5 rounded-full shrink-0" style="background: {{ $ctx['cores'][$chave] }}" aria-hidden="true"></span>
                                <span class="truncate" title="{{ $nome }}">{{ $nome }}</span>
                            </label>
                        </li>
                    @endforeach
                </ul>
                <button type="submit" class="mt-3 w-full bg-slate-800 hover:bg-slate-900 text-white font-semibold rounded-lg px-4 py-2 text-sm">Aplicar</button>
            </div>
        </details>
    </div>

    <noscript><button type="submit" class="bg-slate-800 text-white font-semibold rounded-lg px-4 py-2 text-sm">Filtrar</button></noscript>

    @if ($ctx['filtrando'])
        <a href="{{ url()->current() }}?{{ http_build_query(array_filter($ctx['filtro'], fn ($v) => $v !== '')) }}" class="text-sm font-semibold text-emerald-700 hover:underline pb-2">Ver todos os cursos</a>
    @endif

    @if (! empty($exportar))
        <a href="{{ route('reitor.xlsx', $manterExport) }}" class="ml-auto inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
            <i class="ph ph-microsoft-excel-logo text-lg" aria-hidden="true"></i> Baixar planilha (.xlsx)
        </a>
    @endif
</form>

@if ($ctx['avaliacao']['emOutrosPeriodos'] > 0)
    <div class="mb-6 -mt-3 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" role="note">
        <i class="ph-bold ph-info mr-1" aria-hidden="true"></i>
        Mostrando só o período letivo <strong>{{ $ctx['avaliacao']['periodoLetivo'] === '' ? 'sem período' : $ctx['avaliacao']['periodoLetivo'] }}</strong>:
        esta categoria tem mais <strong>{{ $ctx['avaliacao']['emOutrosPeriodos'] }}</strong> {{ $ctx['avaliacao']['emOutrosPeriodos'] === 1 ? 'avaliação' : 'avaliações' }} em outros períodos letivos.
        <a href="{{ url()->current() }}?{{ http_build_query(['periodo' => '*', 'categoria' => $ctx['filtro']['categoria']] + ($ctx['filtrando'] ? ['cursos' => $ctx['cursosSelecionados']] : [])) }}" class="font-semibold text-emerald-800 underline hover:no-underline">Ver todos os períodos</a>
    </div>
@endif

@if ($ctx['avaliacao']['todosPeriodos'])
    <div class="mb-6 -mt-3 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" role="note">
        <i class="ph-bold ph-info mr-1" aria-hidden="true"></i>
        Somando <strong>todos os períodos letivos</strong>: cada participação (estudante × avaliação) conta uma vez, então quem fez mais de uma avaliação
        ou participou em mais de um semestre aparece em cada uma. Os previstos de cada semestre vêm das matrículas daquele semestre.
    </div>
@endif

@if ($ctx['avaliacao']['mistura'])
    <div class="mb-6 -mt-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" role="note">
        <i class="ph-bold ph-warning mr-1" aria-hidden="true"></i>
        Este recorte reúne avaliações de <strong>categorias diferentes</strong>. Provas de categorias diferentes não são comparáveis em desempenho:
        a participação continua valendo, mas para ler proficiência, média e evolução escolha uma <strong>categoria</strong> no filtro.
    </div>
@endif
