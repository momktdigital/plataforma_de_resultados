@if ($estado['alinhamento_referencias']['visivelAdmin'] && ! empty($alinhamentoReferencias))
    @php
        $mediaGeral = $psicometria['media'] ?? null;
        // Alerta relativo à média DESTA prova, não a um corte fixo: numa
        // avaliação difícil (média 50%) um corte de 60% pintaria tudo de
        // vermelho e não apontaria nada. O que interessa é o eixo que ficou
        // bem abaixo do resto da prova.
        $limiteAlerta = $mediaGeral !== null ? $mediaGeral - 10 : null;
        $abaixo = fn (float $p) => $limiteAlerta !== null && $p < $limiteAlerta;
    @endphp
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <div class="flex items-center gap-2 mb-1">
            <h2 class="font-semibold">Alinhamento curricular e regulatório</h2>
            @include('_explicacao', ['explicacao' => $explicacoes['alinhamento_referencias'] ?? null])
        </div>
        <p class="text-sm text-slate-500 mb-5">
            O que a prova cobriu e como foi o desempenho em cada eixo. A contagem de questões é a cobertura:
            um eixo com poucas questões diz muito menos sobre o curso que um com muitas.
        </p>

        @foreach ($alinhamentoReferencias as $tipo => $bloco)
            <div class="{{ ! $loop->last ? 'mb-6' : '' }}">
                <h3 class="text-sm font-semibold text-slate-700 mb-2">{{ $bloco['rotulo'] }}</h3>
                <div class="divide-y divide-slate-100">
                    @foreach ($bloco['itens'] as $item)
                        <div class="grid grid-cols-[minmax(110px,1.4fr)_minmax(0,3fr)_auto_auto] gap-3 items-center py-2 text-sm">
                            <span class="font-medium truncate" title="{{ $item['valor'] }}">{{ $item['valor'] }}</span>
                            <div class="relative h-2.5 rounded-full bg-slate-100">
                                <div class="absolute inset-y-0 left-0 rounded-full"
                                     style="width: {{ min(100, $item['percentual']) }}%; background-color: {{ $abaixo($item['percentual']) ? '#d03b3b' : '#12a37f' }}"></div>
                                @if ($mediaGeral !== null)
                                    <span class="absolute -top-0.5 -bottom-0.5 w-0.5 rounded bg-slate-400"
                                          style="left: {{ min(100, $mediaGeral) }}%"
                                          title="Média geral da avaliação: {{ number_format($mediaGeral, 1, ',', '.') }}%"></span>
                                @endif
                            </div>
                            <span class="tabular-nums font-semibold text-right w-14 {{ $abaixo($item['percentual']) ? 'text-red-600' : '' }}">
                                {{ number_format($item['percentual'], 1, ',', '.') }}%
                            </span>
                            <span class="text-xs text-slate-500 tabular-nums text-right w-20">{{ $item['totalQuestoes'] }} quest.</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        @if ($mediaGeral !== null)
            <p class="text-xs text-slate-500 mt-4 flex items-center gap-2">
                <span class="inline-block w-0.5 h-3 bg-slate-400 rounded"></span>
                marca a média geral da avaliação ({{ number_format($mediaGeral, 1, ',', '.') }}%) — em vermelho, os eixos
                que ficaram 10 pontos ou mais abaixo dela
            </p>
        @endif
    </div>
@endif
