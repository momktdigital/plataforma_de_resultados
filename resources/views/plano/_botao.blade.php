{{--
    O ícone "Iniciar plano de ação" de um visual (gráfico, indicador, linha de tabela) do painel do coordenador. Só aparece
    para o coordenador que pode gravar (não para o reitor olhando o curso, nem para o administrador).

    Sem $itens: um link direto, que abre o plano sobre o visual inteiro.
    Com $itens: um menu — "plano sobre todo o visual" ou sobre um item (uma área, um nível de Bloom, um período do curso...).
    O menu é uma lista de links (funciona com teclado e leitor de tela; não depende de clicar no gráfico).

    Variáveis:
      $visual   chave de App\Models\PlanoAcao::VISUAIS
      $titulo   nome do visual/dado, para o rótulo acessível ("Desempenho por área")
      $ctx      ['curso' => ?string, 'periodo_letivo' => ?string, 'categoria' => ?int, 'avaliacao' => ?int] — onde o coordenador está
      $planoItem    (opcional) o item específico a que o plano se refere
      $planoItens   (opcional) [['rotulo' => string, 'valor' => ?string, 'item' => ?string (padrão = rotulo)]]
      $planoLegenda (opcional) texto visível ao lado do ícone (ex.: "Plano de ação")
      (os opcionais têm prefixo: um @include herda as variáveis da página — um `$item` de um foreach dela vazava para cá)
--}}
@php
    $usuarioPlano = auth('admin')->user();
    $mostrarPlano = $usuarioPlano && $usuarioPlano->ehCoordenador() && ! $usuarioPlano->emVisaoDeCurso;
    $planoItens = $planoItens ?? [];
    $planoItem = $planoItem ?? null;
    $urlPlano = fn (?string $doItem) => route('coordenador.planos.novo', array_filter([...($ctx ?? []), 'visual' => $visual, 'item' => $doItem], fn ($v) => $v !== null));
    // Onde já existe plano vivo: selo no ícone (qualquer item do visual) e marca no item do menu. Uma consulta por requisição.
    $planosDoVisual = $mostrarPlano ? \App\Support\PlanoAcaoMarcas::buscar($usuarioPlano, $ctx ?? [], $visual, $planoItem, $planoItens !== []) : collect();
    $seloPlano = $planosDoVisual->isNotEmpty() ? ($planosDoVisual->count() === 1 ? 'Já existe 1 plano sobre este dado' : 'Já existem '.$planosDoVisual->count().' planos sobre este dado') : null;
    $classePlano = 'relative inline-flex items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-emerald-50 hover:text-emerald-800 hover:border-emerald-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary '.(! empty($planoLegenda) ? 'px-2.5 py-1.5 text-xs font-semibold' : 'h-8 w-8');
@endphp
@if ($mostrarPlano)
    @if ($planoItens === [])
        <a href="{{ $urlPlano($planoItem) }}" class="{{ $classePlano }}" title="{{ $seloPlano ?? 'Iniciar plano de ação' }}" aria-label="Iniciar plano de ação: {{ $titulo }}{{ $planoItem ? ' — '.$planoItem : '' }}{{ $seloPlano ? '. '.$seloPlano : '' }}">
            <i class="ph-bold ph-clipboard-text text-base" aria-hidden="true"></i>@if (! empty($planoLegenda))<span>{{ $planoLegenda }}</span>@endif
            @if ($seloPlano)<span class="absolute -right-1 -top-1 h-2.5 w-2.5 rounded-full bg-emerald-500 ring-2 ring-white" aria-hidden="true"></span>@endif
        </a>
    @else
        <details class="plano-menu relative inline-block text-left">
            <summary class="{{ $classePlano }} cursor-pointer list-none [&::-webkit-details-marker]:hidden" title="{{ $seloPlano ?? 'Iniciar plano de ação' }}" aria-label="Iniciar plano de ação: {{ $titulo }} (escolher o que será o foco){{ $seloPlano ? '. '.$seloPlano : '' }}">
                <i class="ph-bold ph-clipboard-text text-base" aria-hidden="true"></i>@if (! empty($planoLegenda))<span>{{ $planoLegenda }}</span>@endif
                @if ($seloPlano)<span class="absolute -right-1 -top-1 h-2.5 w-2.5 rounded-full bg-emerald-500 ring-2 ring-white" aria-hidden="true"></span>@endif
            </summary>
            <div data-plano-lista class="absolute left-0 z-30 mt-1 w-72 max-w-[85vw] max-h-80 overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-lg">
                <p class="px-3 pt-2 pb-1 text-xs font-bold uppercase tracking-wide text-slate-500">Plano de ação sobre…</p>
                <a href="{{ $urlPlano(null) }}" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-emerald-50 focus:bg-emerald-50 focus:outline-none">
                    <i class="ph-bold ph-squares-four text-emerald-700" aria-hidden="true"></i> {{ $titulo }} (visão inteira)
                    @if ($planosDoVisual->contains(fn ($p) => $p->origem_item === null))<span class="ml-auto rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-800">já tem plano</span>@endif
                </a>
                @foreach ($planoItens as $linha)
                    @php $doItem = $planosDoVisual->first(fn ($p) => $p->origem_item === ($linha['item'] ?? $linha['rotulo'])); @endphp
                    <a href="{{ $urlPlano($linha['item'] ?? $linha['rotulo']) }}" class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-emerald-50 focus:bg-emerald-50 focus:outline-none">
                        <span class="min-w-0 truncate">{{ $linha['rotulo'] }}</span>
                        <span class="flex shrink-0 items-center gap-2">
                            @if ($doItem)<span class="inline-flex items-center gap-1 whitespace-nowrap rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-800" title="Já existe um plano sobre este item"><i class="ph-bold ph-clipboard-text" aria-hidden="true"></i><span class="sr-only">plano: </span>{{ mb_strtolower($doItem->rotuloStatus()) }}</span>@endif
                            @if (! empty($linha['valor']))<span class="text-xs font-semibold text-slate-600">{{ $linha['valor'] }}</span>@endif
                        </span>
                    </a>
                @endforeach
            </div>
        </details>
        @once
            <script>
                // Um menu aberto por vez; fecha ao clicar fora e com Esc (devolvendo o foco ao botão).
                (function () {
                    // O menu abre alinhado à esquerda do botão; se passaria da borda direita da janela, alinha à direita.
                    document.addEventListener('toggle', function (e) {
                        var menu = e.target;
                        if (!menu.classList || !menu.classList.contains('plano-menu') || !menu.open) return;
                        var lista = menu.querySelector('[data-plano-lista]');
                        lista.classList.add('left-0');
                        lista.classList.remove('right-0');
                        if (lista.getBoundingClientRect().right > window.innerWidth - 8) {
                            lista.classList.remove('left-0');
                            lista.classList.add('right-0');
                        }
                    }, true);
                    document.addEventListener('click', function (e) {
                        document.querySelectorAll('details.plano-menu[open]').forEach(function (menu) {
                            if (!menu.contains(e.target)) menu.removeAttribute('open');
                        });
                    });
                    document.addEventListener('keydown', function (e) {
                        if (e.key !== 'Escape') return;
                        var aberto = document.querySelector('details.plano-menu[open]');
                        if (!aberto) return;
                        aberto.removeAttribute('open');
                        var botao = aberto.querySelector('summary');
                        if (botao) botao.focus();
                    });
                })();
            </script>
        @endonce
    @endif
@endif
