{{--
    Quadro dos planos de ação por curso (só números). Variáveis: $linhas (PlanoAcaoQuadroService::porCurso), $totais (opcional).
--}}
@php $n = fn ($v, $dec = 0) => $v === null ? '—' : number_format($v, $dec, ',', '.'); @endphp
<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-x-auto">
    <table class="w-full text-sm">
        <caption class="sr-only">Planos de ação por curso: situação, execução das ações e tempo de análise</caption>
        <thead class="bg-slate-50 text-slate-500 text-left">
            <tr>
                <th scope="col" class="px-4 py-3">Curso</th>
                <th scope="col" class="px-3 py-3 text-right">Planos</th>
                <th scope="col" class="px-3 py-3 text-right">Em análise</th>
                <th scope="col" class="px-3 py-3 text-right">Ajustes</th>
                <th scope="col" class="px-3 py-3 text-right">Em execução</th>
                <th scope="col" class="px-3 py-3 text-right">Concluídos</th>
                <th scope="col" class="px-3 py-3 text-right">Recusados</th>
                <th scope="col" class="px-3 py-3 text-right">Ações concluídas</th>
                <th scope="col" class="px-3 py-3 text-right">Ações atrasadas</th>
                <th scope="col" class="px-3 py-3 text-right">Dias de análise (média)</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach ($linhas as $l)
                <tr>
                    <th scope="row" class="px-4 py-2.5 text-left font-semibold">{{ $l['curso'] }}</th>
                    <td class="px-3 py-2.5 text-right font-bold">{{ $l['total'] }}</td>
                    <td class="px-3 py-2.5 text-right">{{ $l['em_analise'] }}</td>
                    <td class="px-3 py-2.5 text-right">{{ $l['ajustes'] }}</td>
                    <td class="px-3 py-2.5 text-right">{{ $l['em_execucao'] }}@if ($l['parados'] > 0)<span class="block text-[11px] font-semibold text-amber-700">{{ $l['parados'] }} parado(s)</span>@endif</td>
                    <td class="px-3 py-2.5 text-right">{{ $l['concluidos'] }}</td>
                    <td class="px-3 py-2.5 text-right">{{ $l['recusados'] }}</td>
                    <td class="px-3 py-2.5 text-right">{{ $l['acoes'] > 0 ? $l['acoes_concluidas'].'/'.$l['acoes'].' ('.$l['pct_acoes'].'%)' : '—' }}</td>
                    <td class="px-3 py-2.5 text-right {{ $l['acoes_atrasadas'] > 0 ? 'font-bold text-red-700' : '' }}">{{ $l['acoes_atrasadas'] }}</td>
                    <td class="px-3 py-2.5 text-right">{{ $n($l['dias_analise'], 1) }}</td>
                </tr>
            @endforeach
        </tbody>
        @if (! empty($totais))
            <tfoot class="bg-slate-50 font-bold">
                <tr>
                    <th scope="row" class="px-4 py-2.5 text-left">Total</th>
                    <td class="px-3 py-2.5 text-right">{{ $totais['planos'] }}</td>
                    <td class="px-3 py-2.5 text-right">{{ $totais['em_analise'] }}</td>
                    <td class="px-3 py-2.5 text-right">{{ $totais['ajustes'] }}</td>
                    <td class="px-3 py-2.5 text-right">{{ $totais['em_execucao'] }}</td>
                    <td class="px-3 py-2.5 text-right">{{ $totais['concluidos'] }}</td>
                    <td class="px-3 py-2.5 text-right">{{ $totais['recusados'] }}</td>
                    <td class="px-3 py-2.5 text-right">{{ $totais['acoes'] > 0 ? $totais['acoes_concluidas'].'/'.$totais['acoes'].' ('.$totais['pct_acoes'].'%)' : '—' }}</td>
                    <td class="px-3 py-2.5 text-right">{{ $totais['acoes_atrasadas'] }}</td>
                    <td class="px-3 py-2.5 text-right">{{ $n($totais['dias_analise'], 1) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
</div>
