@extends('layouts.app')

@section('title', "Editar {$admin->username} — Usuários")

@section('content')
@php $coordenador = $admin->ehCoordenador(); @endphp
<a href="{{ route('usuarios.index', ['aba' => $coordenador ? 'coordenadores' : 'administradores']) }}" class="text-sm text-slate-500 hover:underline">&larr; Usuários</a>
<h1 class="text-2xl font-bold mt-2 mb-6">Editar {{ $coordenador ? 'coordenador' : 'administrador' }}</h1>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 max-w-xl">
    <form method="POST" action="{{ route('usuarios.update', $admin) }}" class="space-y-4">
        @csrf
        @method('PUT')
        <div>
            <label class="block text-sm font-medium mb-1" for="username">Nome de usuário</label>
            <input id="username" name="username" type="text" required value="{{ old('username', $admin->username) }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1" for="email">{{ $coordenador ? 'E-mail' : 'E-mail (opcional)' }}</label>
            <input id="email" name="email" type="email" value="{{ old('email', $admin->email) }}" @required($coordenador)
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <p class="text-xs text-slate-500 mt-1">
                {{ $coordenador ? 'É para este e-mail que enviamos o código de acesso do coordenador.' : 'Necessário pra esta conta poder usar "esqueci minha senha".' }}
            </p>
        </div>
        <div>
            <label class="block text-sm font-medium mb-1" for="password">Nova senha (opcional)</label>
            <input id="password" name="password" type="password" minlength="10"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <p class="text-xs text-slate-500 mt-1">
                Deixe em branco para manter a senha atual{{ $coordenador ? ' (coordenador sem senha entra só com o código por e-mail)' : '' }}. Se preencher, mínimo de 10 caracteres.
            </p>
        </div>
        @if ($coordenador)
            <div>
                <p class="block text-sm font-medium mb-1">Cursos que o coordenador pode ver</p>
                @include('partials.seletor-cursos', ['nome' => 'cursos', 'opcoes' => $opcoesCurso, 'selecionados' => $cursosSelecionados, 'id' => 'edit-coordenador-cursos'])
                @error('cursos')
                    <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                @enderror
            </div>
        @endif
        <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-4 py-2 text-sm">
            Salvar alterações
        </button>
    </form>
</div>
@endsection
