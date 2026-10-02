@if ($estado['estatisticas_gerais']['visivelAdmin'] && $psicometria !== null)
    @php
        $kr20 = $psicometria['kr20'];
        $kr20Bom = $kr20 !== null && $kr20 >= 0.80;
        $kr20Aceitavel = $kr20 !== null && $kr20 >= 0.70;
    @endphp
    <h2 class="text-lg font-bold mb-3">Números da prova</h2>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5 mb-6">
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Alunos presentes</p>
            <p class="text-3xl font-bold mt-2 tracking-tight">{{ number_format($presenca['percentual'], 1, ',', '.') }}<span class="text-lg font-medium text-slate-500">%</span></p>
            <p class="text-xs text-slate-500 mt-1">{{ $presenca['presentes'] }} de {{ $presenca['total'] }} · {{ $presenca['ausentes'] }} ausente(s) (prova inteira em branco)</p>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
            <div class="flex items-center gap-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Média da turma</p>
                @include('_explicacao', ['explicacao' => $explicacoes['kpi_media'] ?? null])
            </div>
            <p class="text-3xl font-bold mt-2 tracking-tight">{{ number_format($psicometria['media'], 1, ',', '.') }}<span class="text-lg font-medium text-slate-500">%</span></p>
            <p class="text-xs text-slate-500 mt-1">{{ $psicometria['respondentes'] }} respondente(s) · {{ $psicometria['questoes'] }} questão(ões)</p>
            @if ($psicometria['semAusentes'])
                <p class="text-xs text-slate-500 mt-2 pt-2 border-t border-slate-100">Sem ausentes: <span class="font-semibold text-slate-700">{{ number_format($psicometria['semAusentes']['media'], 1, ',', '.') }}%</span> ({{ $psicometria['semAusentes']['respondentes'] }} presente(s))</p>
            @endif
        </div>
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
            <div class="flex items-center gap-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Mediana</p>
                @include('_explicacao', ['explicacao' => $explicacoes['kpi_mediana'] ?? null])
            </div>
            <p class="text-3xl font-bold mt-2 tracking-tight">{{ number_format($psicometria['mediana'], 1, ',', '.') }}<span class="text-lg font-medium text-slate-500">%</span></p>
            <p class="text-xs text-slate-500 mt-1">metade da turma ficou acima disto</p>
            @if ($psicometria['semAusentes'])
                <p class="text-xs text-slate-500 mt-2 pt-2 border-t border-slate-100">Sem ausentes: <span class="font-semibold text-slate-700">{{ number_format($psicometria['semAusentes']['mediana'], 1, ',', '.') }}%</span></p>
            @endif
        </div>
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
            <div class="flex items-center gap-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Desvio-padrão</p>
                @include('_explicacao', ['explicacao' => $explicacoes['kpi_desvio'] ?? null])
            </div>
            <p class="text-3xl font-bold mt-2 tracking-tight">{{ number_format($psicometria['desvio'], 1, ',', '.') }}<span class="text-lg font-medium text-slate-500">pp</span></p>
            <p class="text-xs text-slate-500 mt-1">o quanto as notas se espalham</p>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
            <div class="flex items-center gap-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Confiabilidade (KR-20)</p>
                @include('_explicacao', ['explicacao' => $explicacoes['kpi_kr20'] ?? null])
            </div>
            @if ($kr20 === null)
                <p class="text-3xl font-bold mt-2 tracking-tight text-slate-300">—</p>
                <p class="text-xs text-slate-500 mt-1">sem variação de notas suficiente para calcular</p>
            @else
                <p class="text-3xl font-bold mt-2 tracking-tight">{{ number_format($kr20, 2, ',', '.') }}</p>
                <div class="h-1.5 rounded-full bg-slate-100 mt-3 overflow-hidden">
                    <div class="h-full rounded-full" style="width: {{ round($kr20 * 100) }}%; background-color: {{ $kr20Aceitavel ? '#12a37f' : '#d03b3b' }}"></div>
                </div>
                <p class="text-xs mt-2 font-medium {{ $kr20Bom ? 'text-emerald-700' : ($kr20Aceitavel ? 'text-amber-700' : 'text-red-700') }}">
                    {{ $kr20Bom ? 'Consistência adequada' : ($kr20Aceitavel ? 'Aceitável — dá para melhorar' : 'Baixa: a prova mede muito ao acaso') }}
                </p>
            @endif
        </div>
    </div>
@endif
