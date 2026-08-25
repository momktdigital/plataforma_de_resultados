@php
    $siteTitle = \App\Models\Configuracao::valor('site_title', 'Resultados DI');
    $siteLogo = \App\Models\Configuracao::valor('site_logo', '');
    $siteLogoDark = \App\Models\Configuracao::valor('site_logo_dark', '');
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Avaliações') — {{ $siteTitle }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
    @include('partials.accessibility-head')
    <style>
        body { font-family: 'Inter', sans-serif; }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); transition: transform .3s ease-in-out; z-index: 50; position: fixed; height: 100vh; }
            .sidebar.open { transform: translateX(0); }
            .overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 40; }
            .overlay.open { display: block; }
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 font-sans">
@auth('admin')
    <div class="h-screen flex overflow-hidden">
        <div id="sidebar-overlay" class="overlay" onclick="toggleSidebar()"></div>

        <aside id="sidebar" class="sidebar w-64 bg-slate-900 text-slate-300 flex flex-col shrink-0">
            <div class="h-16 flex items-center px-6 border-b border-slate-800 bg-slate-950">
                @if ($siteLogoDark || $siteLogo)
                    <img src="{{ asset('uploads/logos/'.basename($siteLogoDark ?: $siteLogo)) }}" alt="{{ $siteTitle }}" class="h-8 object-contain">
                @else
                    <i class="ph-fill ph-exam text-primary text-2xl mr-2"></i>
                    <span class="text-lg font-bold text-white tracking-wide">{{ $siteTitle }}</span>
                @endif
            </div>

            <nav class="flex-1 overflow-y-auto py-4">
                <ul class="space-y-1 px-3">
                    @php
                        $itensMenu = [
                            ['rota' => 'avaliacoes.index', 'padrao' => 'avaliacoes.*', 'icone' => 'ph-exam', 'label' => 'Avaliações'],
                            ['rota' => 'alunos.index', 'padrao' => 'alunos.*', 'icone' => 'ph-identification-card', 'label' => 'Alunos'],
                            ['rota' => 'categorias.index', 'padrao' => 'categorias.*', 'icone' => 'ph-tree-structure', 'label' => 'Categorias'],
                            ['rota' => 'lixeira.index', 'padrao' => 'lixeira.*', 'icone' => 'ph-trash', 'label' => 'Lixeira'],
                            ['rota' => 'administradores.index', 'padrao' => 'administradores.*', 'icone' => 'ph-users', 'label' => 'Administradores'],
                            ['rota' => 'sistema.configuracoes.index', 'padrao' => 'sistema.*', 'icone' => 'ph-gear', 'label' => 'Configurações'],
                            ['rota' => 'perfil.edit', 'padrao' => 'perfil.*', 'icone' => 'ph-user-circle', 'label' => 'Meu Perfil'],
                        ];
                    @endphp
                    @foreach ($itensMenu as $item)
                        @php($ativo = request()->routeIs($item['padrao']))
                        <li>
                            <a href="{{ route($item['rota']) }}"
                               class="flex items-center px-3 py-2.5 rounded-lg transition-colors {{ $ativo ? 'bg-primary/10 text-primary font-medium' : 'hover:bg-slate-800 hover:text-white' }}">
                                <i class="ph {{ $item['icone'] }} text-xl mr-3 {{ $ativo ? 'text-primary' : '' }}"></i> {{ $item['label'] }}
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

        <div class="flex-1 flex flex-col h-screen overflow-hidden">
            <header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 md:hidden shrink-0">
                <div class="flex items-center">
                    <i class="ph-fill ph-exam text-primary text-2xl mr-2"></i>
                    <span class="font-bold text-slate-800">Admin</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="accessibility-container"></div>
                    <button onclick="toggleSidebar()" class="text-slate-500 hover:text-primary focus:outline-none">
                        <i class="ph ph-list text-2xl"></i>
                    </button>
                </div>
            </header>

            <div class="bg-white border-b border-slate-200 px-6 h-14 shrink-0 hidden md:flex items-center justify-between">
                <span class="text-sm text-slate-400">{{ $siteTitle }}</span>
                <div class="flex items-center gap-4">
                    <div class="accessibility-container"></div>
                    <span class="text-sm text-slate-500 border-l border-slate-200 pl-4">{{ auth('admin')->user()->username }}</span>
                </div>
            </div>

            <main class="flex-1 overflow-x-hidden overflow-y-auto bg-slate-50 p-4 sm:p-6 lg:p-8">
                @include('partials.flash')
                @yield('content')
            </main>
        </div>
    </div>
@else
    <main class="max-w-5xl mx-auto px-6 py-8">
        @include('partials.flash')
        @yield('content')
    </main>
@endauth

<script>
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('sidebar-overlay').classList.toggle('open');
    }
</script>
@include('partials.accessibility-scripts')
</body>
</html>
