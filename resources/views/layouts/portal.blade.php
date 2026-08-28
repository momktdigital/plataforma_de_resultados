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
    <title>@yield('title', $siteTitle)</title>
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
    @include('partials.accessibility-head')
    <style>
        body { font-family: 'Inter', sans-serif; }
        .fade-in { animation: fadeIn .4s ease-in forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="bg-secondary text-dark min-h-screen flex flex-col">

<div class="bg-white shadow-sm sticky top-0 z-40">
    <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between">
        <a href="{{ route('portal.consulta') }}" class="flex items-center gap-2">
            @if ($siteLogo || $siteLogoDark)
                @if ($siteLogo)
                    <img src="{{ asset('uploads/logos/'.basename($siteLogo)) }}" alt="{{ $siteTitle }}" class="h-8 object-contain logo-light">
                @endif
                @if ($siteLogoDark)
                    <img src="{{ asset('uploads/logos/'.basename($siteLogoDark)) }}" alt="{{ $siteTitle }}"
                         class="h-8 object-contain logo-dark" @if($siteLogo) style="display:none" @endif>
                @endif
            @else
                <i class="ph-fill ph-exam text-primary text-3xl"></i>
                <span class="font-bold text-xl tracking-tight text-slate-800">{{ $siteTitle }}</span>
            @endif
        </a>
        <div class="flex items-center gap-2 sm:gap-3">
            <div class="accessibility-container"></div>

            @isset($aluno)
                <div class="relative">
                    <button type="button" onclick="portalToggleContaMenu()" id="portal-conta-botao"
                            aria-haspopup="true" aria-expanded="false" aria-controls="portal-conta-menu"
                            class="flex items-center gap-1.5 rounded-full hover:bg-slate-100 p-1 pr-2 transition-colors"
                            title="{{ $aluno->nome ?: $aluno->ra }}">
                        @if ($aluno->fotoUrl())
                            <img src="{{ $aluno->fotoUrl(60) }}" alt="Foto de {{ $aluno->nome ?: $aluno->ra }}"
                                 class="w-9 h-9 rounded-full object-cover border border-slate-200"
                                 onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                            <span style="display:none" class="w-9 h-9 rounded-full bg-primary/10 text-primary items-center justify-center text-sm font-bold">
                                {{ mb_strtoupper(mb_substr($aluno->nome ?: $aluno->ra, 0, 1)) }}
                            </span>
                        @else
                            <span class="w-9 h-9 rounded-full bg-primary/10 text-primary flex items-center justify-center text-sm font-bold">
                                {{ mb_strtoupper(mb_substr($aluno->nome ?: $aluno->ra, 0, 1)) }}
                            </span>
                        @endif
                        <i class="ph-bold ph-caret-down text-slate-400 text-xs hidden sm:inline"></i>
                    </button>

                    <div id="portal-conta-menu" hidden
                         class="absolute right-0 top-full mt-2 w-56 bg-white border border-slate-200 shadow-lg rounded-xl overflow-hidden z-50">
                        <div class="px-4 py-3 border-b border-slate-100">
                            <p class="text-sm font-bold text-slate-800 truncate">{{ $aluno->nome ?: $aluno->ra }}</p>
                            <p class="text-xs text-slate-500">RA {{ $aluno->ra }}</p>
                        </div>
                        <a href="{{ route('portal.sair') }}" class="flex items-center gap-2 px-4 py-3 text-sm text-red-600 hover:bg-red-50 transition-colors">
                            <i class="ph-bold ph-sign-out"></i> Sair
                        </a>
                    </div>
                </div>

                <script>
                function portalToggleContaMenu() {
                    const menu = document.getElementById('portal-conta-menu');
                    const botao = document.getElementById('portal-conta-botao');
                    menu.hidden = ! menu.hidden;
                    botao.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
                }
                function portalFecharContaMenu() {
                    const menu = document.getElementById('portal-conta-menu');
                    if (menu.hidden) return;
                    menu.hidden = true;
                    document.getElementById('portal-conta-botao').setAttribute('aria-expanded', 'false');
                }
                document.addEventListener('click', function (evento) {
                    const botao = document.getElementById('portal-conta-botao');
                    const menu = document.getElementById('portal-conta-menu');
                    if (botao && menu && !menu.hidden && !botao.contains(evento.target) && !menu.contains(evento.target)) {
                        portalFecharContaMenu();
                    }
                });
                document.addEventListener('keydown', function (evento) {
                    if (evento.key === 'Escape') portalFecharContaMenu();
                });
                </script>
            @endisset
        </div>
    </div>
</div>

<main class="@yield('container-class', 'max-w-2xl') mx-auto px-6 py-12 flex-1 w-full">
    @include('partials.flash')
    @yield('content')
</main>

<footer class="py-6 text-center">
    <a href="{{ route('login') }}" title="Área administrativa" aria-label="Área administrativa"
       class="inline-flex items-center gap-1 text-xs text-slate-300 hover:text-slate-500 transition-colors">
        <i class="ph ph-lock-key"></i> Área administrativa
    </a>
</footer>

@include('partials.accessibility-scripts')
</body>
</html>
