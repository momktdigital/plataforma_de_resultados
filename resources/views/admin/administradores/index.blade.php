@extends('layouts.app')

@section('title', 'Administradores — Avaliações')

@section('content')
<h1 class="text-2xl font-bold mb-6">Administradores</h1>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-500 text-left">
                <tr>
                    <th class="px-4 py-3 w-16">ID</th>
                    <th class="px-4 py-3">Usuário</th>
                    <th class="px-4 py-3">E-mail</th>
                    <th class="px-4 py-3">Criado em</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($admins as $admin)
                    <tr>
                        <td class="px-4 py-3 font-mono text-slate-500">#{{ $admin->id }}</td>
                        <td class="px-4 py-3 font-medium">
                            {{ $admin->username }}
                            @if ($admin->id === auth('admin')->id())
                                <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-800">Você</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ $admin->email ?: '—' }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $admin->created_at->format('d/m/Y') }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="{{ route('administradores.edit', $admin) }}" class="text-emerald-700 hover:underline mr-3">Editar</a>
                            @if ($admin->id !== auth('admin')->id())
                                <form method="POST" action="{{ route('administradores.destroy', $admin) }}" class="inline"
                                      onsubmit="return confirm('Tem certeza que deseja excluir o administrador {{ $admin->username }}?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-500 hover:text-red-700">Excluir</button>
                                </form>
                            @else
                                <span class="text-slate-300" title="Você não pode se excluir">Excluir</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
        <h2 class="font-semibold mb-4">Novo administrador</h2>
        <form method="POST" action="{{ route('administradores.store') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium mb-1" for="username">Nome de usuário</label>
                <input id="username" name="username" type="text" required value="{{ old('username') }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" for="email">E-mail (opcional)</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <p class="text-xs text-slate-500 mt-1">Necessário pra esta conta poder usar "esqueci minha senha".</p>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" for="password">Senha</label>
                <input id="password" name="password" type="password" required minlength="4"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <p class="text-xs text-slate-500 mt-1">Mínimo de 4 caracteres.</p>
            </div>
            <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-4 py-2 text-sm">
                Criar conta
            </button>
        </form>
    </div>
</div>
@endsection
