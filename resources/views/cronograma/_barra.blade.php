{{--
    Barra do cronograma: alternar entre Calendário e Lista + filtros (os mesmos nas duas visões; o intervalo de datas só
    na lista, porque o calendário já é de um mês). Trocar de visão mantém os filtros.
    Variáveis:
      $rota          nome da rota da tela
      $visao         'calendario' | 'lista'
      $filtros       busca, rotina, curso, status, de, ate (CronogramaService::filtros)
      $opcoesCurso   cursos para o filtro (vazio ou 1 = sem o seletor)
      $mes           (calendário) mês em foco, AAAA-MM
      $extra         (opcional) outros parâmetros a manter (ex.: pendencias=todas)
--}}
@php
    $extra = $extra ?? [];
    $campo = 'rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary';
    $manter = array_filter([...$extra, ...$filtros], fn ($v) => $v !== '' && $v !== null);
    $porVisao = fn (string $v) => route($rota, array_filter([...$manter, 'visao' => $v === 'lista' ? 'lista' : '', 'mes' => $v === 'calendario' ? ($mes ?? '') : '']));
    $comFiltro = array_filter($filtros) !== [];
    $abas = [['calendario', 'ph-calendar-blank', 'Calendário'], ['lista', 'ph-list-bullets', 'Lista']];
@endphp
<div class="mb-4">
    <nav class="mb-3 inline-flex rounded-xl border border-slate-300 bg-white p-1" aria-label="Forma de ver as atividades">
        @foreach ($abas as [$id, $icone, $rotulo])
            <a href="{{ $porVisao($id) }}" @if ($visao === $id) aria-current="page" @endif
               class="inline-flex items-center gap-2 rounded-lg px-4 py-1.5 text-sm font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-primary {{ $visao === $id ? 'bg-slate-800 text-white' : 'text-slate-700 hover:bg-slate-100' }}">
                <i class="ph-bold {{ $icone }}" aria-hidden="true"></i> {{ $rotulo }}
            </a>
        @endforeach
    </nav>

    <form method="GET" action="{{ route($rota) }}" class="flex flex-wrap items-end gap-3" aria-label="Filtrar as atividades">
        @foreach ($extra as $nome => $valor)
            @if ($valor !== '' && $valor !== null)<input type="hidden" name="{{ $nome }}" value="{{ $valor }}">@endif
        @endforeach
        @if ($visao === 'lista')
            <input type="hidden" name="visao" value="lista">
        @else
            <input type="hidden" name="mes" value="{{ $mes }}">
        @endif
        <div>
            <label for="filtro-busca" class="mb-1 block text-xs font-semibold text-slate-600">Buscar</label>
            <input id="filtro-busca" name="busca" type="search" maxlength="100" value="{{ $filtros['busca'] }}" placeholder="Projeto ou o que conferir" class="{{ $campo }} w-56">
        </div>
        <div>
            <label for="filtro-rotina" class="mb-1 block text-xs font-semibold text-slate-600">Rotina</label>
            <select id="filtro-rotina" name="rotina" class="{{ $campo }}">
                <option value="">Todas</option>
                @foreach (\App\Models\CronogramaItem::ROTINAS as $sigla => $quem)
                    <option value="{{ $sigla }}" @selected($sigla === $filtros['rotina'])>{{ $sigla }} — {{ $quem }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filtro-status" class="mb-1 block text-xs font-semibold text-slate-600">Situação</label>
            <select id="filtro-status" name="status" class="{{ $campo }}">
                <option value="">Todas</option>
                @foreach (\App\Models\CronogramaItem::STATUS as $valor => $rotulo)
                    <option value="{{ $valor }}" @selected($valor === $filtros['status'])>{{ $rotulo }}</option>
                @endforeach
            </select>
        </div>
        @if (count($opcoesCurso ?? []) > 1)
            <div>
                <label for="filtro-curso" class="mb-1 block text-xs font-semibold text-slate-600">Curso</label>
                <select id="filtro-curso" name="curso" class="{{ $campo }} max-w-[15rem]">
                    <option value="">Todos os cursos</option>
                    @foreach ($opcoesCurso as $c)
                        <option value="{{ $c }}" @selected($c === $filtros['curso'])>{{ $c }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        @if ($visao === 'lista')
            <div>
                <label for="filtro-de" class="mb-1 block text-xs font-semibold text-slate-600">De</label>
                <input id="filtro-de" name="de" type="date" value="{{ $filtros['de'] }}" class="{{ $campo }}">
            </div>
            <div>
                <label for="filtro-ate" class="mb-1 block text-xs font-semibold text-slate-600">Até</label>
                <input id="filtro-ate" name="ate" type="date" value="{{ $filtros['ate'] }}" class="{{ $campo }}">
            </div>
        @endif
        <button type="submit" class="rounded-lg bg-slate-800 hover:bg-slate-900 px-4 py-2 text-sm font-semibold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">Filtrar</button>
        @if ($comFiltro)
            <a href="{{ route($rota, array_filter([...$extra, 'visao' => $visao === 'lista' ? 'lista' : '', 'mes' => $visao === 'calendario' ? ($mes ?? '') : ''])) }}"
               class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 underline hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">Limpar filtros</a>
        @endif
    </form>
</div>
