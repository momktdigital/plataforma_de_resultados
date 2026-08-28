@php
    $siteTitle = \App\Models\Configuracao::valor('site_title', 'Resultados DI');
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Entrar') — {{ $siteTitle }}</title>
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
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen flex flex-col items-center justify-center p-4 relative">

    <div class="absolute top-4 right-4 bg-white rounded-lg shadow px-2 py-1 z-50">
        <div class="accessibility-container"></div>
    </div>

    <div class="w-full max-w-md">
        @yield('content')
    </div>

    @include('partials.accessibility-scripts')
</body>
</html>
