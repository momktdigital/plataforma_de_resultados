{{--
    Cabeçalho comum das telas do painel da reitoria: saudação, avaliação em foco e as abas do painel.
    Variáveis:
      $ctx     contexto (avaliacao = o recorte, cursosSelecionados, filtrando, corte, meta; ou semResultados)
      $usuario Admin logado
      $aba     'visao' | 'desempenho' | 'trajetoria' | 'competencias' | 'evolucao'
      $chips   (opcional) array de ['valor' => string, 'rotulo' => string]
--}}
@php
    $semResultadosCab = ! empty($ctx['semResultados']);

    // Trocar de aba mantém o recorte (avaliação ou categoria) e os cursos escolhidos.
    $manter = [];
    if (! $semResultadosCab) {
        $manter = array_filter($ctx['filtro'], fn ($v) => $v !== '');
        if ($ctx['filtrando']) {
            $manter['cursos'] = $ctx['cursosSelecionados'];
        }
    }

    $abas = [
        ['id' => 'visao', 'rota' => 'reitor.visao', 'icone' => 'ph-squares-four', 'rotulo' => 'Visão institucional'],
        ['id' => 'desempenho', 'rota' => 'reitor.desempenho', 'icone' => 'ph-chart-bar', 'rotulo' => 'Desempenho'],
        ['id' => 'trajetoria', 'rota' => 'reitor.trajetoria', 'icone' => 'ph-path', 'rotulo' => 'Trajetória no curso'],
        ['id' => 'competencias', 'rota' => 'reitor.competencias', 'icone' => 'ph-brain', 'rotulo' => 'Competências'],
        ['id' => 'evolucao', 'rota' => 'reitor.evolucao', 'icone' => 'ph-chart-line-up', 'rotulo' => 'Evolução entre semestres'],
    ];
    if ($usuario->ehReitor()) {
        $abas[] = ['id' => 'cursos', 'rota' => 'reitor.cursos', 'icone' => 'ph-graduation-cap', 'rotulo' => 'Análise do curso'];
    }
@endphp
<div class="relative overflow-hidden rounded-2xl shadow-lg mb-6" style="background: linear-gradient(135deg, #1e3a5f 0%, #17506a 55%, #0b6b53 100%);">
    <div class="absolute inset-0 opacity-10 pointer-events-none" style="background-image: radial-gradient(circle at 85% 15%, white 0, transparent 45%), radial-gradient(circle at 10% 90%, white 0, transparent 40%);"></div>

    <div class="relative p-6 sm:p-8 pb-5 sm:pb-6 flex flex-col lg:flex-row lg:items-center gap-5 lg:gap-8">
        <div class="min-w-0 flex-1">
            <p id="saudacao-reitor" data-nome="{{ $usuario->nomeParaSaudacao() }}" class="text-white/85 text-sm font-medium uppercase tracking-wide">Olá, {{ $usuario->nomeParaSaudacao() }}</p>
            <h1 class="text-2xl sm:text-3xl font-black text-white break-words">Painel da reitoria</h1>
            <p class="text-white/85 text-sm mt-1">
                @if ($semResultadosCab)
                    Indicadores institucionais de todos os cursos
                @else
                    {{ $ctx['avaliacao']['nome'] }}
                    @if ($ctx['avaliacao']['todosPeriodos']) &middot; todos os períodos letivos
                    @elseif ($ctx['avaliacao']['periodoLetivo'] !== '') &middot; período letivo {{ $ctx['avaliacao']['periodoLetivo'] }} @endif
                    &middot; {{ count($ctx['cursosSelecionados']) }} {{ count($ctx['cursosSelecionados']) === 1 ? 'curso' : 'cursos' }}
                @endif
            </p>
        </div>

        @if (! empty($chips))
            <div class="flex flex-wrap gap-3 shrink-0">
                @foreach ($chips as $chip)
                    <div class="bg-white/15 backdrop-blur-sm rounded-2xl px-4 py-3 text-center min-w-[96px]">
                        <div class="text-2xl font-black text-white">{{ $chip['valor'] }}</div>
                        <div class="text-[11px] text-white/85 font-medium uppercase tracking-wide">{{ $chip['rotulo'] }}</div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <nav class="relative border-t border-white/20 bg-black/10 px-3 sm:px-6 flex gap-1 overflow-x-auto" aria-label="Seções do painel da reitoria">
        @foreach ($abas as $item)
            @php $ativa = $aba === $item['id']; @endphp
            <a href="{{ route($item['rota'], $manter) }}" @if ($ativa) aria-current="page" @endif
               class="shrink-0 inline-flex items-center gap-2 px-4 py-3 text-sm border-b-2 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-white/80 {{ $ativa ? 'border-white text-white font-bold' : 'border-transparent text-white/85 hover:text-white hover:border-white/50 font-medium' }}">
                <i class="ph-bold {{ $item['icone'] }} text-base" aria-hidden="true"></i> {{ $item['rotulo'] }}
            </a>
        @endforeach
    </nav>
</div>

<script>
(function () {
    var el = document.getElementById('saudacao-reitor');
    if (!el) return;
    var hora = new Date().getHours();
    el.textContent = (hora < 12 ? 'Bom dia' : (hora < 18 ? 'Boa tarde' : 'Boa noite')) + ', ' + el.getAttribute('data-nome');
})();
</script>
