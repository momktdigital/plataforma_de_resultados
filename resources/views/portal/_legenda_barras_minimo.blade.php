{{-- Legenda do gráfico de barras com linha tracejada de mínimo/meta (Viz.barrasComMinimo, em _viz.blade.php). --}}
<div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2 text-[11px] text-slate-500">
    <span class="inline-flex items-center gap-1.5"><span class="inline-block w-3 h-3 rounded-sm" style="background-color: #00b48d"></span> {{ $verde ?? 'no mínimo ou acima' }}</span>
    <span class="inline-flex items-center gap-1.5"><span class="inline-block w-3 h-3 rounded-sm" style="background-color: #f59e0b"></span> {{ $amarelo ?? 'abaixo do mínimo' }}</span>
    <span class="inline-flex items-center gap-1.5"><span class="inline-block h-3 border-l-2 border-dashed border-slate-600"></span> {{ $linha ?? 'mínimo esperado' }}</span>
</div>
