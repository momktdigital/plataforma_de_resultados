@if ($estado['desempenho_area']['visivelAdmin'] && $mediaPorArea !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <div class="flex items-center gap-2 mb-4">
            <h2 class="font-semibold">Desempenho por área</h2>
            @include('_explicacao', ['explicacao' => $explicacoes['desempenho_area'] ?? null])
        </div>
        @if (empty($mediaPorArea))
            <p class="text-sm text-slate-500">Sem dados suficientes.</p>
        @else
            @php $areasOrdenadas = collect($mediaPorArea)->sortDesc(); @endphp
            <div>
                <ul class="grid gap-x-10 gap-y-3 md:grid-cols-2">
                    @foreach ($areasOrdenadas as $area => $percentual)
                        <li>
                            <div class="flex items-center justify-between text-sm mb-1">
                                <span class="font-medium text-slate-600">{{ $area }}</span>
                                <span class="font-bold text-slate-800">{{ $percentual }}%</span>
                            </div>
                            <div class="h-2 bg-slate-100 rounded-full overflow-hidden">
                                <div class="h-full {{ \App\Support\CorDesempenho::classeBg((float) $percentual) }} rounded-full" style="width: {{ $percentual }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
@endif
