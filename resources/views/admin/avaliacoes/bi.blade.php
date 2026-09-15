@extends('layouts.app')

@section('title', "Painel BI — Avaliação #{$avaliacao->codigo}")

@section('content')
<div class="flex items-start justify-between gap-4 mb-2">
    <a href="{{ route('avaliacoes.show', $avaliacao) }}" class="text-sm text-slate-500 hover:underline">&larr; Avaliacao #{{ $avaliacao->codigo }}</a>
    <a href="{{ route('avaliacoes.visualizacoes.edit', $avaliacao) }}" class="text-sm text-slate-500 hover:underline">Configurar visualizações &rarr;</a>
</div>
<h1 class="text-2xl font-bold mt-2 mb-6">Painel BI</h1>

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
<p class="text-xs text-slate-400 mb-6">
    Turma e demografia (sexo, cor/raça, faixa etária) se aplicam a: Distribuição de acertos, Distribuição por turma,
    Mapa de calor, Análise de alternativas e Correlação com métricas. Os demais visuais respeitam apenas o período.
</p>

@if (! empty($dados['semGabarito']))
    <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-xl p-6 text-sm mb-6">
        Esta avaliação ainda não tem gabarito cadastrado — importe ou cadastre as questões antes de ver o painel.
    </div>
@elseif (! empty($dados['semRespostas']))
    <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-xl p-6 text-sm mb-6">
        Nenhum resultado importado ainda para este filtro.
    </div>
@endif

@php
    $temAlgumVisivel = collect($estado)->contains(fn ($item) => $item['visivelAdmin']);
@endphp

@if (! $temAlgumVisivel && empty($dados['semGabarito']) && empty($dados['semRespostas']))
    <div class="bg-slate-50 border border-slate-200 text-slate-500 rounded-xl p-6 text-sm">
        Nenhum visual está habilitado para o administrativo nesta avaliação.
        <a href="{{ route('avaliacoes.visualizacoes.edit', $avaliacao) }}" class="text-emerald-700 font-medium hover:underline">Configure aqui.</a>
    </div>
@endif

@if ($estado['estatisticas_gerais']['visivelAdmin'] && $psicometria !== null)
    @php
        $kr20 = $psicometria['kr20'];
        $kr20Bom = $kr20 !== null && $kr20 >= 0.80;
        $kr20Aceitavel = $kr20 !== null && $kr20 >= 0.70;
    @endphp
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-6">
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Média da turma</p>
            <p class="text-3xl font-bold mt-2 tracking-tight">{{ number_format($psicometria['media'], 1, ',', '.') }}<span class="text-lg font-medium text-slate-500">%</span></p>
            <p class="text-xs text-slate-500 mt-1">{{ $psicometria['respondentes'] }} respondente(s) · {{ $psicometria['questoes'] }} questão(ões)</p>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Mediana</p>
            <p class="text-3xl font-bold mt-2 tracking-tight">{{ number_format($psicometria['mediana'], 1, ',', '.') }}<span class="text-lg font-medium text-slate-500">%</span></p>
            <p class="text-xs text-slate-500 mt-1">metade da turma ficou acima disto</p>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Desvio-padrão</p>
            <p class="text-3xl font-bold mt-2 tracking-tight">{{ number_format($psicometria['desvio'], 1, ',', '.') }}<span class="text-lg font-medium text-slate-500">pp</span></p>
            <p class="text-xs text-slate-500 mt-1">o quanto as notas se espalham</p>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Confiabilidade (KR-20)</p>
            @if ($kr20 === null)
                <p class="text-3xl font-bold mt-2 tracking-tight text-slate-300">—</p>
                <p class="text-xs text-slate-500 mt-1">sem variação de notas suficiente para calcular</p>
            @else
                <p class="text-3xl font-bold mt-2 tracking-tight">{{ number_format($kr20, 2, ',', '.') }}</p>
                <div class="h-1.5 rounded-full bg-slate-100 mt-3 overflow-hidden">
                    <div class="h-full rounded-full" style="width: {{ round($kr20 * 100) }}%; background-color: {{ $kr20Aceitavel ? '#12a37f' : '#d03b3b' }}"></div>
                </div>
                <p class="text-xs mt-2 font-medium {{ $kr20Bom ? 'text-emerald-700' : ($kr20Aceitavel ? 'text-amber-700' : 'text-red-700') }}">
                    {{ $kr20Bom ? 'Consistência adequada' : ($kr20Aceitavel ? 'Aceitável — dá para melhorar' : 'Baixa: a prova mede muito ao acaso') }}
                </p>
            @endif
        </div>
    </div>
@endif

@if ($estado['mapa_itens']['visivelAdmin'] && $psicometria !== null && ! empty($psicometria['itens']))
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <div class="flex items-start justify-between gap-4 flex-wrap mb-1">
            <h2 class="font-semibold">Mapa de qualidade dos itens</h2>
            <button type="button" data-tabela="tabela-mapa-itens" aria-expanded="false"
                    class="text-xs text-slate-500 hover:text-slate-800 border border-slate-200 rounded-lg px-2.5 py-1">
                Ver como tabela
            </button>
        </div>
        <p class="text-sm text-slate-500 mb-4">
            Cada ponto é uma questão. O eixo horizontal é quanto a turma acertou; o vertical é o quanto a questão
            separa quem foi bem de quem foi mal (faixas de Ebel). Clique num ponto para ver a curva característica dele.
            Questões anuladas ficam de fora.
        </p>

        <div class="grid lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2">
                <canvas id="grafico-mapa-itens" height="260"></canvas>
            </div>
            <div class="border border-slate-200 rounded-xl p-4">
                <div class="flex items-center gap-2 flex-wrap">
                    <span id="item-numero" class="text-xl font-bold tracking-tight">—</span>
                    <span id="item-faixa" class="text-xs font-semibold px-2 py-0.5 rounded"></span>
                </div>
                <p id="item-contexto" class="text-xs text-slate-500 mt-0.5">Clique num ponto do gráfico</p>

                <div class="grid grid-cols-3 gap-2 mt-4 pt-3 border-t border-slate-100">
                    <div>
                        <span class="block text-[10px] font-semibold uppercase tracking-wide text-slate-400">Acerto</span>
                        <b id="item-p" class="text-base font-bold tabular-nums">—</b>
                    </div>
                    <div>
                        <span class="block text-[10px] font-semibold uppercase tracking-wide text-slate-400">Discrim.</span>
                        <b id="item-d" class="text-base font-bold tabular-nums">—</b>
                    </div>
                    <div>
                        <span class="block text-[10px] font-semibold uppercase tracking-wide text-slate-400">Em branco</span>
                        <b id="item-branco" class="text-base font-bold tabular-nums">—</b>
                    </div>
                </div>

                <p class="text-xs font-medium text-slate-500 mt-4 mb-1">Curva característica</p>
                <canvas id="grafico-cci" height="150"></canvas>
                <p id="item-acao" class="text-xs text-slate-600 mt-3"></p>
            </div>
        </div>

        @if ($psicometria['simulacao'] !== null)
            <div class="mt-5 bg-slate-50 border-l-4 rounded-r-lg px-4 py-3 text-sm text-slate-600" style="border-color: #eb6834">
                <b class="text-slate-800">E se…</b>
                removendo {{ count($psicometria['simulacao']['removidos']) }} questão(ões) com discriminação abaixo de 0,20
                ({{ collect($psicometria['simulacao']['removidos'])->take(3)->map(fn ($n) => 'Q'.$n)->implode(', ') }}{{ count($psicometria['simulacao']['removidos']) > 3 ? '…' : '' }}),
                o KR-20 da prova
                @if ($psicometria['simulacao']['ganho'] > 0)
                    sobe para <b class="text-slate-800">{{ number_format($psicometria['simulacao']['kr20'], 2, ',', '.') }}</b>
                    (+{{ number_format($psicometria['simulacao']['ganho'], 2, ',', '.') }}).
                @else
                    não melhora — o problema desta prova não está concentrado nesses itens.
                @endif
            </div>
        @endif

        <div id="tabela-mapa-itens" hidden class="mt-5 overflow-x-auto">
            <table class="w-full text-sm">
                <caption class="text-left text-xs text-slate-500 pb-2">Questões com discriminação abaixo de 0,20</caption>
                <thead class="bg-slate-50 text-slate-500 text-left text-xs uppercase">
                    <tr><th class="px-3 py-2">Questão</th><th class="px-3 py-2">Acerto</th><th class="px-3 py-2">Discriminação</th><th class="px-3 py-2">Faixa</th><th class="px-3 py-2">O que fazer</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach (collect($psicometria['itens'])->filter(fn ($i) => $i['discriminacao'] === null || $i['discriminacao'] < 0.20)->sortBy('discriminacao') as $item)
                        <tr>
                            <td class="px-3 py-2 font-medium">Q{{ $item['numero'] }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ number_format($item['dificuldade'], 1, ',', '.') }}%</td>
                            <td class="px-3 py-2 tabular-nums">{{ $item['discriminacao'] === null ? '—' : number_format($item['discriminacao'], 2, ',', '.') }}</td>
                            <td class="px-3 py-2">{{ $item['rotulo'] }}</td>
                            <td class="px-3 py-2 text-slate-500">{{ $item['acao'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

@if ($estado['histograma']['visivelAdmin'])
    @if (empty($dados['semGabarito']) && empty($dados['semRespostas']) && ! empty($dados))
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
            <h2 class="font-semibold mb-4">Distribuição de acertos ({{ $dados['totalRespondentes'] }} respondente(s))</h2>
            <canvas id="grafico-histograma" height="100"></canvas>
        </div>
    @endif
@endif

@if ($estado['radar_disciplina']['visivelAdmin'])
    @if (empty($dados['semGabarito']) && empty($dados['semRespostas']) && ! empty($dados))
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
            <h2 class="font-semibold mb-4">Desempenho médio por disciplina</h2>
            @if (empty($dados['radar']))
                <p class="text-sm text-slate-400">Nenhuma questão desta avaliação tem disciplina cadastrada na matriz.</p>
            @else
                <div class="max-w-xl mx-auto">
                    <canvas id="grafico-radar" height="220"></canvas>
                </div>
            @endif
        </div>
    @endif
@endif

@if ($estado['ranking_completo']['visivelAdmin'] && $rankingCompleto !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-slate-100 font-semibold">Ranking completo ({{ count($rankingCompleto) }} respondente(s))</div>
        <div class="max-h-96 overflow-y-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-left sticky top-0">
                    <tr>
                        <th class="px-4 py-3">#</th>
                        <th class="px-4 py-3">Aluno</th>
                        <th class="px-4 py-3">RA</th>
                        <th class="px-4 py-3">Turma</th>
                        <th class="px-4 py-3">Acertos</th>
                        <th class="px-4 py-3">%</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($rankingCompleto as $i => $r)
                        <tr>
                            <td class="px-4 py-3 text-slate-400">{{ $i + 1 }}</td>
                            <td class="px-4 py-3 font-medium">{{ $r['aluno_nome'] ?: '—' }}</td>
                            <td class="px-4 py-3">{{ $r['ra'] ?: '—' }}</td>
                            <td class="px-4 py-3">{{ $r['turma'] ?: '—' }}</td>
                            <td class="px-4 py-3">{{ $r['acertos'] }}/{{ $r['total'] }}</td>
                            <td class="px-4 py-3">
                                <div class="relative w-24">
                                    <div class="absolute inset-y-0 left-0 bg-emerald-100 rounded" style="width: {{ $r['percentual'] }}%"></div>
                                    <span class="relative font-bold text-emerald-800 px-1">{{ $r['percentual'] }}%</span>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

@if ($estado['distribuicao_turma']['visivelAdmin'] && $distribuicaoTurma !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <h2 class="font-semibold mb-4">Distribuição de notas por turma</h2>
        @if (empty($distribuicaoTurma))
            <p class="text-sm text-slate-400">Sem dados suficientes.</p>
        @else
            <canvas id="grafico-turma" height="220"></canvas>
        @endif
    </div>
@endif

@if ($estado['curva_dificuldade']['visivelAdmin'] && $curvaDificuldade !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <h2 class="font-semibold mb-1">Dificuldade pedagógica: esperado x observado</h2>
        <p class="text-sm text-slate-500 mb-4">% de acerto observado por nível de dificuldade cadastrado nas questões.</p>
        @if (empty($curvaDificuldade))
            <p class="text-sm text-slate-400">Sem dados suficientes.</p>
        @else
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-left">
                    <tr><th class="px-4 py-2">Dificuldade esperada</th><th class="px-4 py-2">Questões</th><th class="px-4 py-2">% de acerto observado</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($curvaDificuldade as $linha)
                        <tr>
                            <td class="px-4 py-2">{{ $linha['esperado'] }}</td>
                            <td class="px-4 py-2">{{ $linha['questoes'] }}</td>
                            <td class="px-4 py-2 font-bold">{{ $linha['observado'] }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endif

@if ($estado['dispersao_tri']['visivelAdmin'] && $dispersaoTri !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <h2 class="font-semibold mb-1">Dispersão TRI x taxa de acerto observada</h2>
        <p class="text-sm text-slate-500 mb-4">Cada ponto é uma questão — eixo X: dificuldade TRI cadastrada, eixo Y: % de acerto observado.</p>
        @if (empty($dispersaoTri))
            <p class="text-sm text-slate-400">Sem dados suficientes.</p>
        @else
            <canvas id="grafico-tri" height="220"></canvas>
        @endif
    </div>
@endif

@if ($estado['heatmap_habilidade_turma']['visivelAdmin'] && $heatmapHabilidadeTurma !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6 overflow-x-auto">
        <h2 class="font-semibold mb-4">Mapa de calor: habilidade x turma (% de acerto)</h2>
        @if (empty($heatmapHabilidadeTurma))
            <p class="text-sm text-slate-400">Sem dados suficientes.</p>
        @else
            @php $todasTurmas = collect($heatmapHabilidadeTurma)->flatMap(fn ($t) => array_keys($t))->unique()->sort()->values(); @endphp
            <table class="text-sm border-collapse">
                <thead>
                    <tr>
                        <th class="px-3 py-2 text-left text-slate-500">Habilidade</th>
                        @foreach ($todasTurmas as $turma)
                            <th class="px-3 py-2 text-slate-500 whitespace-nowrap">{{ $turma }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($heatmapHabilidadeTurma as $habilidade => $porTurma)
                        <tr>
                            <td class="px-3 py-2 font-medium whitespace-nowrap">{{ $habilidade }}</td>
                            @foreach ($todasTurmas as $turma)
                                @php $valor = $porTurma[$turma] ?? null; @endphp
                                <td class="px-3 py-2 text-center text-white font-semibold"
                                    style="background-color: {{ $valor === null ? '#e2e8f0' : 'rgba(18,163,127,'.max(0.15, $valor / 100).')' }}; color: {{ $valor === null ? '#94a3b8' : '#0f172a' }}">
                                    {{ $valor !== null ? $valor.'%' : '—' }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endif

@if ($estado['perfil_demografico']['visivelAdmin'] && $perfilDemografico !== null)
    <div class="grid lg:grid-cols-3 gap-6 mb-6">
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
            <h2 class="font-semibold mb-3">Sexo</h2>
            @if (empty($perfilDemografico['sexo']))
                <p class="text-sm text-slate-400">Sem dados.</p>
            @else
                <canvas id="grafico-sexo" height="200"></canvas>
            @endif
        </div>

        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
            <h2 class="font-semibold mb-3">Cor/raça</h2>
            @if (empty($perfilDemografico['cor_raca']))
                <p class="text-sm text-slate-400">Sem dados.</p>
            @else
                <canvas id="grafico-cor-raca" height="200"></canvas>
            @endif
        </div>

        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
            <h2 class="font-semibold mb-3">UF</h2>
            @if (empty($perfilDemografico['uf']))
                <p class="text-sm text-slate-400">Sem dados.</p>
            @else
                @php
                    $maximoUf = max($perfilDemografico['uf']);
                    $topUf = array_slice($perfilDemografico['uf'], 0, 8, true);
                @endphp
                <svg viewBox="{{ \App\Support\MapaBrasilSvg::viewBox() }}" class="w-full h-auto mb-3">
                    @foreach (\App\Support\MapaBrasilSvg::caminhos() as $uf => $d)
                        @php $valorUf = $perfilDemografico['uf'][$uf] ?? null; @endphp
                        <path d="{{ $d }}" fill="{{ \App\Support\MapaBrasilSvg::corPorValor($valorUf, $maximoUf) }}"
                              stroke="#fff" stroke-width="1"><title>{{ $uf }}: {{ $valorUf ?? 0 }}</title></path>
                    @endforeach
                </svg>
                <ul class="text-xs space-y-1">
                    @foreach ($topUf as $uf => $total)
                        <li class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-sm shrink-0" style="background-color: {{ \App\Support\MapaBrasilSvg::corPorValor($total, $maximoUf) }}"></span>
                            <span class="text-slate-600 flex-1">{{ $uf }}</span>
                            <span class="font-bold">{{ $total }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endif

@if ($estado['desempenho_area']['visivelAdmin'] && $mediaPorArea !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <h2 class="font-semibold mb-4">Desempenho por área</h2>
        @if (empty($mediaPorArea))
            <p class="text-sm text-slate-400">Sem dados suficientes.</p>
        @else
            @php $areasOrdenadas = collect($mediaPorArea)->sortDesc(); @endphp
            <div class="grid lg:grid-cols-2 gap-6 items-center">
                <div class="max-w-md mx-auto w-full">
                    <canvas id="grafico-area" height="320"></canvas>
                </div>
                <ul class="space-y-2.5">
                    @foreach ($areasOrdenadas as $area => $percentual)
                        <li>
                            <div class="flex items-center justify-between text-sm mb-1">
                                <span class="font-medium text-slate-600">{{ $area }}</span>
                                <span class="font-bold text-slate-800">{{ $percentual }}%</span>
                            </div>
                            <div class="h-2 bg-slate-100 rounded-full overflow-hidden">
                                <div class="h-full bg-emerald-500 rounded-full" style="width: {{ $percentual }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
@endif

@if ($estado['desempenho_tema']['visivelAdmin'] && $desempenhoPorTema !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <h2 class="font-semibold mb-4">Desempenho por tema</h2>
        @if (empty($desempenhoPorTema))
            <p class="text-sm text-slate-400">Sem dados suficientes.</p>
        @else
            @php
                $temasMenorAcerto = collect($desempenhoPorTema)->sortBy('percentual')->take(10);
                $temasMaiorAcerto = collect($desempenhoPorTema)->sortByDesc('percentual')->take(10);
            @endphp
            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <p class="text-xs font-bold text-red-600 uppercase tracking-wide mb-3">Temas com menor acerto</p>
                    <ul class="divide-y divide-slate-100">
                        @foreach ($temasMenorAcerto as $t)
                            <li class="py-2 flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-slate-700 truncate">{{ $t['tema'] }}</p>
                                    <p class="text-xs text-slate-400 truncate">{{ $t['area'] ?? '—' }}</p>
                                </div>
                                <span class="text-sm font-bold text-red-600 shrink-0">{{ $t['percentual'] }}%</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div>
                    <p class="text-xs font-bold text-emerald-700 uppercase tracking-wide mb-3">Temas com maior acerto</p>
                    <ul class="divide-y divide-slate-100">
                        @foreach ($temasMaiorAcerto as $t)
                            <li class="py-2 flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-slate-700 truncate">{{ $t['tema'] }}</p>
                                    <p class="text-xs text-slate-400 truncate">{{ $t['area'] ?? '—' }}</p>
                                </div>
                                <span class="text-sm font-bold text-emerald-700 shrink-0">{{ $t['percentual'] }}%</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif
    </div>
@endif

@if (($estado['desempenho_bloom']['visivelAdmin'] || $estado['desempenho_miller']['visivelAdmin']) && ($mediaPorBloom !== null || $mediaPorMiller !== null))
    <div class="grid lg:grid-cols-2 gap-6 mb-6">
        @if ($estado['desempenho_bloom']['visivelAdmin'] && $mediaPorBloom !== null)
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
                <h2 class="font-semibold mb-4">Desempenho médio por nível de Bloom</h2>
                @if (empty($mediaPorBloom))
                    <p class="text-sm text-slate-400">Sem dados suficientes.</p>
                @else
                    <canvas id="grafico-bloom" height="220"></canvas>
                @endif
            </div>
        @endif
        @if ($estado['desempenho_miller']['visivelAdmin'] && $mediaPorMiller !== null)
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
                <h2 class="font-semibold mb-4">Desempenho médio por nível de Miller</h2>
                @if (empty($mediaPorMiller))
                    <p class="text-sm text-slate-400">Sem dados suficientes.</p>
                @else
                    <canvas id="grafico-miller" height="220"></canvas>
                @endif
            </div>
        @endif
    </div>
@endif

@if ($estado['analise_alternativas']['visivelAdmin'] && $analiseAlternativas !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <h2 class="font-semibold mb-1">Análise de alternativas por questão <span id="alternativas-ordenacao-label" class="font-normal text-slate-400 text-sm">(ordenado por % de acerto)</span></h2>
        @if (empty($analiseAlternativas))
            <p class="text-sm text-slate-400">Sem dados suficientes.</p>
        @else
            <p class="text-xs text-slate-500 mb-4 flex flex-wrap items-center gap-4">
                <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm bg-emerald-100 border border-emerald-300 inline-block"></span> gabarito</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm bg-amber-100 border border-amber-300 inline-block"></span> distrator (alternativa errada mais marcada)</span>
                <span class="inline-flex items-center gap-1.5"><span class="font-bold">*</span> questão anulada — não conta na nota</span>
            </p>
            <div class="overflow-x-auto max-h-[40rem] overflow-y-auto">
                <table id="tabela-alternativas" class="text-sm border-collapse w-full">
                    <thead>
                        <tr class="sticky top-0 bg-white z-10 text-left text-slate-500">
                            <th class="px-3 py-2">
                                <button type="button" onclick="ordenarTabelaAlternativas('numero')" class="font-semibold hover:text-emerald-700 hover:underline">Questão</button>
                            </th>
                            <th class="px-3 py-2">Área</th>
                            <th class="px-3 py-2">Tema</th>
                            <th class="px-3 py-2">Gabarito</th>
                            <th class="px-3 py-2">
                                <button type="button" onclick="ordenarTabelaAlternativas('percentual')" class="font-semibold hover:text-emerald-700 hover:underline">% acerto</button>
                            </th>
                            <th class="px-3 py-2">Distribuição</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($analiseAlternativas as $q)
                            <tr data-numero="{{ $q['numero'] }}" data-percentual="{{ $q['percentualAcerto'] }}" @if ($q['anulada']) title="Questão anulada — não conta na nota" @endif>
                                <td class="px-3 py-2 font-bold text-slate-600 font-mono whitespace-nowrap">Q{{ $q['numero'] }}{{ $q['anulada'] ? '*' : '' }}</td>
                                <td class="px-3 py-2 text-slate-500 whitespace-nowrap">{{ $q['area'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-slate-500">{{ $q['tema'] ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex items-center justify-center w-6 h-6 rounded bg-emerald-600 text-white text-xs font-bold">{{ $q['gabarito'] ?: '—' }}</span>
                                </td>
                                <td class="px-3 py-2 font-bold {{ $q['percentualAcerto'] < 40 ? 'text-red-600' : ($q['percentualAcerto'] < 70 ? 'text-amber-600' : 'text-emerald-700') }}">
                                    {{ $q['percentualAcerto'] }}%
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex flex-wrap gap-x-3 gap-y-1">
                                        @foreach ($q['alternativas'] as $alt)
                                            <span class="text-xs whitespace-nowrap px-1.5 py-0.5 rounded
                                                {{ $alt['ehGabarito'] ? 'bg-emerald-100 text-emerald-800 font-bold' : ($alt['ehDistrator'] ? 'bg-amber-100 text-amber-800 font-bold' : 'text-slate-500') }}">
                                                {{ $alt['letra'] }}: {{ $alt['percentual'] }}%
                                                @if ($alt['ehDistrator'])
                                                    <span class="uppercase tracking-wide">distrator</span>
                                                @endif
                                            </span>
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endif

@if ($estado['correlacao_metricas']['visivelAdmin'] && $correlacaoMetricas !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <h2 class="font-semibold mb-1">Correlação entre nota total e métricas nomeadas</h2>
        <p class="text-sm text-slate-500 mb-4">Coeficiente de Pearson entre o percentual de acerto e cada métrica (ex.: nota de redação). Próximo de 1 ou -1 = correlação forte.</p>
        @if (empty($correlacaoMetricas))
            <p class="text-sm text-slate-400">Sem dados suficientes.</p>
        @else
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-left"><tr><th class="px-4 py-2">Métrica</th><th class="px-4 py-2">N</th><th class="px-4 py-2">Correlação</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($correlacaoMetricas as $m)
                        @php
                            $r = $m['correlacao'];
                            $intensidade = $r !== null ? min(1, max(0, (abs($r) - 0.3) / 0.7)) : 0;
                            $corFundo = $r === null || abs($r) < 0.3
                                ? null
                                : ($r > 0 ? 'rgba(18,163,127,'.round(0.1 + 0.35 * $intensidade, 2).')' : 'rgba(239,68,68,'.round(0.1 + 0.35 * $intensidade, 2).')');
                        @endphp
                        <tr>
                            <td class="px-4 py-2">{{ $m['nome_metrica'] }}</td>
                            <td class="px-4 py-2">{{ $m['n'] }}</td>
                            <td class="px-4 py-2 font-bold" style="{{ $corFundo ? 'background-color: '.$corFundo : '' }}">{{ $r ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endif

@if ($estado['evolucao_categoria']['visivelAdmin'] && $evolucaoCategoria !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <h2 class="font-semibold mb-4">Evolução da média da turma na categoria</h2>
        @if (count($evolucaoCategoria) < 2)
            <p class="text-sm text-slate-400">Sem dados suficientes.</p>
        @else
            <canvas id="grafico-evolucao" height="220"></canvas>
        @endif
    </div>
@endif

@if ($estado['alinhamento_referencias']['visivelAdmin'] && ! empty($alinhamentoReferencias))
    @php $mediaGeral = $psicometria['media'] ?? null; @endphp
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <h2 class="font-semibold mb-1">Alinhamento curricular e regulatório</h2>
        <p class="text-sm text-slate-500 mb-5">
            O que a prova cobriu e como foi o desempenho em cada eixo. A contagem de questões é a cobertura:
            um eixo com poucas questões diz muito menos sobre o curso que um com muitas.
        </p>

        @foreach ($alinhamentoReferencias as $tipo => $bloco)
            <div class="{{ ! $loop->last ? 'mb-6' : '' }}">
                <h3 class="text-sm font-semibold text-slate-700 mb-2">{{ $bloco['rotulo'] }}</h3>
                <div class="divide-y divide-slate-100">
                    @foreach ($bloco['itens'] as $item)
                        <div class="grid grid-cols-[minmax(110px,1.4fr)_minmax(0,3fr)_auto_auto] gap-3 items-center py-2 text-sm">
                            <span class="font-medium truncate" title="{{ $item['valor'] }}">{{ $item['valor'] }}</span>
                            <div class="relative h-2.5 rounded-full bg-slate-100">
                                <div class="absolute inset-y-0 left-0 rounded-full"
                                     style="width: {{ min(100, $item['percentual']) }}%; background-color: {{ $item['percentual'] < 60 ? '#d03b3b' : '#12a37f' }}"></div>
                                @if ($mediaGeral !== null)
                                    <span class="absolute -top-0.5 -bottom-0.5 w-0.5 rounded bg-slate-400"
                                          style="left: {{ min(100, $mediaGeral) }}%"
                                          title="Média geral da avaliação: {{ number_format($mediaGeral, 1, ',', '.') }}%"></span>
                                @endif
                            </div>
                            <span class="tabular-nums font-semibold text-right w-14 {{ $item['percentual'] < 60 ? 'text-red-600' : '' }}">
                                {{ number_format($item['percentual'], 1, ',', '.') }}%
                            </span>
                            <span class="text-xs text-slate-400 tabular-nums text-right w-20">{{ $item['totalQuestoes'] }} quest.</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        @if ($mediaGeral !== null)
            <p class="text-xs text-slate-400 mt-4 flex items-center gap-2">
                <span class="inline-block w-0.5 h-3 bg-slate-400 rounded"></span>
                marca a média geral da avaliação ({{ number_format($mediaGeral, 1, ',', '.') }}%)
            </p>
        @endif
    </div>
@endif

@if ($estado['equidade_demografica']['visivelAdmin'] && ! empty($equidade))
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <h2 class="font-semibold mb-1">Equidade: desempenho por recorte</h2>
        <p class="text-sm text-slate-500 mb-5">
            Monitoramento institucional, não avaliação de indivíduo. Grupos com menos de 10 respondentes
            são omitidos — num grupo pequeno, a média do grupo identifica a pessoa.
        </p>

        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            @foreach ($equidade as $recorte)
                @php
                    $maior = collect($recorte['grupos'])->max('media');
                    $menor = collect($recorte['grupos'])->min('media');
                @endphp
                <div>
                    <div class="flex items-baseline justify-between gap-2 mb-2">
                        <h3 class="text-sm font-semibold text-slate-700">{{ $recorte['rotulo'] }}</h3>
                        <span class="text-xs tabular-nums {{ ($maior - $menor) >= 10 ? 'text-amber-700 font-semibold' : 'text-slate-400' }}">
                            {{ number_format($maior - $menor, 1, ',', '.') }} pp de diferença
                        </span>
                    </div>
                    <div class="space-y-2">
                        @foreach ($recorte['grupos'] as $grupo)
                            <div class="text-sm">
                                <div class="flex items-baseline justify-between gap-2">
                                    <span class="truncate" title="{{ $grupo['valor'] }}">{{ $grupo['valor'] }}</span>
                                    <span class="tabular-nums font-semibold">{{ number_format($grupo['media'], 1, ',', '.') }}%</span>
                                </div>
                                <div class="h-2 rounded-full bg-slate-100 mt-1">
                                    <div class="h-full rounded-full" style="width: {{ min(100, $grupo['media']) }}%; background-color: #2a78d6"></div>
                                </div>
                                <span class="text-[11px] text-slate-400">{{ $grupo['respondentes'] }} respondente(s)</span>
                            </div>
                        @endforeach
                    </div>
                    @if ($recorte['suprimidos'] > 0)
                        <p class="text-[11px] text-slate-400 mt-2">
                            {{ $recorte['suprimidos'] }} grupo(s) omitido(s) por terem menos de 10 respondentes.
                        </p>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endif

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1"></script>
@include('_viz')

@if ($estado['mapa_itens']['visivelAdmin'] && $psicometria !== null && ! empty($psicometria['itens']))
<script>
(function () {
    'use strict';

    var itens = {{ Js::from($psicometria['itens']) }};
    var curvas = {{ Js::from($curvasItens ?? []) }};

    // Faixas de Ebel: a posição vertical já diz em qual faixa o item caiu, então
    // a cor é reforço, nunca o único canal de leitura.
    var faixas = [
        { chave: 'otimo', rotulo: 'Ótimo (D ≥ 0,40)', cor: Viz.cores.bom, de: 0.40, ate: 1.0 },
        { chave: 'bom', rotulo: 'Bom (0,30–0,39)', cor: Viz.cores.serie1, de: 0.30, ate: 0.40 },
        { chave: 'marginal', rotulo: 'Marginal (0,20–0,29)', cor: Viz.cores.atencao, de: 0.20, ate: 0.30 },
        { chave: 'revisar', rotulo: 'Revisar (< 0,20)', cor: Viz.cores.critico, de: -1.0, ate: 0.20 },
    ];

    var fundoFaixas = {
        id: 'fundoFaixas',
        beforeDatasetsDraw: function (chart) {
            var ctx = chart.ctx;
            var eixoY = chart.scales.y;
            var area = chart.chartArea;

            ctx.save();
            faixas.forEach(function (faixa) {
                var topo = eixoY.getPixelForValue(Math.min(faixa.ate, eixoY.max));
                var base = eixoY.getPixelForValue(Math.max(faixa.de, eixoY.min));
                if (base <= topo) {
                    return;
                }
                ctx.fillStyle = faixa.cor;
                ctx.globalAlpha = faixa.chave === 'revisar' ? 0.08 : 0.05;
                ctx.fillRect(area.left, topo, area.right - area.left, base - topo);
            });
            ctx.restore();
        },
    };

    var grafico = new Chart(document.getElementById('grafico-mapa-itens'), {
        type: 'scatter',
        data: {
            datasets: faixas.map(function (faixa) {
                return {
                    label: faixa.rotulo,
                    backgroundColor: faixa.cor,
                    borderColor: Viz.cores.superficie,
                    borderWidth: 1.5,
                    pointRadius: 5,
                    pointHoverRadius: 8,
                    data: itens
                        .filter(function (item) { return item.faixa === faixa.chave; })
                        .map(function (item) {
                            return { x: item.dificuldade, y: item.discriminacao === null ? 0 : item.discriminacao, item: item };
                        }),
                };
            }),
        },
        options: {
            scales: {
                x: {
                    min: 0, max: 100,
                    title: { display: true, text: 'Acertaram a questão (%)' },
                },
                y: {
                    suggestedMin: -0.2, suggestedMax: 0.8,
                    title: { display: true, text: 'Índice de discriminação' },
                },
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function (contexto) {
                            var item = contexto.raw.item;
                            var linhas = [
                                'Acerto: ' + item.dificuldade.toFixed(1).replace('.', ',') + '%',
                                'Discriminação: ' + (item.discriminacao === null ? '—' : item.discriminacao.toFixed(2).replace('.', ',')),
                            ];
                            if (item.area) {
                                linhas.push(item.area);
                            }

                            return linhas;
                        },
                        title: function (contexto) {
                            return 'Questão ' + contexto[0].raw.item.numero;
                        },
                    },
                },
            },
            onClick: function (evento, elementos) {
                if (elementos.length) {
                    var ponto = grafico.data.datasets[elementos[0].datasetIndex].data[elementos[0].index];
                    selecionar(ponto.item);
                }
            },
        },
        plugins: [fundoFaixas],
    });

    var graficoCci = new Chart(document.getElementById('grafico-cci'), {
        type: 'line',
        data: {
            labels: ['1º', '2º', '3º', '4º', '5º'],
            datasets: [{ label: '% de acerto', data: [], borderColor: Viz.cores.serie2, backgroundColor: Viz.cores.serie2, fill: false }],
        },
        options: {
            scales: {
                y: { min: 0, max: 100, title: { display: true, text: '% de acerto' } },
                x: { title: { display: true, text: 'quinto de desempenho geral →' } },
            },
            plugins: { legend: { display: false } },
        },
    });

    var coresFaixa = {};
    faixas.forEach(function (faixa) { coresFaixa[faixa.chave] = faixa.cor; });

    function selecionar(item) {
        document.getElementById('item-numero').textContent = 'Q' + item.numero;
        document.getElementById('item-contexto').textContent = [item.area, item.tema].filter(Boolean).join(' · ') || 'sem área cadastrada';

        var faixa = document.getElementById('item-faixa');
        faixa.textContent = item.rotulo;
        faixa.style.color = coresFaixa[item.faixa];
        faixa.style.backgroundColor = coresFaixa[item.faixa] + '22';

        document.getElementById('item-p').textContent = item.dificuldade.toFixed(1).replace('.', ',') + '%';
        document.getElementById('item-d').textContent = item.discriminacao === null ? '—' : item.discriminacao.toFixed(2).replace('.', ',');
        document.getElementById('item-branco').textContent = item.respostas > 0
            ? (item.emBranco / item.respostas * 100).toFixed(1).replace('.', ',') + '%'
            : '—';
        document.getElementById('item-acao').textContent = item.acao;

        var curva = curvas[item.numero] || {};
        graficoCci.data.datasets[0].data = [1, 2, 3, 4, 5].map(function (quinto) {
            return curva[quinto] === undefined ? null : curva[quinto];
        });
        graficoCci.update();
    }

    // Abre já no item mais problemático: é o que o coordenador veio ver.
    var pior = itens.slice().sort(function (a, b) {
        return (a.discriminacao === null ? -99 : a.discriminacao) - (b.discriminacao === null ? -99 : b.discriminacao);
    })[0];
    if (pior) {
        selecionar(pior);
    }
})();
</script>
@endif
<script>
@if (! empty($dados) && empty($dados['semGabarito']) && empty($dados['semRespostas']))
    @if ($estado['histograma']['visivelAdmin'])
    new Chart(document.getElementById('grafico-histograma'), {
        type: 'bar',
        data: {
            labels: [@foreach($dados['histograma'] as $i => $c) '{{ $i * 10 }}-{{ $i * 10 + 9 }}%', @endforeach],
            datasets: [{ label: 'Respondentes', data: {{ Js::from($dados['histograma']) }}, backgroundColor: Viz.cores.serie1 }],
        },
        options: { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { display: false } } },
    });
    @endif

    @if ($estado['radar_disciplina']['visivelAdmin'] && ! empty($dados['radar']))
    new Chart(document.getElementById('grafico-radar'), {
        type: 'radar',
        data: {
            labels: {{ Js::from(array_keys($dados['radar'])) }},
            datasets: [{ label: '% de acerto', data: {{ Js::from(array_values($dados['radar'])) }}, backgroundColor: 'rgba(18,163,127,0.2)', borderColor: Viz.cores.serie1 }],
        },
        options: { scales: { r: { beginAtZero: true, max: 100 } } },
    });
    @endif
@endif

@if (! empty($mediaPorArea))
new Chart(document.getElementById('grafico-area'), {
    type: 'radar',
    data: {
        labels: {{ Js::from(array_keys($mediaPorArea)) }},
        datasets: [{ label: '% de acerto', data: {{ Js::from(array_values($mediaPorArea)) }}, backgroundColor: 'rgba(18,163,127,0.2)', borderColor: Viz.cores.serie1 }],
    },
    options: { scales: { r: { beginAtZero: true, max: 100 } } },
});
@endif

@if (! empty($distribuicaoTurma))
new Chart(document.getElementById('grafico-turma'), {
    type: 'bar',
    data: {
        labels: {{ Js::from(array_column($distribuicaoTurma, 'turma')) }},
        datasets: [{ label: 'Média (%)', data: {{ Js::from(array_column($distribuicaoTurma, 'media')) }}, backgroundColor: Viz.cores.serie1 }],
    },
    options: { scales: { y: { beginAtZero: true, max: 100 } }, plugins: { legend: { display: false } } },
});
@endif

@if (! empty($dispersaoTri))
new Chart(document.getElementById('grafico-tri'), {
    type: 'scatter',
    data: {
        datasets: [{
            label: 'Questões',
            data: {{ Js::from(array_map(fn ($p) => ['x' => $p['dificuldade_tri'], 'y' => $p['taxa_acerto']], $dispersaoTri)) }},
            backgroundColor: Viz.cores.serie1,
        }],
    },
    options: {
        scales: {
            x: { title: { display: true, text: 'Dificuldade TRI' } },
            y: { title: { display: true, text: '% de acerto observado' }, min: 0, max: 100 },
        },
    },
});
@endif

@if (! empty($mediaPorBloom))
new Chart(document.getElementById('grafico-bloom'), {
    type: 'bar',
    data: {
        labels: {{ Js::from(array_keys($mediaPorBloom)) }},
        datasets: [{ label: '% de acerto', data: {{ Js::from(array_values($mediaPorBloom)) }}, backgroundColor: Viz.cores.serie1 }],
    },
    options: { indexAxis: 'y', scales: { x: { beginAtZero: true, max: 100 } }, plugins: { legend: { display: false } } },
});
@endif

@if (! empty($mediaPorMiller))
new Chart(document.getElementById('grafico-miller'), {
    type: 'bar',
    data: {
        labels: {{ Js::from(array_keys($mediaPorMiller)) }},
        datasets: [{ label: '% de acerto', data: {{ Js::from(array_values($mediaPorMiller)) }}, backgroundColor: Viz.cores.serie1 }],
    },
    options: { indexAxis: 'y', scales: { x: { beginAtZero: true, max: 100 } }, plugins: { legend: { display: false } } },
});
@endif

@if (! empty($perfilDemografico['sexo']))
new Chart(document.getElementById('grafico-sexo'), {
    type: 'doughnut',
    data: {
        labels: {{ Js::from(array_keys($perfilDemografico['sexo'])) }},
        datasets: [{ data: {{ Js::from(array_values($perfilDemografico['sexo'])) }}, backgroundColor: [Viz.cores.serie1, Viz.cores.serie2, Viz.cores.serie3, Viz.cores.tinta3] }],
    },
    options: { plugins: { legend: { position: 'bottom' } } },
});
@endif

@if (! empty($perfilDemografico['cor_raca']))
new Chart(document.getElementById('grafico-cor-raca'), {
    type: 'bar',
    data: {
        labels: {{ Js::from(array_keys($perfilDemografico['cor_raca'])) }},
        datasets: [{ data: {{ Js::from(array_values($perfilDemografico['cor_raca'])) }}, backgroundColor: Viz.cores.serie1, borderRadius: 4, maxBarThickness: 22 }],
    },
    options: { indexAxis: 'y', scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { display: false } } },
});
@endif

@if (! empty($evolucaoCategoria) && count($evolucaoCategoria) >= 2)
new Chart(document.getElementById('grafico-evolucao'), {
    type: 'line',
    data: {
        labels: {{ Js::from(array_column($evolucaoCategoria, 'nome')) }},
        datasets: [{ label: 'Média (%)', data: {{ Js::from(array_column($evolucaoCategoria, 'media')) }}, borderColor: Viz.cores.serie1, backgroundColor: 'rgba(18,163,127,0.2)', tension: 0.2 }],
    },
    options: { scales: { y: { beginAtZero: true, max: 100 } } },
});
@endif

function ordenarTabelaAlternativas(campo) {
    var tabela = document.getElementById('tabela-alternativas');
    if (!tabela) return;
    var tbody = tabela.querySelector('tbody');
    var linhas = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
    linhas.sort(function (a, b) {
        return parseFloat(a.dataset[campo]) - parseFloat(b.dataset[campo]);
    });
    linhas.forEach(function (linha) { tbody.appendChild(linha); });
    var label = document.getElementById('alternativas-ordenacao-label');
    if (label) {
        label.textContent = campo === 'numero' ? '(ordenado por número da questão)' : '(ordenado por % de acerto)';
    }
}
</script>
@endsection
