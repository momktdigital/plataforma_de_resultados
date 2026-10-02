@if ($estado['analise_alternativas']['visivelAdmin'] && $analiseAlternativas !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <div class="flex items-center gap-2 mb-1">
            <h2 class="font-semibold">Análise de alternativas por questão <span id="alternativas-ordenacao-label" class="font-normal text-slate-500 text-sm">(ordenado por % de acerto)</span></h2>
            @include('_explicacao', ['explicacao' => $explicacoes['analise_alternativas'] ?? null])
        </div>
        @if (empty($analiseAlternativas))
            <p class="text-sm text-slate-500">Sem dados suficientes.</p>
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
                                <td class="px-3 py-2 font-bold {{ $q['percentualAcerto'] < 40 ? 'text-red-600' : ($q['percentualAcerto'] < 70 ? 'text-amber-700' : 'text-emerald-700') }}">
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
