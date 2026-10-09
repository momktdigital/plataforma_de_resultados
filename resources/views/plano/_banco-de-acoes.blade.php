{{--
    Ideias de ações de planos já concluídos sobre o mesmo tipo de dado (anônimas). "Usar esta ação" copia o texto para uma ação
    do plano, que o coordenador edita. Variável: $bancoDeAcoes (PlanoAcaoBancoDeAcoes::sugerir).
--}}
@if (! empty($bancoDeAcoes))
    <details class="rounded-xl border border-emerald-200 bg-emerald-50/50">
        <summary class="cursor-pointer list-none [&::-webkit-details-marker]:hidden px-4 py-3 text-sm font-semibold text-emerald-900 flex items-center justify-between gap-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded-xl">
            <span class="flex items-center gap-2"><i class="ph-bold ph-lightbulb" aria-hidden="true"></i> Ideias de planos já concluídos ({{ count($bancoDeAcoes) }})</span>
            <i class="ph-bold ph-caret-down" aria-hidden="true"></i>
        </summary>
        <div class="px-4 pb-4 space-y-3">
            <p class="text-xs text-emerald-900">Ações que foram executadas e concluídas em planos sobre o mesmo tipo de dado. São anônimas (sem curso nem responsável) e só servem de ponto de partida: leia, adapte à sua causa-raiz e ao seu contexto.</p>
            <ul class="space-y-2">
                @foreach ($bancoDeAcoes as $ideia)
                    <li class="rounded-lg border border-emerald-200 bg-white p-3 text-sm"
                        data-ideia-descricao="{{ $ideia['descricao'] }}" data-ideia-execucao="{{ $ideia['execucao'] }}" data-ideia-verificacao="{{ $ideia['verificacao'] }}">
                        <p class="font-semibold text-slate-900">{{ $ideia['descricao'] }}</p>
                        @if ($ideia['execucao'] !== '')<p class="mt-1 text-slate-700 whitespace-pre-line"><span class="text-xs font-semibold text-slate-500">Como foi executada:</span> {{ \Illuminate\Support\Str::limit($ideia['execucao'], 300) }}</p>@endif
                        @if ($ideia['verificacao'] !== '')<p class="mt-1 text-slate-700 whitespace-pre-line"><span class="text-xs font-semibold text-slate-500">Como foi verificada:</span> {{ \Illuminate\Support\Str::limit($ideia['verificacao'], 300) }}</p>@endif
                        <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                            <span class="text-xs text-slate-500">{{ $ideia['vezes'] === 1 ? 'Usada em 1 plano concluído' : 'Usada em '.$ideia['vezes'].' planos concluídos' }}</span>
                            <button type="button" data-usar-ideia class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-300 bg-white px-3 py-1.5 text-xs font-semibold text-emerald-800 hover:bg-emerald-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                <i class="ph-bold ph-plus" aria-hidden="true"></i> Usar esta ação
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    </details>
@endif
