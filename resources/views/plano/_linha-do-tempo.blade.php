{{--
    O histórico do plano (eventos, do mais recente ao mais antigo). Variáveis: $plano (com `eventos.admin` e `acoes`).
--}}
@php
    $rotuloAcao = fn ($id) => $id === null ? null : ($plano->acoes->firstWhere('id', $id)?->descricao);
@endphp
<section class="bg-white border border-slate-200 rounded-xl shadow-sm p-6" aria-labelledby="titulo-historico">
    <h2 id="titulo-historico" class="text-lg font-bold mb-4">Histórico</h2>
    @if ($plano->eventos->isEmpty())
        <p class="text-sm text-slate-500">Nada registrado ainda.</p>
    @else
        <ol class="space-y-4">
            @foreach ($plano->eventos as $evento)
                <li class="flex gap-3">
                    <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-700" aria-hidden="true"><i class="ph-bold {{ $evento->icone() }}"></i></span>
                    <div class="min-w-0 flex-1 text-sm">
                        <p>
                            <span class="font-semibold">{{ $evento->rotulo() }}</span>
                            <span class="text-slate-500">· {{ $evento->admin?->username ?? 'Sistema' }} · {{ $evento->created_at?->format('d/m/Y H:i') }}</span>
                        </p>
                        @if ($evento->acao_id && $rotuloAcao($evento->acao_id))
                            <p class="text-xs text-slate-500 truncate">Ação: {{ $rotuloAcao($evento->acao_id) }}</p>
                        @endif
                        @if ($evento->tipo === \App\Models\PlanoAcaoEvento::ACAO_STATUS && ! empty($evento->dados))
                            <p class="text-xs text-slate-600">{{ \App\Models\PlanoAcaoAcao::STATUS[$evento->dados['de'] ?? ''] ?? '—' }} &rarr; <strong>{{ \App\Models\PlanoAcaoAcao::STATUS[$evento->dados['para'] ?? ''] ?? '—' }}</strong></p>
                        @elseif ($evento->tipo === \App\Models\PlanoAcaoEvento::PRAZO && ! empty($evento->dados))
                            <p class="text-xs text-slate-600">{{ ! empty($evento->dados['de']) ? \Illuminate\Support\Carbon::parse($evento->dados['de'])->format('d/m/Y') : '—' }} &rarr; <strong>{{ \Illuminate\Support\Carbon::parse($evento->dados['para'])->format('d/m/Y') }}</strong></p>
                        @endif
                        @if ($evento->texto)
                            <p class="mt-1 whitespace-pre-line text-slate-700">{{ $evento->texto }}</p>
                        @endif
                        @if ($evento->relationLoaded('anexos'))
                            @include('plano._anexos', ['listaAnexos' => $evento->anexos, 'plano' => $plano])
                        @endif
                        @if (! empty($evento->dados['criterios']))
                            <ul class="mt-1 space-y-0.5 text-xs">
                                @foreach ($evento->dados['criterios'] as $chave => $ok)
                                    <li class="flex items-start gap-1.5 {{ $ok ? 'text-emerald-800' : 'text-amber-800' }}">
                                        <i class="ph-bold {{ $ok ? 'ph-check-circle' : 'ph-warning-circle' }} mt-0.5" aria-hidden="true"></i>
                                        <span><span class="sr-only">{{ $ok ? 'Atendido: ' : 'Não atendido: ' }}</span>{{ \App\Services\PlanoAcaoService::CRITERIOS[$chave] ?? $chave }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</section>
