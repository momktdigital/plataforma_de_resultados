@php
    $temPerfil = $estado['perfil_demografico']['visivelAdmin'] && $perfilDemografico !== null;
    $temEquidade = $estado['equidade_demografica']['visivelAdmin'] && ! empty($equidade);
@endphp

@if ($temPerfil || $temEquidade)
        <div class="mb-6">
        <h2 class="text-lg font-bold mb-1">Análise demográfica</h2>
        <p class="text-sm text-slate-500 mb-4">
            Quem fez esta avaliação e como cada recorte se saiu. O perfil descreve a composição do grupo;
            a equidade logo abaixo mostra o desempenho de cada um desses mesmos recortes — inclusive a
            <strong>forma de ingresso</strong> (Vestibular, ENEM, PROUNI...), para ver se ela influencia o desempenho.
        </p>

        @if ($temPerfil)
        <div class="grid lg:grid-cols-2 xl:grid-cols-4 gap-6 mb-6">
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
            <div class="flex items-center gap-2 mb-3">
                <h3 class="font-semibold">Sexo</h3>
                @include('_explicacao', ['explicacao' => $explicacoes['perfil_sexo'] ?? null])
            </div>
            @if (empty($perfilDemografico['sexo']))
                <p class="text-sm text-slate-500">Sem dados.</p>
            @else
                <canvas id="grafico-sexo" height="200"></canvas>
            @endif
        </div>

        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
            <div class="flex items-center gap-2 mb-3">
                <h3 class="font-semibold">Cor/raça</h3>
                @include('_explicacao', ['explicacao' => $explicacoes['perfil_cor_raca'] ?? null])
            </div>
            @if (empty($perfilDemografico['cor_raca']))
                <p class="text-sm text-slate-500">Sem dados.</p>
            @else
                <canvas id="grafico-cor-raca" height="200"></canvas>
            @endif
        </div>

        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
            <div class="flex items-center gap-2 mb-3">
                <h3 class="font-semibold">Forma de ingresso</h3>
            </div>
            @if (empty($perfilDemografico['forma_ingresso']))
                <p class="text-sm text-slate-500">Sem dados. A forma de ingresso vem da planilha de alunos (coluna "Forma de ingresso").</p>
            @else
                <canvas id="grafico-forma-ingresso" height="200"></canvas>
            @endif
        </div>

        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
            <div class="flex items-center gap-2 mb-3">
                <h3 class="font-semibold">UF</h3>
                @include('_explicacao', ['explicacao' => $explicacoes['perfil_uf'] ?? null])
            </div>
            @if (empty($perfilDemografico['uf']))
                <p class="text-sm text-slate-500">Sem dados.</p>
            @else
                @php
                    $maximoUf = max($perfilDemografico['uf']);
                    $topUf = array_slice($perfilDemografico['uf'], 0, 8, true);
                @endphp
                <svg viewBox="{{ \App\Support\MapaBrasilSvg::viewBox() }}" class="w-full h-auto mb-3">
                    @foreach (\App\Support\MapaBrasilSvg::caminhos() as $uf => $d)
                        @php $valorUf = $perfilDemografico['uf'][$uf] ?? null; @endphp
                        <path d="{{ $d }}" fill="{{ \App\Support\MapaBrasilSvg::corPorValor($valorUf, $maximoUf) }}"
                              stroke="#fff" stroke-width="1"><title>{{ $uf }}: {{ $valorUf ?? 0 }}</title></path>
                    @endforeach
                </svg>
                <ul class="text-xs space-y-1">
                    @foreach ($topUf as $uf => $total)
                        <li class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-sm shrink-0" style="background-color: {{ \App\Support\MapaBrasilSvg::corPorValor($total, $maximoUf) }}"></span>
                            <span class="text-slate-600 flex-1">{{ $uf }}</span>
                            <span class="font-bold">{{ $total }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
        @endif

        @if ($temEquidade)
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <div class="flex items-center gap-2 mb-1">
            <h3 class="font-semibold">Equidade: desempenho por recorte</h3>
            @include('_explicacao', ['explicacao' => $explicacoes['equidade_demografica'] ?? null])
        </div>
        <p class="text-sm text-slate-500 mb-5">
            Monitoramento institucional, não avaliação de indivíduo. Grupos com menos de 10 respondentes
            são omitidos — num grupo pequeno, a média do grupo identifica a pessoa.
        </p>

        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            @foreach ($equidade as $recorte)
                @php
                    $maior = collect($recorte['grupos'])->max('media');
                    $menor = collect($recorte['grupos'])->min('media');
                @endphp
                <div>
                    <div class="flex items-baseline justify-between gap-2 mb-2">
                        <h3 class="text-sm font-semibold text-slate-700">{{ $recorte['rotulo'] }}</h3>
                        <span class="text-xs tabular-nums {{ ($maior - $menor) >= 10 ? 'text-amber-700 font-semibold' : 'text-slate-500' }}">
                            {{ number_format($maior - $menor, 1, ',', '.') }} pp de diferença
                        </span>
                    </div>
                    <div class="space-y-2">
                        @foreach ($recorte['grupos'] as $grupo)
                            <div class="text-sm">
                                <div class="flex items-baseline justify-between gap-2">
                                    <span class="truncate" title="{{ $grupo['valor'] }}">{{ $grupo['valor'] }}</span>
                                    <span class="tabular-nums font-semibold">{{ number_format($grupo['media'], 1, ',', '.') }}%</span>
                                </div>
                                <div class="h-2 rounded-full bg-slate-100 mt-1">
                                    <div class="h-full rounded-full" style="width: {{ min(100, $grupo['media']) }}%; background-color: #2a78d6"></div>
                                </div>
                                <span class="text-[11px] text-slate-500">{{ $grupo['respondentes'] }} respondente(s)</span>
                            </div>
                        @endforeach
                    </div>
                    @if ($recorte['suprimidos'] > 0)
                        <p class="text-[11px] text-slate-500 mt-2">
                            {{ $recorte['suprimidos'] }} grupo(s) omitido(s) por terem menos de 10 respondentes.
                        </p>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
        @endif
    </div>
@endif
