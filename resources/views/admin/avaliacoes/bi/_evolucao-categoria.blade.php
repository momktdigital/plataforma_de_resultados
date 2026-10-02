@if ($estado['evolucao_categoria']['visivelAdmin'] && $evolucaoCategoria !== null)
    @php
        $temEvolucaoGeral = count($evolucaoCategoria) >= 2;
        // Só períodos que tenham ao menos uma turma com resultado nas avaliações da categoria.
        $periodosComEvolucao = \App\Support\EvolucaoDoDashboard::periodosComEvolucao($evolucaoPorPeriodo ?? null);
        // Período inicial: o da turma do filtro "Turma" (se houver); senão o que tem mais turmas com
        // evolução de verdade (2+ avaliações) e, no empate, o mais numeroso na avaliação aberta.
        $periodoInicial = null;
        if ($filtro->turma !== null) {
            $periodoInicial = $periodosComEvolucao->search(fn ($periodo) => array_key_exists($filtro->turma, $periodo['turmas']));
        }
        if ($periodoInicial === null || $periodoInicial === false) {
            $periodoInicial = $periodosComEvolucao
                ->sortByDesc(fn ($periodo) => [
                    collect($periodo['turmas'])->filter(fn ($pontos) => count($pontos) >= 2)->count(),
                    collect($periodo['turmas'])->sum(fn ($pontos) => collect($pontos)->where('codigo', $avaliacao->codigo)->sum('respondentes')),
                ])
                ->keys()->first();
        }
        $abaInicial = $periodoInicial !== null ? 'periodo' : 'geral';
        $temAusentes = collect($evolucaoCategoria)->contains(fn ($p) => $p['presentes'] < $p['respondentes']);
    @endphp
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <div class="flex items-center gap-2 mb-4">
            <h2 class="font-semibold">Evolução da média na categoria</h2>
            @include('_explicacao', ['explicacao' => $explicacoes['evolucao_categoria'] ?? null])
        </div>
        @if (! $temEvolucaoGeral)
            <p class="text-sm text-slate-500">Sem dados suficientes.</p>
        @else
            <div class="flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 mb-4">
                <div class="flex gap-1" role="tablist" aria-label="Evolução da média na categoria">
                    @if ($periodosComEvolucao->isNotEmpty())
                        <button type="button" role="tab" id="aba-evolucao-periodo" data-aba-evolucao="periodo" aria-selected="{{ $abaInicial === 'periodo' ? 'true' : 'false' }}"
                                class="aba-evolucao px-4 py-2 text-sm font-medium -mb-px border-b-2">Período</button>
                    @endif
                    <button type="button" role="tab" id="aba-evolucao-geral" data-aba-evolucao="geral" aria-selected="{{ $abaInicial === 'geral' ? 'true' : 'false' }}"
                            class="aba-evolucao px-4 py-2 text-sm font-medium -mb-px border-b-2">Avaliação inteira</button>
                </div>
                <label class="inline-flex items-center gap-2 text-sm text-slate-600 pb-2 cursor-pointer" title="Ausente = prova inteira em branco">
                    <input type="checkbox" id="evolucao-sem-ausentes" checked class="rounded border-slate-300">
                    Desconsiderar ausentes
                </label>
            </div>

            @if ($periodosComEvolucao->isNotEmpty())
                <div id="painel-evolucao-periodo" role="tabpanel" aria-labelledby="aba-evolucao-periodo" class="{{ $abaInicial === 'periodo' ? '' : 'hidden' }}">
                    <div class="mb-3">
                        <label for="seletor-periodo-evolucao" class="block text-xs font-medium text-slate-500 mb-1">Período do curso</label>
                        <select id="seletor-periodo-evolucao" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            @foreach ($periodosComEvolucao as $ordinal => $periodo)
                                <option value="{{ $ordinal }}" {{ $ordinal === $periodoInicial ? 'selected' : '' }}>{{ $periodo['rotulo'] }}</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-slate-500 mt-1">Uma linha por turma do período: a média dos alunos que estavam nela em cada avaliação da categoria.</p>
                    </div>
                    <canvas id="grafico-evolucao-periodo" height="220"></canvas>
                </div>
            @endif

            <div id="painel-evolucao-geral" role="tabpanel" aria-labelledby="aba-evolucao-geral" class="{{ $abaInicial === 'geral' ? '' : 'hidden' }}">
                <p class="text-xs text-slate-500 mb-3">Média de todos os respondentes de cada avaliação da categoria.</p>
                <canvas id="grafico-evolucao" height="220"></canvas>
            </div>
            @unless ($temAusentes)
                <p class="text-xs text-slate-500 mt-3">Nenhum ausente nestas avaliações: com ou sem a opção marcada, os números são os mesmos.</p>
            @endunless
        @endif
    </div>
@endif
