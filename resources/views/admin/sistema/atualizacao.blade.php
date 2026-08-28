@extends('layouts.app')

@section('title', 'Atualizações — Avaliações')

@section('content')
<h1 class="text-2xl font-bold mb-6">Configurações do sistema</h1>

@include('admin.sistema._subnav')

<h2 class="text-lg font-semibold mb-4">Atualizações</h2>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 max-w-2xl">
    <p class="text-sm text-slate-500 mb-1">Versão instalada</p>
    <p class="text-lg font-mono font-semibold mb-6">{{ $versaoAtual }}</p>

    @if ($pendente)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 mb-6 text-sm text-amber-900">
            <p class="font-semibold mb-2">Pacote baixado — confira antes de aplicar</p>
            <p class="mb-1">Versão: <span class="font-mono font-semibold">{{ $pendente['versao'] }}</span></p>
            <p class="mb-3">
                SHA-256:
                <span class="block font-mono text-xs break-all bg-amber-100 rounded px-2 py-1 mt-1">{{ $pendente['sha256'] }}</span>
            </p>
            <p class="mb-4">
                Confira este hash contra uma fonte confiável (ex.: a página da Release no GitHub) antes de continuar —
                as releases deste repositório não publicam assinatura automática pra isso.
            </p>

            <form method="POST" action="{{ route('sistema.atualizacao.store') }}"
                  onsubmit="return confirm('A aplicação ficará em manutenção durante a atualização. Um backup será gerado automaticamente antes. Continuar?');"
                  class="space-y-3">
                @csrf
                <div>
                    <label class="block text-sm font-medium mb-1" for="versao_confirmada">
                        Digite a versão acima ({{ $pendente['versao'] }}) para confirmar
                    </label>
                    <input id="versao_confirmada" name="versao_confirmada" type="text" required
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono">
                    @error('versao_confirmada')
                        <p class="text-red-600 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-5 py-2 text-sm">
                    Confirmar e aplicar
                </button>
            </form>
        </div>
    @elseif ($disponivel)
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 mb-6">
            <p class="font-semibold text-emerald-900 mb-1">Nova versão disponível: {{ $disponivel['versao'] }}</p>
            @if ($disponivel['notas'])
                <div class="text-sm text-emerald-800 whitespace-pre-line mt-2">{{ $disponivel['notas'] }}</div>
            @endif
        </div>

        <form method="POST" action="{{ route('sistema.atualizacao.verificar') }}">
            @csrf
            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-5 py-2 text-sm">
                Baixar pacote e conferir
            </button>
        </form>
    @else
        <p class="text-sm text-slate-500">Nenhuma atualização disponível — você já está na versão mais recente (ou o GitHub não pôde ser consultado agora).</p>
    @endif
</div>
@endsection
