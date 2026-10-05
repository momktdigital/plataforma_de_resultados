@extends('layouts.auth')

@section('title', 'Entrar')

@section('content')
<div class="text-center mb-8">
    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-slate-800 border border-slate-700 mb-4 shadow-lg">
        <i class="ph-fill ph-lock-key text-3xl text-primary"></i>
    </div>
    <h1 class="text-2xl font-bold text-white tracking-tight">Área Restrita</h1>
    <p class="text-slate-400 mt-2 text-sm">Painel de Administração de Avaliações</p>
</div>

<div class="bg-slate-800 rounded-2xl shadow-2xl border border-slate-700 overflow-hidden">
    <div class="p-8">
        @if (session('status'))
            <div class="bg-emerald-900/30 border border-emerald-800 text-emerald-300 p-4 mb-6 rounded-lg text-sm flex items-start gap-2">
                <i class="ph-fill ph-check-circle text-xl mt-0.5"></i>
                <span>{{ session('status') }}</span>
            </div>
        @endif

        @if ($errors->any())
            <div id="erros-do-formulario" data-resumo-erros role="alert" class="bg-red-900/30 border border-red-800 text-red-300 p-4 mb-6 rounded-lg text-sm flex items-start gap-2">
                <i class="ph-fill ph-warning-circle text-xl mt-0.5" aria-hidden="true"></i>
                <ul class="space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Administrador entra com usuário e senha; coordenador e reitor, com um código enviado ao e-mail — ou, se tiverem senha, também com ela. --}}
        @php $abaCoordenador = in_array($modo, ['codigo', 'coordenador'], true); @endphp
        <div class="grid grid-cols-2 gap-1 p-1 mb-6 bg-slate-900 rounded-xl border border-slate-700" role="tablist" aria-label="Tipo de acesso">
            <a href="{{ route('login') }}" role="tab" aria-selected="{{ $modo === 'senha' ? 'true' : 'false' }}"
               class="text-center text-sm font-semibold py-2 rounded-lg transition-colors {{ $modo === 'senha' ? 'bg-primary text-white' : 'text-slate-400 hover:text-slate-200' }}">
                Administrador
            </a>
            <a href="{{ route('login', ['modo' => 'codigo']) }}" role="tab" aria-selected="{{ $abaCoordenador ? 'true' : 'false' }}"
               class="text-center text-sm font-semibold py-2 rounded-lg transition-colors {{ $abaCoordenador ? 'bg-primary text-white' : 'text-slate-400 hover:text-slate-200' }}">
                Coordenação / Reitoria
            </a>
        </div>

        @if ($modo === 'codigo')
            <form method="POST" action="{{ route('login.codigo.solicitar') }}" class="space-y-6">
                @csrf
                <div>
                    <label for="identificador" class="block text-sm font-medium text-slate-300 mb-1 ml-1">Usuário ou e-mail</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="ph-fill ph-envelope-simple text-slate-500 text-lg"></i>
                        </div>
                        <input type="text" id="identificador" name="identificador" required autofocus value="{{ old('identificador') }}" autocomplete="username"
                               class="block w-full pl-10 pr-3 py-3 bg-slate-900 border border-slate-700 rounded-xl text-slate-200 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all"
                               placeholder="seu usuário ou e-mail">
                    </div>
                    <p class="text-xs text-slate-400 mt-2 ml-1">Enviaremos um código de acesso para o e-mail cadastrado. Não é preciso senha.</p>
                </div>

                <button type="submit"
                        class="w-full flex justify-center items-center py-3.5 px-4 border border-transparent rounded-xl shadow-md text-sm font-bold text-white bg-primary hover:bg-emerald-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-slate-800 focus:ring-primary transition-all">
                    <i class="ph-bold ph-paper-plane-tilt mr-2 text-lg"></i> Enviar código
                </button>
            </form>

            <div class="flex items-center gap-3 my-5" aria-hidden="true">
                <span class="flex-1 h-px bg-slate-700"></span>
                <span class="text-xs uppercase tracking-wider text-slate-400">ou</span>
                <span class="flex-1 h-px bg-slate-700"></span>
            </div>

            <a href="{{ route('login', ['modo' => 'coordenador']) }}"
               class="w-full flex justify-center items-center py-3 px-4 border border-slate-600 rounded-xl text-sm font-semibold text-slate-200 bg-slate-900 hover:bg-slate-700 hover:border-slate-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-slate-800 focus:ring-primary transition-all">
                <i class="ph-bold ph-key mr-2 text-lg"></i> Entrar com senha
            </a>
            <p class="text-xs text-slate-400 mt-2 text-center">Para quem tem senha cadastrada. Você vai direto para o seu painel.</p>
        @else
        <form method="POST" action="{{ route('login') }}" class="space-y-6">
            @csrf
            <div>
                <label for="username" class="block text-sm font-medium text-slate-300 mb-1 ml-1">Usuário</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="ph-fill ph-user text-slate-500 text-lg"></i>
                    </div>
                    <input type="text" id="username" name="username" required autofocus value="{{ old('username') }}" autocomplete="username"
                           class="block w-full pl-10 pr-3 py-3 bg-slate-900 border border-slate-700 rounded-xl text-slate-200 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all"
                           placeholder="{{ $modo === 'coordenador' ? 'seu usuário' : 'admin' }}">
                </div>
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-slate-300 mb-1 ml-1">Senha</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="ph-fill ph-lock text-slate-500 text-lg"></i>
                    </div>
                    <input type="password" id="password" name="password" required autocomplete="current-password"
                           class="block w-full pl-10 pr-3 py-3 bg-slate-900 border border-slate-700 rounded-xl text-slate-200 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all"
                           placeholder="••••••••">
                </div>
            </div>

            <button type="submit"
                    class="w-full flex justify-center items-center py-3.5 px-4 border border-transparent rounded-xl shadow-md text-sm font-bold text-white bg-primary hover:bg-emerald-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-slate-800 focus:ring-primary transition-all">
                <i class="ph-bold ph-sign-in mr-2 text-lg"></i> {{ $modo === 'coordenador' ? 'Entrar no meu painel' : 'Entrar no Sistema' }}
            </button>
        </form>

        @if ($modo === 'coordenador')
            <div class="flex items-center gap-3 my-5" aria-hidden="true">
                <span class="flex-1 h-px bg-slate-700"></span>
                <span class="text-xs uppercase tracking-wider text-slate-400">ou</span>
                <span class="flex-1 h-px bg-slate-700"></span>
            </div>

            <a href="{{ route('login', ['modo' => 'codigo']) }}"
               class="w-full flex justify-center items-center py-3 px-4 border border-slate-600 rounded-xl text-sm font-semibold text-slate-200 bg-slate-900 hover:bg-slate-700 hover:border-slate-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-slate-800 focus:ring-primary transition-all">
                <i class="ph-bold ph-envelope-simple mr-2 text-lg"></i> Receber código por e-mail
            </a>
            <p class="text-xs text-slate-400 mt-2 text-center">Não tem senha? Entre com um código enviado ao seu e-mail.</p>
        @endif

        <div class="text-center mt-4">
            <a href="{{ route('senha.esqueci') }}" class="text-sm text-slate-400 hover:text-slate-300 transition-colors">
                Esqueci minha senha
            </a>
        </div>
        @endif
    </div>

    <div class="bg-slate-900/50 px-8 py-4 border-t border-slate-700 text-center">
        <a href="{{ route('portal.consulta') }}" class="text-sm text-slate-400 hover:text-slate-300 transition-colors flex items-center justify-center">
            <i class="ph-bold ph-arrow-left mr-1"></i> Voltar para Consulta de Alunos
        </a>
    </div>
</div>
@endsection
