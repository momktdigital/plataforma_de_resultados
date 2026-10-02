@if (($estado['desempenho_bloom']['visivelAdmin'] || $estado['desempenho_miller']['visivelAdmin']) && ($mediaPorBloom !== null || $mediaPorMiller !== null))
    <div class="grid lg:grid-cols-2 gap-6 mb-6">
        @if ($estado['desempenho_bloom']['visivelAdmin'] && $mediaPorBloom !== null)
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
                <div class="flex items-center gap-2 mb-4">
            <h2 class="font-semibold">Desempenho médio por nível de Bloom</h2>
            @include('_explicacao', ['explicacao' => $explicacoes['desempenho_bloom'] ?? null])
        </div>
                @if (empty($mediaPorBloom))
                    <p class="text-sm text-slate-500">Sem dados suficientes.</p>
                @else
                    <canvas id="grafico-bloom" height="220"></canvas>
                @endif
            </div>
        @endif
        @if ($estado['desempenho_miller']['visivelAdmin'] && $mediaPorMiller !== null)
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
                <div class="flex items-center gap-2 mb-4">
            <h2 class="font-semibold">Desempenho médio por nível de Miller</h2>
            @include('_explicacao', ['explicacao' => $explicacoes['desempenho_miller'] ?? null])
        </div>
                @if (empty($mediaPorMiller))
                    <p class="text-sm text-slate-500">Sem dados suficientes.</p>
                @else
                    <canvas id="grafico-miller" height="220"></canvas>
                @endif
            </div>
        @endif
    </div>
@endif
