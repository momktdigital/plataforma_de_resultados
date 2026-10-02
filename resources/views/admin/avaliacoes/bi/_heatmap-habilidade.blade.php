@if ($estado['heatmap_habilidade_turma']['visivelAdmin'] && $heatmapHabilidadeTurma !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6 overflow-x-auto">
        <div class="flex items-center gap-2 mb-4">
            <h2 class="font-semibold">Mapa de calor: habilidade x turma (% de acerto)</h2>
            @include('_explicacao', ['explicacao' => $explicacoes['heatmap_habilidade_turma'] ?? null])
        </div>
        @if (empty($heatmapHabilidadeTurma))
            <p class="text-sm text-slate-500">Sem dados suficientes.</p>
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
