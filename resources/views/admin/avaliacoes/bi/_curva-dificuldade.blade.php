@if ($estado['curva_dificuldade']['visivelAdmin'] && $curvaDificuldade !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <div class="flex items-center gap-2 mb-1">
            <h2 class="font-semibold">Dificuldade pedagógica: esperado x observado</h2>
            @include('_explicacao', ['explicacao' => $explicacoes['curva_dificuldade'] ?? null])
        </div>
        <p class="text-sm text-slate-500 mb-4">% de acerto observado por nível de dificuldade cadastrado nas questões, comparado à meta definida na configuração da avaliação.</p>
        @if (empty($curvaDificuldade))
            <p class="text-sm text-slate-500">Sem dados suficientes.</p>
        @else
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-left">
                    <tr>
                        <th class="px-4 py-2">Dificuldade esperada</th>
                        <th class="px-4 py-2">Questões</th>
                        <th class="px-4 py-2">Meta de acerto</th>
                        <th class="px-4 py-2">% de acerto observado</th>
                        <th class="px-4 py-2">Desvio</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($curvaDificuldade as $linha)
                        <tr>
                            <td class="px-4 py-2">{{ $linha['esperado'] }}</td>
                            <td class="px-4 py-2">{{ $linha['questoes'] }}@if ($linha['questoes'] === 0)<span class="text-xs text-slate-500"> (nenhuma cadastrada)</span>@endif</td>
                            <td class="px-4 py-2">{{ $linha['meta'] !== null ? number_format($linha['meta'], 1, ',', '.').'%' : '—' }}</td>
                            <td class="px-4 py-2 font-bold">{{ $linha['observado'] !== null ? $linha['observado'].'%' : '—' }}</td>
                            <td class="px-4 py-2">
                                @if ($linha['desvio'] === null)
                                    <span class="text-slate-500">—</span>
                                @else
                                    <span class="font-semibold {{ $linha['atingiu'] ? 'text-emerald-700' : 'text-amber-700' }}">{{ $linha['desvio'] > 0 ? '+' : '' }}{{ number_format($linha['desvio'], 1, ',', '.') }} pp</span>
                                    <span class="text-xs text-slate-500">· {{ $linha['atingiu'] ? 'meta atingida' : 'abaixo da meta' }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endif
