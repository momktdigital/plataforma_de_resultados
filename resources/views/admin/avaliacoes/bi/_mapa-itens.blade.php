@if ($estado['mapa_itens']['visivelAdmin'] && $psicometria !== null && ! empty($psicometria['itens']))
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <div class="flex items-start justify-between gap-4 flex-wrap mb-1">
            <span class="flex items-center gap-2 mr-auto">
                <h2 class="font-semibold">Mapa de qualidade dos itens</h2>
                @include('_explicacao', ['explicacao' => $explicacoes['mapa_itens'] ?? null, 'id' => 'expl-mapa'])
            </span>
            <button type="button" data-tabela="tabela-mapa-itens" aria-expanded="false"
                    class="text-xs text-slate-500 hover:text-slate-800 border border-slate-200 rounded-lg px-2.5 py-1">
                Ver como tabela
            </button>
        </div>
        <p class="text-sm text-slate-500 mb-4">
            Cada ponto é uma questão. O eixo horizontal é quanto a turma acertou; o vertical é o quanto a questão
            separa quem foi bem de quem foi mal (faixas de Ebel). Clique num ponto para ver a curva característica dele.
            Questões anuladas ficam de fora.
        </p>

        <div class="grid lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2">
                <canvas id="grafico-mapa-itens" height="260"></canvas>
            </div>
            <div class="border border-slate-200 rounded-xl p-4">
                <div class="flex items-center gap-2 flex-wrap">
                    <span id="item-numero" class="text-xl font-bold tracking-tight">—</span>
                    <span id="item-faixa" class="text-xs font-semibold px-2 py-0.5 rounded"></span>
                    @include('_explicacao', [
                        'explicacao' => $explicacoes['curva_caracteristica'] ?? null,
                        'id' => 'expl-cci',
                    ])
                </div>
                <p id="item-contexto" class="text-xs text-slate-500 mt-0.5">Clique num ponto do gráfico</p>

                <div class="grid grid-cols-3 gap-2 mt-4 pt-3 border-t border-slate-100">
                    <div>
                        <span class="block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Acerto</span>
                        <b id="item-p" class="text-base font-bold tabular-nums">—</b>
                    </div>
                    <div>
                        <span class="block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Discrim.</span>
                        <b id="item-d" class="text-base font-bold tabular-nums">—</b>
                    </div>
                    <div>
                        <span class="block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Em branco</span>
                        <b id="item-branco" class="text-base font-bold tabular-nums">—</b>
                    </div>
                </div>

                <p class="text-xs font-medium text-slate-500 mt-4 mb-1">Curva característica</p>
                <canvas id="grafico-cci" height="150" data-titulo="Curva característica do item selecionado (% de acerto por quinto de desempenho geral)"></canvas>
                <p id="item-acao" class="text-xs text-slate-600 mt-3"></p>
            </div>
        </div>

        @if ($psicometria['simulacao'] !== null)
            <div class="mt-5 bg-slate-50 border-l-4 rounded-r-lg px-4 py-3 text-sm text-slate-600" style="border-color: #eb6834">
                <b class="text-slate-800">E se…</b>
                removendo {{ count($psicometria['simulacao']['removidos']) }} questão(ões) com discriminação abaixo de 0,20
                ({{ collect($psicometria['simulacao']['removidos'])->take(3)->map(fn ($n) => 'Q'.$n)->implode(', ') }}{{ count($psicometria['simulacao']['removidos']) > 3 ? '…' : '' }}),
                o KR-20 da prova
                @if ($psicometria['simulacao']['ganho'] > 0)
                    sobe para <b class="text-slate-800">{{ number_format($psicometria['simulacao']['kr20'], 2, ',', '.') }}</b>
                    (+{{ number_format($psicometria['simulacao']['ganho'], 2, ',', '.') }}).
                @else
                    não melhora — o problema desta prova não está concentrado nesses itens.
                @endif
            </div>
        @endif

        <div id="tabela-mapa-itens" hidden class="mt-5 overflow-x-auto">
            <table class="w-full text-sm">
                <caption class="text-left text-xs text-slate-500 pb-2">Questões com discriminação abaixo de 0,20</caption>
                <thead class="bg-slate-50 text-slate-500 text-left text-xs uppercase">
                    <tr><th class="px-3 py-2">Questão</th><th class="px-3 py-2">Acerto</th><th class="px-3 py-2">Discriminação</th><th class="px-3 py-2">Faixa</th><th class="px-3 py-2">O que fazer</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach (collect($psicometria['itens'])->filter(fn ($i) => $i['discriminacao'] === null || $i['discriminacao'] < 0.20)->sortBy('discriminacao') as $item)
                        <tr>
                            <td class="px-3 py-2 font-medium">Q{{ $item['numero'] }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ number_format($item['dificuldade'], 1, ',', '.') }}%</td>
                            <td class="px-3 py-2 tabular-nums">{{ $item['discriminacao'] === null ? '—' : number_format($item['discriminacao'], 2, ',', '.') }}</td>
                            <td class="px-3 py-2">{{ $item['rotulo'] }}</td>
                            <td class="px-3 py-2 text-slate-500">{{ $item['acao'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
