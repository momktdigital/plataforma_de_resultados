{{--
    "Funcionou?": a linha de base do plano, as metas e — quando já houve o DI seguinte — o que o curso alcançou.
    Variáveis: $resultado (PlanoAcaoResultadoService::calcular).
--}}
@php
    $fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1, ',', '.'), '0'), ',').'%';
    $dif = fn ($v) => $v === null ? null : ($v > 0 ? '+' : ($v < 0 ? '−' : '')).rtrim(rtrim(number_format(abs($v), 1, ',', '.'), '0'), ',').' pp';
    $p = $resultado['proximo'];
@endphp
<section class="bg-white border border-slate-200 rounded-xl shadow-sm p-6" aria-labelledby="titulo-resultado">
    <h2 id="titulo-resultado" class="text-lg font-bold mb-1">Resultado frente à linha de base</h2>
    <p class="text-sm text-slate-600 mb-4">
        @if ($p === null)
            Ainda não há resultado de um DI posterior ao do plano. Quando houver, a comparação aparece aqui automaticamente.
        @else
            Comparação com o DI do período letivo <strong>{{ $p['periodo_letivo'] }}</strong>.
        @endif
    </p>
    <div class="overflow-x-auto rounded-xl border border-slate-200">
        <table class="w-full text-sm">
            <caption class="sr-only">Participação e proficiência: linha de base, metas e resultado no DI seguinte</caption>
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th scope="col" class="px-4 py-2.5">Indicador</th>
                    <th scope="col" class="px-4 py-2.5">Linha de base</th>
                    <th scope="col" class="px-4 py-2.5">Meta</th>
                    <th scope="col" class="px-4 py-2.5">DI seguinte</th>
                    <th scope="col" class="px-4 py-2.5">Variação</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ([
                    ['Proficiência', 'proficiencia', 'dProficiencia', 'metaProficiencia'],
                    ['Participação', 'participacao', 'dParticipacao', 'metaParticipacao'],
                ] as [$nome, $chave, $chaveDif, $chaveMeta])
                    <tr>
                        <th scope="row" class="px-4 py-2.5 text-left font-semibold">{{ $nome }}</th>
                        <td class="px-4 py-2.5">{{ $fmt($resultado['base'][$chave]) }}</td>
                        <td class="px-4 py-2.5">{{ $fmt($resultado['metas'][$chave]) }}</td>
                        <td class="px-4 py-2.5 font-bold">
                            {{ $p ? $fmt($p[$chave]) : '—' }}
                            @if ($p && $p[$chaveMeta] !== null)
                                <span class="ml-1 inline-flex items-center gap-1 text-xs font-semibold {{ $p[$chaveMeta] ? 'text-emerald-700' : 'text-amber-700' }}">
                                    <i class="ph-bold {{ $p[$chaveMeta] ? 'ph-check-circle' : 'ph-warning-circle' }}" aria-hidden="true"></i>{{ $p[$chaveMeta] ? 'meta atingida' : 'abaixo da meta' }}
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 font-semibold {{ $p && $p[$chaveDif] !== null ? ($p[$chaveDif] >= 0 ? 'text-emerald-700' : 'text-amber-700') : '' }}">{{ $p ? ($dif($p[$chaveDif]) ?? '—') : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-xs text-slate-500">É um sinal, não uma prova: o grupo de estudantes muda de um período para outro e o resultado tem muitas causas. Atribuir o avanço à ação exige cautela e a análise das evidências de execução.</p>
</section>
