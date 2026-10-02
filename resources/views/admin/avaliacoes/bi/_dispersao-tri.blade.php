@if ($estado['dispersao_tri']['visivelAdmin'] && $dispersaoTri !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <div class="flex items-center gap-2 mb-1">
            <h2 class="font-semibold">Dispersão TRI x taxa de acerto observada</h2>
            @include('_explicacao', ['explicacao' => $explicacoes['dispersao_tri'] ?? null])
        </div>
        <p class="text-sm text-slate-500 mb-4">Cada ponto é uma questão — eixo X: dificuldade TRI cadastrada, eixo Y: % de acerto observado.</p>
        @if (empty($dispersaoTri))
            <p class="text-sm text-slate-500">Sem dados suficientes.</p>
        @else
            <canvas id="grafico-tri" height="220"></canvas>
        @endif
    </div>
@endif
