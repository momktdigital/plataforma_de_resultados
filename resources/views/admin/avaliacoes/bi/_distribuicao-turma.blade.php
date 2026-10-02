@if ($estado['distribuicao_turma']['visivelAdmin'] && $distribuicaoTurma !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <div class="flex items-center gap-2 mb-4">
            <h2 class="font-semibold">Distribuição de notas por turma</h2>
            @include('_explicacao', ['explicacao' => $explicacoes['distribuicao_turma'] ?? null])
        </div>
        @if (empty($distribuicaoTurma))
            <p class="text-sm text-slate-500">Sem dados suficientes.</p>
        @else
            <canvas id="grafico-turma" height="220"></canvas>
        @endif
    </div>
@endif
