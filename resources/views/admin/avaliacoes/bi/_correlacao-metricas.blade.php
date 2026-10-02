@if ($estado['correlacao_metricas']['visivelAdmin'] && $correlacaoMetricas !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <div class="flex items-center gap-2 mb-1">
            <h2 class="font-semibold">Correlação entre nota total e métricas nomeadas</h2>
            @include('_explicacao', ['explicacao' => $explicacoes['correlacao_metricas'] ?? null])
        </div>
        <p class="text-sm text-slate-500 mb-4">Coeficiente de Pearson entre o percentual de acerto e cada métrica (ex.: nota de redação). Próximo de 1 ou -1 = correlação forte.</p>
        @if (empty($correlacaoMetricas))
            <p class="text-sm text-slate-500">Sem dados suficientes.</p>
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
