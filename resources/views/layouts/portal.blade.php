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
        .fade-in { animation: fadeIn .4s ease-in forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="bg-secondary text-dark min-h-screen flex flex-col">

<div class="bg-white shadow-sm sticky top-0 z-40">
    <div class="max-w-5xl mx-auto px-4 h-16 flex items-center justify-between">
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
        <div class="accessibility-container"></div>
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
