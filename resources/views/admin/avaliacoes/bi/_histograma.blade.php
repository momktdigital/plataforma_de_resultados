@if ($estado['histograma']['visivelAdmin'])
    @if (empty($dados['semGabarito']) && empty($dados['semRespostas']) && ! empty($dados))
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
            <div class="flex items-center gap-2 mb-4">
            <h2 class="font-semibold">Distribuição de acertos ({{ $dados['totalRespondentes'] }} respondente(s))</h2>
            @include('_explicacao', ['explicacao' => $explicacoes['histograma'] ?? null])
        </div>
            <canvas id="grafico-histograma" height="100"></canvas>
        </div>
    @endif
@endif
