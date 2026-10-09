{{--
    "O que mudou desde o envio anterior": só o que foi alterado entre os dois últimos envios (antes → depois).
    Variável: $mudancasDoEnvio (PlanoAcaoComparacao::doUltimoEnvio) — nulo se há um envio só.
--}}
@if (! empty($mudancasDoEnvio))
    @php $semMudanca = $mudancasDoEnvio['mudancas'] === [] && $mudancasDoEnvio['acoes'] === []; @endphp
    <section class="bg-white border-2 border-amber-300 rounded-xl shadow-sm p-6" aria-labelledby="titulo-mudancas">
        <h2 id="titulo-mudancas" class="text-lg font-bold flex items-center gap-2"><i class="ph-bold ph-git-diff text-amber-800" aria-hidden="true"></i> O que mudou desde o envio anterior</h2>
        <p class="text-sm text-slate-600 mt-1">Envio de {{ $mudancasDoEnvio['de'] }} &rarr; envio de {{ $mudancasDoEnvio['para'] }}. Só aparece o que foi alterado.</p>

        @if ($semMudanca)
            <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">O conteúdo do plano é o mesmo do envio anterior.</p>
        @endif

        @if ($mudancasDoEnvio['mudancas'] !== [])
            <div class="mt-4 space-y-3">
                @foreach ($mudancasDoEnvio['mudancas'] as $m)
                    <div class="rounded-xl border border-slate-200 p-3 text-sm">
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ $m['rotulo'] }}</p>
                        <div class="mt-1 grid gap-2 sm:grid-cols-2">
                            <p class="rounded-lg bg-red-50 px-3 py-2 text-red-900 whitespace-pre-line"><span class="block text-xs font-semibold">Antes</span>{{ $m['antes'] }}</p>
                            <p class="rounded-lg bg-emerald-50 px-3 py-2 text-emerald-900 whitespace-pre-line"><span class="block text-xs font-semibold">Depois</span>{{ $m['depois'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($mudancasDoEnvio['acoes'] !== [])
            <h3 class="mt-5 text-sm font-bold">Ações</h3>
            <div class="mt-2 space-y-3">
                @foreach ($mudancasDoEnvio['acoes'] as $a)
                    <div class="rounded-xl border border-slate-200 p-3 text-sm">
                        <p class="font-semibold">
                            <span class="mr-1 rounded-full px-2 py-0.5 text-xs {{ $a['situacao'] === 'nova' ? 'bg-emerald-100 text-emerald-800' : ($a['situacao'] === 'removida' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800') }}">{{ ['nova' => 'Nova', 'removida' => 'Removida', 'alterada' => 'Alterada'][$a['situacao']] }}</span>{{ $a['titulo'] }}
                        </p>
                        @foreach ($a['mudancas'] as $m)
                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                <p class="rounded-lg bg-red-50 px-3 py-2 text-red-900 whitespace-pre-line"><span class="block text-xs font-semibold">{{ $m['rotulo'] }} · antes</span>{{ $m['antes'] }}</p>
                                <p class="rounded-lg bg-emerald-50 px-3 py-2 text-emerald-900 whitespace-pre-line"><span class="block text-xs font-semibold">{{ $m['rotulo'] }} · depois</span>{{ $m['depois'] }}</p>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif
    </section>
@endif
