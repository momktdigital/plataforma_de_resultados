<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Avaliações')</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen">
@auth('admin')
    <nav class="bg-white border-b border-slate-200">
        <div class="max-w-5xl mx-auto px-6 py-4 flex items-center justify-between">
            <a href="{{ route('provas.index') }}" class="font-bold text-lg">Avaliações</a>
            <div class="flex items-center gap-4 text-sm">
                <a href="{{ route('alunos.index') }}" class="text-slate-500 hover:text-slate-800">Alunos</a>
                <a href="{{ route('administradores.index') }}" class="text-slate-500 hover:text-slate-800">Administradores</a>
                <a href="{{ route('sistema.configuracoes.index') }}" class="text-slate-500 hover:text-slate-800">Configurações</a>
                <a href="{{ route('perfil.edit') }}" class="text-slate-500 hover:text-slate-800">{{ auth('admin')->user()->username }}</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-red-600 hover:underline">Sair</button>
                </form>
            </div>
        </div>
    </nav>
@endauth

<main class="max-w-5xl mx-auto px-6 py-8">
    @if (session('status'))
        <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 text-sm">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 text-red-800 px-4 py-3 text-sm">
            <ul class="list-disc pl-5 space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>
</body>
</html>
