{{--
    Acompanhamento das ações de um plano aprovado: situação, prazo (com aviso de atraso) e a última nota de cada ação.
    Com $podeAtualizar (o coordenador, plano em execução), cada ação traz o formulário para atualizar situação, prazo e nota.
    Variáveis: $plano (com `acoes` e `eventos`), $podeAtualizar (bool).
--}}
@php
    use App\Models\PlanoAcaoAcao;

    $progresso = $plano->progresso();
    $campo = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary';
@endphp
<section class="bg-white border border-slate-200 rounded-xl shadow-sm p-6" aria-labelledby="titulo-acompanhamento">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <h2 id="titulo-acompanhamento" class="text-lg font-bold">Acompanhamento das ações</h2>
        <div class="flex items-center gap-3 min-w-[220px]">
            <div class="h-2.5 flex-1 rounded-full bg-slate-100 overflow-hidden" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progresso['pct'] }}" aria-label="Ações concluídas">
                <div class="h-full rounded-full bg-emerald-500" style="width: {{ $progresso['pct'] }}%"></div>
            </div>
            <span class="text-sm font-semibold whitespace-nowrap">{{ $progresso['concluidas'] }} de {{ $progresso['total'] }} concluídas</span>
        </div>
    </div>
    @if ($progresso['atrasadas'] > 0)
        <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 flex items-center gap-2" role="status">
            <i class="ph-bold ph-warning-circle" aria-hidden="true"></i>
            {{ $progresso['atrasadas'] === 1 ? '1 ação está com o prazo vencido.' : $progresso['atrasadas'].' ações estão com o prazo vencido.' }}
        </p>
    @endif

    <ol class="space-y-4">
        @foreach ($plano->acoes as $i => $acao)
            @php
                $ultima = $plano->eventos->first(fn ($e) => $e->acao_id === $acao->id && $e->texto);
                $dias = $acao->diasParaOPrazo();
                [$icone, $classe] = match ($acao->status) {
                    PlanoAcaoAcao::CONCLUIDA => ['ph-check-circle', 'bg-emerald-100 text-emerald-800'],
                    PlanoAcaoAcao::EM_ANDAMENTO => ['ph-play-circle', 'bg-sky-100 text-sky-800'],
                    PlanoAcaoAcao::CANCELADA => ['ph-prohibit', 'bg-slate-100 text-slate-700'],
                    default => ['ph-clock', 'bg-slate-100 text-slate-700'],
                };
            @endphp
            <li id="acao-{{ $acao->id }}" class="rounded-xl border {{ $acao->estaAtrasada() ? 'border-red-300' : 'border-slate-200' }} p-4 text-sm">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <p class="font-bold text-slate-900 min-w-0 flex-1"><span class="text-slate-500">{{ $i + 1 }}.</span> {{ $acao->descricao }}</p>
                    <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap {{ $classe }}"><i class="ph-bold {{ $icone }}" aria-hidden="true"></i>{{ $acao->rotuloStatus() }}</span>
                </div>
                <p class="mt-1 text-xs text-slate-600">
                    Responsável: <strong>{{ $acao->responsavel ?: '—' }}</strong>
                    · Prazo: <strong>{{ $acao->prazo?->format('d/m/Y') ?? '—' }}</strong>
                    @if ($acao->estaAtrasada())
                        <span class="font-semibold text-red-700">(vencido há {{ abs($dias) }} {{ abs($dias) === 1 ? 'dia' : 'dias' }})</span>
                    @elseif ($acao->estaAberta() && $dias !== null && $dias <= 7)
                        <span class="font-semibold text-amber-700">({{ $dias === 0 ? 'vence hoje' : ($dias === 1 ? 'vence amanhã' : "vence em {$dias} dias") }})</span>
                    @endif
                    @if ($acao->concluida_em)
                        · Concluída em {{ $acao->concluida_em->format('d/m/Y') }}
                    @endif
                </p>
                @include('plano._anexos', ['listaAnexos' => $plano->anexos->where('acao_id', $acao->id), 'plano' => $plano])
                @if ($ultima)
                    <p class="mt-2 rounded-lg bg-slate-50 border border-slate-100 px-3 py-2 text-slate-700">
                        <span class="text-xs font-semibold text-slate-500">Última nota ({{ $ultima->created_at?->format('d/m/Y') }}):</span>
                        <span class="whitespace-pre-line">{{ $ultima->texto }}</span>
                    </p>
                @endif

                @if ($podeAtualizar && $acao->status !== PlanoAcaoAcao::CANCELADA)
                    <details class="mt-3 rounded-lg border border-slate-200 bg-slate-50">
                        <summary class="cursor-pointer list-none [&::-webkit-details-marker]:hidden px-3 py-2 text-sm font-semibold text-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded-lg">Atualizar esta ação</summary>
                        <form method="POST" enctype="multipart/form-data" action="{{ route('coordenador.planos.acoes.update', [$plano, $acao]) }}" class="px-3 pb-3 pt-1 space-y-3">
                            @csrf
                            @method('PUT')
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label for="acao-{{ $acao->id }}-status" class="block text-xs font-semibold text-slate-700 mb-1">Situação</label>
                                    <select id="acao-{{ $acao->id }}-status" name="status" class="{{ $campo }}">
                                        @foreach (PlanoAcaoAcao::STATUS as $valor => $rotulo)
                                            <option value="{{ $valor }}" @selected($acao->status === $valor)>{{ $rotulo }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="acao-{{ $acao->id }}-prazo" class="block text-xs font-semibold text-slate-700 mb-1">Prazo <span class="font-normal text-slate-500">(mudar exige a nota abaixo)</span></label>
                                    <input id="acao-{{ $acao->id }}-prazo" name="prazo" type="date" value="{{ $acao->prazo?->toDateString() }}" class="{{ $campo }}">
                                </div>
                            </div>
                            <div>
                                <label for="acao-{{ $acao->id }}-nota" class="block text-xs font-semibold text-slate-700 mb-1">Nota de andamento</label>
                                <p class="text-xs text-slate-500 mb-1">O que foi feito, quantos estudantes foram alcançados, evidências. Obrigatória para concluir a ação ou reprogramar o prazo.</p>
                                <textarea id="acao-{{ $acao->id }}-nota" name="nota" rows="3" maxlength="3000" class="{{ $campo }}"></textarea>
                            </div>
                            @include('plano._campos-evidencia', ['prefixo' => 'acao-'.$acao->id])
                            <button type="submit" class="rounded-lg bg-slate-800 hover:bg-slate-900 px-4 py-2 text-sm font-semibold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">Salvar atualização</button>
                        </form>
                    </details>
                @endif
            </li>
        @endforeach
    </ol>
</section>
