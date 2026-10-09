{{--
    Cartão de gráfico com duas abas — "Geral" e "Por período" (do curso). O gráfico é desenhado por
    public/assets/js/painel-desempenho.js quando a categoria é aberta e a cada troca de aba (os dados vêm de
    <script id="painel-dados">). Variáveis:
      $cat          índice da categoria na tela (chave em painel-dados)
      $tipo         'evolucao' | 'area' | 'bloom' | 'tema'
      $titulo, $subtitulo
      $temPeriodo   false esconde a aba "Por período" (não há dado por período para este gráfico)
      $planoCtx     (opcional) onde o coordenador está (curso, período letivo, categoria): liga o ícone de plano de ação
      $planoItens   (opcional) itens do gráfico para o menu do ícone ([['rotulo', 'valor']]); [] = só "o gráfico inteiro"
--}}
@php $base = "grafico-{$cat}-{$tipo}"; @endphp
<div class="grafico-card bg-white border border-slate-200 rounded-xl shadow-sm p-6" data-cat="{{ $cat }}" data-tipo="{{ $tipo }}">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
        <div class="min-w-0">
            <h3 class="font-semibold">{{ $titulo }}</h3>
            <p class="text-sm text-slate-500">{{ $subtitulo }}</p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
        @if (isset($planoCtx))
            @include('plano._botao', ['visual' => $tipo, 'titulo' => $titulo, 'ctx' => $planoCtx, 'planoItens' => $planoItens ?? []])
        @endif
        <div role="tablist" aria-label="Visão do gráfico: {{ $titulo }}" class="inline-flex shrink-0 rounded-lg bg-slate-100 p-1 gap-1">
            <button type="button" role="tab" id="{{ $base }}-aba-geral" data-aba="geral" aria-selected="true" aria-controls="{{ $base }}-painel"
                    class="grafico-aba rounded-md px-3 py-1.5 text-sm font-semibold bg-slate-800 text-white">Geral</button>
            @if ($temPeriodo)
                <button type="button" role="tab" id="{{ $base }}-aba-periodo" data-aba="periodo" aria-selected="false" aria-controls="{{ $base }}-painel" tabindex="-1"
                        class="grafico-aba rounded-md px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-200">Por período</button>
            @endif
        </div>
        </div>
    </div>
    <div id="{{ $base }}-painel" role="tabpanel" aria-labelledby="{{ $base }}-aba-geral" class="relative" data-area-grafico style="height: 280px">
        <canvas id="{{ $base }}" data-titulo="{{ $titulo }}"></canvas>
    </div>
</div>
