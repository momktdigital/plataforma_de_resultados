@extends('layouts.app')

@section('title', 'Usuários — Avaliações')

@section('content')
<h1 class="text-2xl font-bold mb-4">Usuários</h1>

@php
    $coordenadores = $aba === 'coordenadores';
    $reitores = $aba === 'reitores';
    $colaboradores = $aba === 'colaboradores';
    $entraPorCodigo = $coordenadores || $reitores || $colaboradores;
    $papelForm = $coordenadores ? 'coordenador' : ($reitores ? 'reitor' : ($colaboradores ? 'colaborador' : 'administrador'));
@endphp

<div class="flex gap-1 border-b border-slate-200 mb-6" role="tablist">
    <a href="{{ route('usuarios.index', ['aba' => 'administradores']) }}" role="tab" aria-selected="{{ $entraPorCodigo ? 'false' : 'true' }}"
       class="px-4 py-2 text-sm font-medium -mb-px border-b-2 {{ $entraPorCodigo ? 'border-transparent text-slate-500 hover:text-slate-700' : 'border-emerald-600 text-emerald-700' }}">
        Administradores <span class="ml-1 text-xs text-slate-500">{{ $totalAdministradores }}</span>
    </a>
    <a href="{{ route('usuarios.index', ['aba' => 'coordenadores']) }}" role="tab" aria-selected="{{ $coordenadores ? 'true' : 'false' }}"
       class="px-4 py-2 text-sm font-medium -mb-px border-b-2 {{ $coordenadores ? 'border-emerald-600 text-emerald-700' : 'border-transparent text-slate-500 hover:text-slate-700' }}">
        Coordenadores <span class="ml-1 text-xs text-slate-500">{{ $totalCoordenadores }}</span>
    </a>
    <a href="{{ route('usuarios.index', ['aba' => 'reitores']) }}" role="tab" aria-selected="{{ $reitores ? 'true' : 'false' }}"
       class="px-4 py-2 text-sm font-medium -mb-px border-b-2 {{ $reitores ? 'border-emerald-600 text-emerald-700' : 'border-transparent text-slate-500 hover:text-slate-700' }}">
        Reitoria <span class="ml-1 text-xs text-slate-500">{{ $totalReitores }}</span>
    </a>
    <a href="{{ route('usuarios.index', ['aba' => 'colaboradores']) }}" role="tab" aria-selected="{{ $colaboradores ? 'true' : 'false' }}"
       class="px-4 py-2 text-sm font-medium -mb-px border-b-2 {{ $colaboradores ? 'border-emerald-600 text-emerald-700' : 'border-transparent text-slate-500 hover:text-slate-700' }}">
        Colaboradores <span class="ml-1 text-xs text-slate-500">{{ $totalColaboradores }}</span>
    </a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 bg-white border border-slate-200 rounded-xl shadow-sm overflow-x-auto">
        @if ($coordenadores)
            <p class="px-4 pt-4 text-sm text-slate-500">
                Coordenadores têm acesso limitado: veem o painel do(s) curso(s) vinculado(s) e as avaliações que tenham alunos desses cursos
                (ou às quais receberam acesso excepcional na configuração da avaliação).
            </p>
        @elseif ($reitores)
            <p class="px-4 pt-4 text-sm text-slate-500">
                A reitoria acompanha os indicadores institucionais de <strong>todos os cursos</strong> (participação, proficiência, desempenho,
                trajetória e evolução entre semestres). O painel é <strong>agregado, sem dados nominais</strong>. Para analisar um curso a fundo, o reitor
                pode abrir a <strong>visão do coordenador</strong> daquele curso (somente leitura, com os alunos do curso); cada abertura fica registrada na auditoria.
            </p>
        @elseif ($colaboradores)
            <p class="px-4 pt-4 text-sm text-slate-500">
                Colaboradores montam o <strong>cronograma de atividades</strong> (a checklist de auditoria): cadastram as atividades, indicam a quais cursos se aplicam
                (o que monta o calendário de cada coordenador) e registram as pendências. Também analisam os <strong>planos de ação</strong> enviados pelos coordenadores (aprovam, pedem ajustes ou recusam, com justificativa) e acompanham a execução. Também consultam as avaliações e o Dashboard de cada uma, só leitura (já têm acesso às planilhas importadas), mas não gerenciam nada.
            </p>
        @endif
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-500 text-left">
                <tr>
                    <th class="px-4 py-3 w-16">ID</th>
                    <th class="px-4 py-3">Usuário</th>
                    <th class="px-4 py-3">E-mail</th>
                    @if ($coordenadores)
                        <th class="px-4 py-3">Cursos</th>
                    @endif
                    <th class="px-4 py-3">Criado em</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($usuarios as $usuario)
                    <tr>
                        <td class="px-4 py-3 font-mono text-slate-500">#{{ $usuario->id }}</td>
                        <td class="px-4 py-3 font-medium">
                            {{ $usuario->username }}
                            @if ($usuario->id === auth('admin')->id())
                                <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-800">Você</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ $usuario->email ?: '—' }}</td>
                        @if ($coordenadores)
                            <td class="px-4 py-3">
                                @php $cursosDoUsuario = $cursosPorCoordenador->get($usuario->id, collect($usuario->curso ? [$usuario->curso] : [])); @endphp
                                @forelse ($cursosDoUsuario as $curso)
                                    <span class="inline-block px-2 py-0.5 mr-1 mb-1 rounded bg-slate-100 text-slate-700 text-xs">{{ $curso }}</span>
                                @empty
                                    <span class="text-amber-700 text-xs">Nenhum curso vinculado</span>
                                @endforelse
                            </td>
                        @endif
                        <td class="px-4 py-3 text-slate-500">{{ $usuario->created_at?->format('d/m/Y') ?? '—' }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="{{ route('usuarios.edit', $usuario) }}" class="text-emerald-700 hover:underline mr-3">Editar</a>
                            @if ($usuario->id !== auth('admin')->id())
                                <form method="POST" action="{{ route('usuarios.destroy', $usuario) }}" class="inline"
                                      onsubmit="return confirm(@js('Tem certeza que deseja excluir o usuário '.$usuario->username.'?'));">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-600 hover:text-red-700">Excluir</button>
                                </form>
                            @else
                                <span class="text-slate-300" title="Você não pode se excluir">Excluir</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-slate-500">
                            {{ $coordenadores ? 'Nenhum coordenador cadastrado ainda.' : ($reitores ? 'Nenhum usuário da reitoria cadastrado ainda.' : ($colaboradores ? 'Nenhum colaborador cadastrado ainda.' : 'Nenhum administrador cadastrado.')) }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3 border-t border-slate-100">
            {{ $usuarios->links() }}
        </div>
    </div>

    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
        <h2 class="font-semibold mb-4">{{ $coordenadores ? 'Novo coordenador' : ($reitores ? 'Novo usuário da reitoria' : ($colaboradores ? 'Novo colaborador' : 'Novo administrador')) }}</h2>
        <form method="POST" action="{{ route('usuarios.store') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="papel" value="{{ $papelForm }}">
            <div>
                <label class="block text-sm font-medium mb-1" for="username">Nome de usuário</label>
                <input id="username" name="username" type="text" required value="{{ old('username') }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" for="email">{{ $entraPorCodigo ? 'E-mail' : 'E-mail (opcional)' }}</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" @required($entraPorCodigo)
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <p class="text-xs text-slate-500 mt-1">
                    @if ($entraPorCodigo)
                        É para este e-mail que enviamos o código de acesso.
                    @else
                        Necessário pra esta conta poder usar "esqueci minha senha".
                    @endif
                </p>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" for="password">{{ $entraPorCodigo ? 'Senha (opcional)' : 'Senha' }}</label>
                <input id="password" name="password" type="password" @required(! $entraPorCodigo) minlength="10" autocomplete="new-password"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <p class="text-xs text-slate-500 mt-1">
                    @if ($entraPorCodigo)
                        Deixe em branco: a pessoa entra só com o código enviado ao e-mail. Se preencher, mínimo de 10 caracteres.
                    @else
                        Mínimo de 10 caracteres. Administrador sempre entra com senha.
                    @endif
                </p>
            </div>
            @if ($coordenadores)
                <div>
                    <p class="block text-sm font-medium mb-1">Cursos que o coordenador pode ver</p>
                    @include('partials.seletor-cursos', ['nome' => 'cursos', 'opcoes' => $opcoesCurso, 'selecionados' => [], 'id' => 'novo-coordenador-cursos'])
                    @error('cursos')
                        <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>
            @endif
            <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-4 py-2 text-sm">
                Criar conta
            </button>
        </form>
    </div>
</div>
@endsection
