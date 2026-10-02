@if ($estado['radar_disciplina']['visivelAdmin'])
    @if (empty($dados['semGabarito']) && empty($dados['semRespostas']) && ! empty($dados))
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
            <div class="flex items-center gap-2 mb-4">
            <h2 class="font-semibold">Desempenho médio por disciplina</h2>
            @include('_explicacao', ['explicacao' => $explicacoes['radar_disciplina'] ?? null])
        </div>
            @if (empty($dados['radar']))
                <p class="text-sm text-slate-500">Nenhuma questão desta avaliação tem disciplina cadastrada na matriz.</p>
            @else
                <div class="max-w-xl mx-auto">
                    <canvas id="grafico-radar" height="220"></canvas>
                </div>
            @endif
        </div>
    @endif
@endif
