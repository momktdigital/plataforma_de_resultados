@php
    $siteTitle = \App\Models\Configuracao::valor('site_title', 'Resultados DI');
    $siteLogo = \App\Models\Configuracao::valor('site_logo', '');
    $siteLogoDark = \App\Models\Configuracao::valor('site_logo_dark', '');
    // Sidebar do admin é sempre fundo escuro (bg-slate-950) — se nada foi
    // configurado ainda, mostra esta logo em vez do ícone genérico ph-exam.
    // Nunca substitui uma logo que o admin já configurou (clara ou escura).
    $siteLogoDarkPadrao = 'uploads/logos/1788356993_logo_dark_0e5723791771.png';
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Avaliações') — {{ $siteTitle }}</title>
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { primary: '#00b48d', secondary: '#f8fafc', dark: '#1e293b' },
                    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
                },
            },
        };
    </script>
    <script>
        // Menu lateral recolhido (telas largas): a escolha fica guardada neste navegador e é aplicada ANTES de pintar a página.
        try { if (localStorage.getItem('menuLateralOculto') === '1') document.documentElement.classList.add('menu-oculto'); } catch (e) {}
    </script>
    @include('partials.accessibility-head')
    <style>
        body { font-family: 'Inter', sans-serif; }
        /* Abaixo de 768px (o `md:` do Tailwind começa EM 768px): com "max-width: 768px" a barra lateral ficava escondida
           justamente em 768px, onde o botão do menu (md:hidden) também some — o menu ficava inalcançável. */
        /* Telas largas: o botão da barra superior recolhe/exibe o menu lateral (no celular ele já é uma gaveta). */
        @media (min-width: 768px) {
            html.menu-oculto #sidebar { display: none; }
        }
        @media (max-width: 767.98px) {
            /* visibility: escondida fora da tela, a barra também sai da ordem de tabulação do teclado */
            .sidebar { transform: translateX(-100%); visibility: hidden; transition: transform .3s ease-in-out, visibility 0s linear .3s; z-index: 50; position: fixed; height: 100vh; }
            .sidebar.open { transform: translateX(0); visibility: visible; transition-delay: 0s; }
            .overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 40; }
            .overlay.open { display: block; }
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 font-sans">
<a href="#conteudo-principal"
   class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[100] focus:bg-white focus:text-slate-900 focus:font-semibold focus:px-4 focus:py-2 focus:rounded-lg focus:shadow-lg focus:ring-2 focus:ring-primary">
    Pular para o conteúdo
</a>
@auth('admin')
    <div class="h-screen flex overflow-hidden print:h-auto print:block print:overflow-visible">
        <div id="sidebar-overlay" class="overlay" onclick="toggleSidebar()"></div>

        <aside id="sidebar" class="sidebar w-64 bg-slate-900 text-slate-300 flex flex-col shrink-0 print:hidden" aria-label="Barra lateral">
            <div class="h-16 flex items-center px-6 border-b border-slate-800 bg-slate-950">
                @if ($siteLogoDark || $siteLogo)
                    <img src="{{ asset('uploads/logos/'.basename($siteLogoDark ?: $siteLogo)) }}" alt="{{ $siteTitle }}" class="h-8 object-contain">
                @else
                    <img src="{{ asset($siteLogoDarkPadrao) }}" alt="{{ $siteTitle }}" class="h-8 object-contain">
                @endif
            </div>

            @php
                $usuarioLogado = auth('admin')->user();
                $ehCoordenador = $usuarioLogado->ehCoordenador();
                $ehReitor = $usuarioLogado->ehReitor();
                $ehColaborador = $usuarioLogado->ehColaborador();
                // O reitor olhando UM curso como o coordenador dele vê (Admin::comoCoordenadorDe): sem sino de notificações.
                $emVisaoDeCurso = $usuarioLogado->emVisaoDeCurso;
                $sino = $ehCoordenador && ! $emVisaoDeCurso;
                $naoLidas = $ehCoordenador ? $usuarioLogado->notificacoesNaoLidas() : 0;
                // Planos de ação: o coordenador vê quantos foram devolvidos para ajustes; quem analisa (colaborador, administrador), quantos aguardam.
                $planosPendentes = $ehCoordenador
                    ? \App\Services\PlanoAcaoService::comAjustes($usuarioLogado)
                    : (($ehColaborador || $usuarioLogado->ehAdministrador()) ? \App\Services\PlanoAcaoService::aguardandoAnalise() : 0);
            @endphp

            {{-- Busca global procura alunos de qualquer curso: só administrador. --}}
            @if ($usuarioLogado->ehAdministrador())
                <div class="px-3 pt-4">
                    <form method="GET" action="{{ route('busca.index') }}">
                        <div class="relative">
                            <i class="ph ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-500"></i>
                            <input type="text" name="q" placeholder="Buscar aluno ou avaliação..."
                                   class="w-full pl-9 pr-3 py-2 rounded-lg bg-slate-800 border border-slate-700 text-sm text-slate-200 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                    </form>
                </div>
            @endif

            <nav class="flex-1 overflow-y-auto py-4" aria-label="Menu principal">
                <ul class="space-y-1 px-3">
                    @php
                        $itensMenu = $ehReitor
                            ? [
                                ['rota' => 'reitor.visao', 'padrao' => 'reitor.visao', 'icone' => 'ph-squares-four', 'label' => 'Visão institucional'],
                                ['rota' => 'reitor.desempenho', 'padrao' => 'reitor.desempenho', 'icone' => 'ph-chart-bar', 'label' => 'Desempenho'],
                                ['rota' => 'reitor.trajetoria', 'padrao' => 'reitor.trajetoria', 'icone' => 'ph-path', 'label' => 'Trajetória no curso'],
                                ['rota' => 'reitor.competencias', 'padrao' => 'reitor.competencias', 'icone' => 'ph-brain', 'label' => 'Competências'],
                                ['rota' => 'reitor.evolucao', 'padrao' => 'reitor.evolucao', 'icone' => 'ph-chart-line-up', 'label' => 'Evolução entre semestres'],
                                ['rota' => 'reitor.itens', 'padrao' => 'reitor.itens', 'icone' => 'ph-list-checks', 'label' => 'Análise dos itens'],
                                ['rota' => 'reitor.risco', 'padrao' => 'reitor.risco', 'icone' => 'ph-warning-diamond', 'label' => 'Estudantes em risco'],
                                ['rota' => 'reitor.cursos', 'padrao' => 'reitor.cursos', 'icone' => 'ph-graduation-cap', 'label' => 'Análise do curso'],
                                ['rota' => 'perfil.edit', 'padrao' => 'perfil.*', 'icone' => 'ph-user-circle', 'label' => 'Meu Perfil'],
                            ]
                            : ($ehCoordenador
                            ? [
                                ['rota' => 'coordenador.painel', 'padrao' => 'coordenador.painel', 'icone' => 'ph-squares-four', 'label' => 'Visão geral'],
                                ['rota' => 'coordenador.desempenho', 'padrao' => 'coordenador.desempenho', 'icone' => 'ph-chart-line-up', 'label' => 'Desempenho'],
                                ['rota' => 'avaliacoes.index', 'padrao' => 'avaliacoes.*', 'icone' => 'ph-exam', 'label' => 'Avaliações'],
                                ['rota' => 'coordenador.comparativo', 'padrao' => 'coordenador.comparativo', 'icone' => 'ph-arrows-left-right', 'label' => 'Comparar semestres'],
                                ['rota' => 'coordenador.alunos', 'padrao' => 'coordenador.alunos*', 'icone' => 'ph-users-three', 'label' => 'Alunos do curso'],
                                ['rota' => 'coordenador.planos.index', 'padrao' => 'coordenador.planos.*', 'icone' => 'ph-clipboard-text', 'label' => 'Planos de ação', 'badge' => $planosPendentes],
                                ['rota' => 'cronograma.index', 'padrao' => 'cronograma.*', 'icone' => 'ph-calendar-check', 'label' => 'Cronograma'],
                                ['rota' => 'notificacoes.index', 'padrao' => 'notificacoes.*', 'icone' => 'ph-bell', 'label' => 'Notificações'],
                                ['rota' => 'perfil.edit', 'padrao' => 'perfil.*', 'icone' => 'ph-user-circle', 'label' => 'Meu Perfil'],
                            ]
                            : ($ehColaborador
                            ? [
                                ['rota' => 'colaborador.index', 'padrao' => ['colaborador.index', 'colaborador.atividades.*'], 'icone' => 'ph-calendar-check', 'label' => 'Cronograma'],
                                ['rota' => 'colaborador.pendencias.index', 'padrao' => 'colaborador.pendencias.*', 'icone' => 'ph-warning-circle', 'label' => 'Pendências'],
                                ['rota' => 'colaborador.planos.index', 'padrao' => 'colaborador.planos.*', 'icone' => 'ph-clipboard-text', 'label' => 'Planos de ação', 'badge' => $planosPendentes],
                                ['rota' => 'perfil.edit', 'padrao' => 'perfil.*', 'icone' => 'ph-user-circle', 'label' => 'Meu Perfil'],
                            ]
                            : [
                                ['rota' => 'avaliacoes.index', 'padrao' => 'avaliacoes.*', 'icone' => 'ph-exam', 'label' => 'Avaliações'],
                                ['rota' => 'reitor.visao', 'padrao' => 'reitor.*', 'icone' => 'ph-student', 'label' => 'Painel da reitoria'],
                                ['rota' => 'colaborador.index', 'padrao' => ['colaborador.index', 'colaborador.atividades.*', 'colaborador.pendencias.*'], 'icone' => 'ph-calendar-check', 'label' => 'Cronograma de atividades'],
                                ['rota' => 'colaborador.planos.index', 'padrao' => 'colaborador.planos.*', 'icone' => 'ph-clipboard-text', 'label' => 'Planos de ação', 'badge' => $planosPendentes],
                                ['rota' => 'alunos.index', 'padrao' => 'alunos.*', 'icone' => 'ph-identification-card', 'label' => 'Alunos'],
                                ['rota' => 'categorias.index', 'padrao' => 'categorias.*', 'icone' => 'ph-tree-structure', 'label' => 'Categorias'],
                                ['rota' => 'lixeira.index', 'padrao' => 'lixeira.*', 'icone' => 'ph-trash', 'label' => 'Lixeira'],
                                ['rota' => 'usuarios.index', 'padrao' => 'usuarios.*', 'icone' => 'ph-users', 'label' => 'Usuários'],
                                ['rota' => 'sistema.configuracoes.index', 'padrao' => 'sistema.*', 'icone' => 'ph-gear', 'label' => 'Configurações'],
                                ['rota' => 'perfil.edit', 'padrao' => 'perfil.*', 'icone' => 'ph-user-circle', 'label' => 'Meu Perfil'],
                            ]));
                    @endphp
                    @if ($emVisaoDeCurso)
                        @php
                            $itensMenu = array_values(array_filter($itensMenu, fn ($i) => $i['rota'] !== 'notificacoes.index'));
                            array_unshift($itensMenu, ['rota' => 'reitor.curso.sair', 'padrao' => 'reitor.curso.sair', 'icone' => 'ph-arrow-u-up-left', 'label' => 'Voltar à reitoria']);
                        @endphp
                    @endif
                    @foreach ($itensMenu as $item)
                        @php($ativo = request()->routeIs(...(array) $item['padrao']))
                        <li>
                            <a href="{{ route($item['rota']) }}" @if ($ativo) aria-current="page" @endif
                               class="flex items-center px-3 py-2.5 rounded-lg transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary {{ $ativo ? 'bg-primary/10 text-primary font-medium' : 'hover:bg-slate-800 hover:text-white' }}">
                                <i class="ph {{ $item['icone'] }} text-xl mr-3 {{ $ativo ? 'text-primary' : '' }}" aria-hidden="true"></i> {{ $item['label'] }}
                                @if (! empty($item['badge']))
                                    <span class="ml-auto min-w-[1.4rem] rounded-full bg-primary px-1.5 py-0.5 text-center text-xs font-bold text-slate-900"><span aria-hidden="true">{{ $item['badge'] > 99 ? '99+' : $item['badge'] }}</span><span class="sr-only">{{ $item['badge'] }} {{ $ehCoordenador ? 'devolvido(s) para ajustes' : 'aguardando análise' }}</span></span>
                                @endif
                                @if ($item['rota'] === 'notificacoes.index')
                                    <span data-notificacoes-contagem class="ml-auto min-w-[1.4rem] rounded-full bg-primary px-1.5 py-0.5 text-center text-xs font-bold text-slate-900 {{ $naoLidas > 0 ? '' : 'hidden' }}"><span aria-hidden="true">{{ $naoLidas > 99 ? '99+' : $naoLidas }}</span><span class="sr-only">{{ $naoLidas }} não lida(s)</span></span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            <div class="p-4 border-t border-slate-800">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="w-full flex items-center px-3 py-2 text-red-400 hover:text-red-300 hover:bg-slate-800 rounded-lg transition-colors">
                        <i class="ph ph-sign-out text-xl mr-3"></i> Sair
                    </button>
                </form>
            </div>
        </aside>

        <div class="flex-1 flex flex-col h-screen overflow-hidden print:h-auto print:overflow-visible">
            <header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 md:hidden shrink-0 print:hidden">
                <div class="flex items-center">
                    <i class="ph-fill ph-exam text-primary text-2xl mr-2"></i>
                    <span class="font-bold text-slate-800">{{ $ehReitor ? 'Reitoria' : ($ehCoordenador ? 'Coordenação' : ($ehColaborador ? 'Colaboração' : 'Admin')) }}</span>
                </div>
                <div class="flex items-center gap-2">
                    @if ($sino)
                        <a href="{{ route('notificacoes.index') }}" class="relative rounded p-1 text-slate-600 hover:text-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary" aria-label="Notificações" title="Notificações">
                            <i class="ph ph-bell text-2xl" aria-hidden="true"></i>
                            <span data-notificacoes-ponto class="absolute right-0 top-0 h-2.5 w-2.5 rounded-full bg-red-500 ring-2 ring-white {{ $naoLidas > 0 ? '' : 'hidden' }}" aria-hidden="true"></span>
                        </a>
                    @endif
                    <div class="accessibility-container"></div>
                    <button type="button" id="botao-menu" onclick="toggleSidebar()" aria-label="Abrir o menu" aria-controls="sidebar" aria-expanded="false"
                            class="text-slate-600 hover:text-emerald-700 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                        <i class="ph ph-list text-2xl" aria-hidden="true"></i>
                    </button>
                </div>
            </header>

            <div class="bg-white border-b border-slate-200 px-6 h-14 shrink-0 hidden md:flex items-center justify-between print:hidden">
                <div class="flex items-center gap-3 min-w-0">
                    <button type="button" id="botao-menu-lateral" aria-controls="sidebar" aria-expanded="true" aria-label="Ocultar o menu lateral" title="Ocultar o menu lateral"
                            class="shrink-0 rounded-lg p-1.5 text-slate-600 hover:bg-slate-100 hover:text-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                        <i class="ph ph-sidebar-simple text-xl" aria-hidden="true"></i>
                    </button>
                    <span class="truncate text-sm text-slate-500">{{ $siteTitle }}</span>
                </div>
                <div class="flex items-center gap-4">
                    @if ($sino)
                        <a href="{{ route('notificacoes.index') }}" class="relative rounded p-1 text-slate-600 hover:text-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary" aria-label="Notificações" title="Notificações">
                            <i class="ph ph-bell text-2xl" aria-hidden="true"></i>
                            <span data-notificacoes-ponto class="absolute right-0 top-0 h-2.5 w-2.5 rounded-full bg-red-500 ring-2 ring-white {{ $naoLidas > 0 ? '' : 'hidden' }}" aria-hidden="true"></span>
                        </a>
                    @endif
                    <div class="accessibility-container"></div>
                    <div class="border-l border-slate-200 pl-4">
                        @include('partials.menu-usuario')
                    </div>
                </div>
            </div>

            {{-- `relative`: texto só para leitor de tela (`sr-only` = position absolute) sem ancestral posicionado ficava
                 ancorado na janela, fora do overflow-hidden do layout, e esticava a rolagem da PÁGINA (área vazia no fim). --}}
            <main id="conteudo-principal" tabindex="-1" class="relative flex-1 overflow-x-hidden overflow-y-auto bg-slate-50 p-4 sm:p-6 lg:p-8 focus:outline-none print:overflow-visible print:bg-white print:p-0">
                @if ($emVisaoDeCurso)
                    <div class="mb-6 flex flex-wrap items-center gap-3 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" role="note">
                        <i class="ph-bold ph-eye text-lg" aria-hidden="true"></i>
                        <span>Você está vendo <strong>como o coordenador</strong> de <strong>{{ implode(' · ', $usuarioLogado->cursos()) }}</strong> (somente leitura). Esta visão mostra dados de alunos do curso e fica registrada na auditoria.</span>
                        <a href="{{ route('reitor.curso.sair') }}" class="ml-auto inline-flex items-center gap-2 rounded-lg border border-sky-300 bg-white px-3 py-1.5 font-semibold text-sky-900 hover:bg-sky-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                            <i class="ph-bold ph-arrow-u-up-left" aria-hidden="true"></i> Voltar ao painel da reitoria
                        </a>
                    </div>
                @endif
                @include('partials.flash')
                @yield('content')
            </main>
        </div>
    </div>
@else
    <main id="conteudo-principal" tabindex="-1" class="max-w-5xl mx-auto px-6 py-8 focus:outline-none">
        @include('partials.flash')
        @yield('content')
    </main>
@endauth

<script>
    function toggleSidebar(forcarFechado) {
        var barra = document.getElementById('sidebar');
        var botao = document.getElementById('botao-menu');
        var aberta = forcarFechado === true ? false : !barra.classList.contains('open');
        barra.classList.toggle('open', aberta);
        document.getElementById('sidebar-overlay').classList.toggle('open', aberta);
        if (botao) {
            botao.setAttribute('aria-expanded', aberta ? 'true' : 'false');
            botao.setAttribute('aria-label', aberta ? 'Fechar o menu' : 'Abrir o menu');
        }
        if (aberta) {
            var primeiro = barra.querySelector('nav a');
            if (primeiro) primeiro.focus();
        } else if (botao && forcarFechado === true) {
            botao.focus();
        }
    }
    // Telas largas: recolher/exibir o menu lateral (guardado em localStorage; o <html> ganha .menu-oculto).
    (function () {
        var botao = document.getElementById('botao-menu-lateral');
        if (!botao) return;
        var raiz = document.documentElement;
        function refletir() {
            var oculto = raiz.classList.contains('menu-oculto');
            var texto = oculto ? 'Mostrar o menu lateral' : 'Ocultar o menu lateral';
            botao.setAttribute('aria-expanded', oculto ? 'false' : 'true');
            botao.setAttribute('aria-label', texto);
            botao.title = texto;
        }
        botao.addEventListener('click', function () {
            var oculto = raiz.classList.toggle('menu-oculto');
            try { localStorage.setItem('menuLateralOculto', oculto ? '1' : '0'); } catch (e) {}
            refletir();
            // gráficos e tabelas se ajustam à nova largura
            window.dispatchEvent(new Event('resize'));
        });
        refletir();
    })();
    // Esc fecha o menu no celular e devolve o foco ao botão.
    document.addEventListener('keydown', function (e) {
        var barra = document.getElementById('sidebar');
        if (e.key === 'Escape' && barra && barra.classList.contains('open')) toggleSidebar(true);
    });
</script>
@include('partials.accessibility-scripts')
@if (! empty($sino))
    @include('partials.notificacoes-scripts', ['adminId' => $usuarioLogado->id])
@endif
</body>
</html>
