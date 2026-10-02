@if ($estado['comparacao_avaliacoes']['visivelAdmin'])
    @php
        // avaliação base = sempre serie1; a ordem de $comparacaoAvaliacoes['avaliacoes']
        // (base primeiro, depois as escolhidas na ordem do seletor) é o que define
        // qual cor cada uma leva — nunca reordenar por valor/rank.
        $paletaComparacao = ['#12a37f', '#2a78d6', '#eb6834'];
    @endphp
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <div class="flex items-center gap-2 mb-4">
            <h2 class="font-semibold">Comparação entre avaliações</h2>
            @include('_explicacao', ['explicacao' => $explicacoes['comparacao_avaliacoes'] ?? null])
        </div>

        <form method="GET" action="{{ route('avaliacoes.bi', $avaliacao) }}" class="flex flex-wrap items-end gap-3 mb-5">
            <input type="hidden" name="periodo" value="{{ $periodo }}">
            <input type="hidden" name="turma" value="{{ $filtro->turma }}">
            <input type="hidden" name="sexo" value="{{ $filtro->sexo }}">
            <input type="hidden" name="cor_raca" value="{{ $filtro->corRaca }}">
            <input type="hidden" name="faixa_etaria" value="{{ $filtro->faixaEtaria }}">
            <div>
                <div class="flex items-center justify-between mb-1">
                    <label for="busca-comparar" class="block text-xs font-medium text-slate-500">
                        Comparar com (até {{ \App\Services\ComparacaoAvaliacoesService::MAX_COMPARACOES }})
                    </label>
                    <span id="contagem-comparar" class="text-xs text-slate-500"></span>
                </div>
                <div class="border border-slate-300 rounded-lg w-80 max-w-full overflow-hidden">
                    @if ($opcoesComparacao->isNotEmpty())
                        <div class="relative border-b border-slate-200">
                            <i class="ph ph-magnifying-glass absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                            <input type="text" id="busca-comparar" autocomplete="off" placeholder="Buscar avaliação pelo nome…"
                                   class="w-full pl-8 pr-2.5 py-2 text-sm border-0 focus:ring-0">
                        </div>
                    @endif
                    <div id="lista-comparar" class="max-h-48 overflow-y-auto divide-y divide-slate-100">
                        @forelse ($opcoesComparacao as $opcao)
                            <label class="linha-comparar flex items-center gap-2 px-3 py-1.5 text-sm hover:bg-slate-50 cursor-pointer" data-busca="{{ \Illuminate\Support\Str::ascii(mb_strtolower($opcao['nome'])) }}">
                                <input type="checkbox" name="comparar[]" value="{{ $opcao['codigo'] }}"
                                       class="check-comparar rounded border-slate-300"
                                       {{ in_array($opcao['codigo'], $comparacaoSelecionada) ? 'checked' : '' }}>
                                <span class="flex-1">{{ $opcao['nome'] }}</span>
                                @if ($opcao['data'])
                                    <span class="text-xs text-slate-500">{{ \Illuminate\Support\Carbon::parse($opcao['data'])->format('d/m/Y') }}</span>
                                @endif
                            </label>
                        @empty
                            <p class="text-xs text-slate-500 px-3 py-2">Nenhuma outra avaliação com resultado ainda.</p>
                        @endforelse
                        <p id="sem-resultado-comparar" hidden class="text-xs text-slate-500 px-3 py-2">Nenhuma avaliação encontrada.</p>
                    </div>
                </div>
            </div>
            <button type="submit" class="bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-lg px-4 py-2 text-sm">
                Comparar
            </button>
            @if (! empty($comparacaoSelecionada))
                <a href="{{ route('avaliacoes.bi', array_merge(['avaliacao' => $avaliacao->codigo], request()->except('comparar'))) }}"
                   class="text-sm text-slate-500 hover:underline px-1 py-2">Limpar comparação</a>
            @endif
        </form>

        @if ($comparacaoAvaliacoes === null)
            <p class="text-sm text-slate-500">Selecione ao menos uma avaliação acima para comparar.</p>
        @else
            @php
                $listaComparacao = $comparacaoAvaliacoes['avaliacoes'];
                $areasComparacao = $comparacaoAvaliacoes['areas'];
            @endphp
            <div class="flex flex-wrap gap-x-5 gap-y-1.5 mb-5 pb-4 border-b border-slate-100">
                @foreach ($listaComparacao as $i => $av)
                    <span class="flex items-center gap-1.5 text-sm">
                        <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: {{ $paletaComparacao[$i] }}"></span>
                        <span class="font-medium text-slate-700">{{ $av['nome'] }}</span>
                        @if ($i === 0)<span class="text-xs text-slate-500">(esta)</span>@endif
                    </span>
                @endforeach
            </div>

            <div class="grid lg:grid-cols-2 gap-6">
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-sm font-semibold text-slate-600">Média geral</h3>
                        <button type="button" data-tabela="tabela-comparacao-kpi" aria-expanded="false"
                                class="text-xs text-slate-500 hover:text-slate-800 border border-slate-200 rounded-lg px-2.5 py-1">
                            Ver como tabela
                        </button>
                    </div>
                    <canvas id="grafico-comparacao-media" height="220"></canvas>
                    <div id="tabela-comparacao-kpi" hidden class="mt-4 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-slate-500 text-left text-xs uppercase">
                                <tr>
                                    <th class="px-3 py-2">Avaliação</th>
                                    <th class="px-3 py-2">Respondentes</th>
                                    <th class="px-3 py-2">Média</th>
                                    <th class="px-3 py-2">Mediana</th>
                                    <th class="px-3 py-2">Desvio</th>
                                    <th class="px-3 py-2">KR-20</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($listaComparacao as $av)
                                    <tr>
                                        <td class="px-3 py-2 font-medium">{{ $av['nome'] }}</td>
                                        @if ($av['resumo'] === null)
                                            <td class="px-3 py-2 text-slate-500" colspan="5">respondentes insuficientes</td>
                                        @else
                                            <td class="px-3 py-2 tabular-nums">{{ $av['resumo']['respondentes'] }}</td>
                                            <td class="px-3 py-2 tabular-nums">{{ number_format($av['resumo']['media'], 1, ',', '.') }}%</td>
                                            <td class="px-3 py-2 tabular-nums">{{ number_format($av['resumo']['mediana'], 1, ',', '.') }}%</td>
                                            <td class="px-3 py-2 tabular-nums">{{ number_format($av['resumo']['desvio'], 1, ',', '.') }}pp</td>
                                            <td class="px-3 py-2 tabular-nums">{{ $av['resumo']['kr20'] === null ? '—' : number_format($av['resumo']['kr20'], 2, ',', '.') }}</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-sm font-semibold text-slate-600">Desempenho por área</h3>
                        <button type="button" data-tabela="tabela-comparacao-area" aria-expanded="false"
                                class="text-xs text-slate-500 hover:text-slate-800 border border-slate-200 rounded-lg px-2.5 py-1">
                            Ver como tabela
                        </button>
                    </div>
                    @if (empty($areasComparacao))
                        <p class="text-sm text-slate-500">Nenhuma área em comum entre as avaliações comparadas.</p>
                    @else
                        <canvas id="grafico-comparacao-area" height="{{ max(220, count($areasComparacao) * 40) }}"></canvas>
                        <div id="tabela-comparacao-area" hidden class="mt-4 overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 text-slate-500 text-left text-xs uppercase">
                                    <tr>
                                        <th class="px-3 py-2">Área</th>
                                        @foreach ($listaComparacao as $av)
                                            <th class="px-3 py-2">{{ $av['nome'] }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach ($areasComparacao as $area)
                                        <tr>
                                            <td class="px-3 py-2 font-medium">{{ $area }}</td>
                                            @foreach ($listaComparacao as $av)
                                                <td class="px-3 py-2 tabular-nums">
                                                    {{ isset($av['mediaPorArea'][$area]) ? number_format($av['mediaPorArea'][$area], 1, ',', '.').'%' : '—' }}
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    <script>
    // Não depende do Chart.js (busca e limite de seleção são só DOM), então
    // fica aqui — não precisa esperar a biblioteca carregar lá embaixo.
    (function () {
        'use strict';

        var caixas = document.querySelectorAll('#lista-comparar .check-comparar');
        var contagem = document.getElementById('contagem-comparar');

        function atualizarContagem() {
            if (!contagem) {
                return;
            }
            var n = document.querySelectorAll('#lista-comparar .check-comparar:checked').length;
            contagem.textContent = n > 0 ? (n + ' selecionada' + (n > 1 ? 's' : '')) : '';
        }

        function atualizarLimite() {
            var marcadas = document.querySelectorAll('#lista-comparar .check-comparar:checked');
            var limite = marcadas.length >= {{ \App\Services\ComparacaoAvaliacoesService::MAX_COMPARACOES }};
            caixas.forEach(function (outra) {
                outra.disabled = limite && !outra.checked;
            });
            atualizarContagem();
        }

        caixas.forEach(function (caixa) {
            caixa.addEventListener('change', atualizarLimite);
        });
        atualizarLimite();

        // Busca client-side: a lista já vem inteira do servidor (não pagina),
        // então filtrar aqui não custa consulta nenhuma — só esconder linhas.
        // Uma avaliação já marcada nunca some da lista ao filtrar, senão o
        // usuário perde de vista o que já escolheu.
        var busca = document.getElementById('busca-comparar');
        if (busca) {
            busca.addEventListener('input', function () {
                // data-busca já vem sem acento (Str::ascii no servidor) —
                // dobra o termo digitado do mesmo jeito pra "médica" achar
                // "medica" e vice-versa.
                var termo = busca.value.trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
                var linhas = document.querySelectorAll('#lista-comparar .linha-comparar');
                var visiveis = 0;

                linhas.forEach(function (linha) {
                    var marcada = linha.querySelector('.check-comparar').checked;
                    var bate = termo === '' || linha.dataset.busca.indexOf(termo) !== -1;
                    var mostrar = marcada || bate;
                    // NUNCA a propriedade `hidden` aqui: a linha tem a classe
                    // Tailwind `flex` (mesma especificidade de [hidden] no
                    // CSS), e o `display:flex` da classe vence o `display:
                    // none` do atributo — clica em "esconder" e nada some.
                    // style.display sempre ganha de qualquer classe.
                    linha.style.display = mostrar ? '' : 'none';
                    if (mostrar) {
                        visiveis++;
                    }
                });

                var semResultado = document.getElementById('sem-resultado-comparar');
                if (semResultado) {
                    semResultado.hidden = visiveis > 0;
                }
            });
        }
    })();
    </script>
@endif
