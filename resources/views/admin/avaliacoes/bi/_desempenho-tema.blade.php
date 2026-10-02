@if ($estado['desempenho_tema']['visivelAdmin'] && $desempenhoPorTema !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <div class="flex items-center gap-2 mb-4">
            <h2 class="font-semibold">Desempenho por tema</h2>
            @include('_explicacao', ['explicacao' => $explicacoes['desempenho_tema'] ?? null])
        </div>
        @if (empty($desempenhoPorTema))
            <p class="text-sm text-slate-500">Sem dados suficientes.</p>
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
                                    <p class="text-xs text-slate-500 truncate">{{ $t['area'] ?? '—' }}</p>
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
                                    <p class="text-xs text-slate-500 truncate">{{ $t['area'] ?? '—' }}</p>
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
