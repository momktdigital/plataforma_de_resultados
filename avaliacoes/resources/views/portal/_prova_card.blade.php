{{--
    $r: uma entrada do array retornado por ResultadoConsultaService.
    Card resumido (nome, data, % de acerto) — o detalhe completo (gabarito,
    métricas) só é montado quando o aluno clica, dentro de um modal, pra não
    carregar tudo já aberto na tela quando há muitas provas.
--}}
@php
    $idUnico = $r['prova']->codigo.'-'.\Illuminate\Support\Str::slug($r['periodo'] !== '' ? $r['periodo'] : 'sem-periodo');
@endphp
<button type="button" onclick="portalAbrirDetalhe('{{ $idUnico }}')"
        class="prova-card w-full text-left bg-white rounded-xl border border-slate-200 shadow-sm hover:border-primary hover:shadow-md transition-all p-4 flex items-center justify-between gap-4 mb-3"
        data-data="{{ $r['prova']->data_prova?->format('Y-m-d') ?? '' }}">
    <div class="flex items-center gap-3 min-w-0">
        <div class="w-11 h-11 rounded-lg bg-emerald-50 flex items-center justify-center shrink-0">
            <i class="ph-fill ph-exam text-primary text-xl"></i>
        </div>
        <div class="min-w-0">
            <div class="font-bold text-slate-800 truncate">{{ $r['prova']->nome ?? "Prova #{$r['prova']->codigo}" }}</div>
            <div class="text-xs text-slate-500 mt-0.5">
                @if ($r['prova']->data_prova)
                    {{ $r['prova']->data_prova->format('d/m/Y') }} &middot;
                @endif
                Período: {{ $r['periodo'] !== '' ? $r['periodo'] : '—' }}
            </div>
        </div>
    </div>
    <div class="flex items-center gap-3 shrink-0">
        @if ($r['total'] > 0)
            <div class="text-right">
                <div class="text-xl font-black text-primary">{{ $r['percentual'] }}%</div>
                <div class="text-[11px] text-slate-500 font-medium">{{ $r['acertos'] }}/{{ $r['total'] }}</div>
            </div>
        @endif
        <i class="ph-bold ph-caret-right text-slate-300 text-lg"></i>
    </div>
</button>

<div id="modal-{{ $idUnico }}" class="prova-modal hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm items-center justify-center p-4"
     onclick="if (event.target === this) portalFecharDetalhe('{{ $idUnico }}')">
    <div class="bg-white rounded-2xl shadow-xl max-w-2xl w-full max-h-[90vh] flex flex-col">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between shrink-0">
            <h3 class="font-bold text-slate-800 flex items-center gap-2">
                <i class="ph-fill ph-exam text-primary"></i> Detalhes da prova
            </h3>
            <button type="button" onclick="portalFecharDetalhe('{{ $idUnico }}')" class="text-slate-400 hover:text-red-500 transition-colors">
                <i class="ph-bold ph-x text-xl"></i>
            </button>
        </div>

        <div class="overflow-y-auto p-6">
            <div id="pdf-conteudo-{{ $idUnico }}" data-prova-nome="{{ $r['prova']->nome ?? "Prova #{$r['prova']->codigo}" }}"
                 data-prova-info="@if($r['prova']->data_prova){{ $r['prova']->data_prova->format('d/m/Y') }} &middot; @endif Período: {{ $r['periodo'] !== '' ? $r['periodo'] : '—' }}">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h4 class="text-lg font-bold text-slate-800">{{ $r['prova']->nome ?? "Prova #{$r['prova']->codigo}" }}</h4>
                        <p class="text-sm text-slate-500 mt-0.5">
                            @if ($r['prova']->data_prova)
                                {{ $r['prova']->data_prova->format('d/m/Y') }} &middot;
                            @endif
                            Período: {{ $r['periodo'] !== '' ? $r['periodo'] : '—' }}
                        </p>
                    </div>
                    @if ($r['total'] > 0)
                        <div class="text-right shrink-0">
                            <div class="text-3xl font-black text-primary">{{ $r['percentual'] }}%</div>
                            <div class="text-xs text-slate-500 font-medium">{{ $r['acertos'] }}/{{ $r['total'] }} acertos</div>
                        </div>
                    @endif
                </div>

                @if ($r['prova']->link_comentado)
                    <a href="{{ $r['prova']->link_comentado }}" target="_blank" rel="noopener"
                       class="inline-flex items-center mb-4 text-sm font-medium text-primary hover:underline">
                        <i class="ph-bold ph-link mr-1.5"></i> Acessar gabarito comentado
                    </a>
                @endif

                @if ($r['metricas']->isNotEmpty())
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
                        @foreach ($r['metricas'] as $metrica)
                            <div class="bg-slate-50 border border-slate-100 rounded-lg p-3">
                                <div class="text-xs font-bold text-slate-500 uppercase truncate" title="{{ $metrica->nome_metrica }}">{{ $metrica->nome_metrica }}</div>
                                <div class="text-lg font-black text-slate-800">{{ $metrica->valor }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-3">Detalhamento das respostas</p>
                <div class="grid grid-cols-5 sm:grid-cols-8 md:grid-cols-10 gap-2">
                    @foreach ($r['respostas'] as $resposta)
                        @php
                            $correta = $r['gabaritos'][$resposta->questao_numero] ?? null;
                            $marcada = $resposta->resposta ?: '';
                            $cor = 'bg-slate-400';
                            if ($correta !== null && $correta !== '') {
                                $cor = $marcada === $correta ? 'bg-green-500' : ($marcada === '' ? 'bg-slate-400' : 'bg-red-500');
                            }
                        @endphp
                        <div class="rounded-lg overflow-hidden border border-slate-200 shadow-sm">
                            <div class="{{ $cor }} text-white text-[10px] text-center font-bold py-1">Q{{ $resposta->questao_numero }}</div>
                            <div class="bg-white text-center font-bold text-sm py-1.5 {{ $marcada === '' ? 'text-slate-300' : 'text-slate-700' }}">
                                {{ $marcada !== '' ? $marcada : '-' }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="px-6 py-4 border-t border-slate-100 flex justify-end gap-2 shrink-0">
            <button type="button" onclick="portalFecharDetalhe('{{ $idUnico }}')"
                    class="border border-slate-300 text-slate-700 hover:bg-slate-50 rounded-lg px-4 py-2 text-sm font-medium">
                Fechar
            </button>
            <button type="button" onclick="portalExportarPdfProva('{{ $idUnico }}')" class="btn-pdf-prova
                    inline-flex items-center bg-slate-800 hover:bg-slate-700 text-white rounded-lg px-4 py-2 text-sm font-medium">
                <i class="ph-bold ph-file-pdf mr-2 text-red-400"></i> Baixar PDF desta prova
            </button>
        </div>
    </div>
</div>
