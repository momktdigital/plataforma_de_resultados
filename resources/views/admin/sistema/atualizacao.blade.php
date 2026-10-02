@extends('layouts.app')

@section('title', 'Atualizações — Avaliações')

@section('content')
<h1 class="text-2xl font-bold mb-6">Configurações do sistema</h1>

@include('admin.sistema._subnav')

<h2 class="text-lg font-semibold mb-4">Atualizações</h2>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 max-w-2xl">
    <p class="text-sm text-slate-600 mb-1">Versão instalada</p>
    <p class="text-lg font-mono font-semibold mb-2">{{ $versaoAtual }}</p>
    <p class="text-xs text-slate-600 mb-6">
        Repositório de atualizações: <span class="font-mono">{{ $repositorio }}</span> (definido no servidor, no <code>.env</code>).
    </p>

    @if ($repositorioLegadoIgnorado)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 mb-6 text-sm text-amber-900" role="status">
            Havia um repositório salvo pelo painel em versões antigas (<span class="font-mono">{{ $repositorioLegadoIgnorado }}</span>).
            Ele é <strong>ignorado</strong>: só vale o do <code>.env</code> (<code>ATUALIZACAO_REPOSITORIO</code>).
        </div>
    @endif

    @if ($pendente)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 mb-6 text-sm text-amber-900">
            <p class="font-semibold mb-2">Pacote baixado — confira antes de aplicar</p>
            <p class="mb-1">Versão: <span class="font-mono font-semibold">{{ $pendente['versao'] }}</span></p>
            <p class="mb-3">
                SHA-256:
                <span class="block font-mono text-xs break-all bg-amber-100 rounded px-2 py-1 mt-1">{{ $pendente['sha256'] }}</span>
            </p>
            <p class="mb-4">
                Confira este hash contra uma fonte confiável (ex.: a página da Release no GitHub) antes de continuar.
                Ao confirmar, o servidor passa a executar o código desta versão (migrations incluídas).
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
                        <p class="text-red-700 text-xs mt-1" role="alert">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="senha_atual">Sua senha (confirma que é você mesmo)</label>
                    <input id="senha_atual" name="senha_atual" type="password" required autocomplete="current-password"
                           @error('senha_atual') aria-invalid="true" aria-describedby="erro-senha-atual" @enderror
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @error('senha_atual')
                        <p id="erro-senha-atual" class="text-red-700 text-xs mt-1" role="alert">{{ $message }}</p>
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

        @if ($assinatura)
            @if ($assinatura['verificada'])
                <p class="text-sm text-emerald-800 mb-4"><i class="ph-fill ph-seal-check" aria-hidden="true"></i>
                    Commit da versão com <strong>assinatura verificada</strong> pelo GitHub{{ $assinatura['assinante'] ? ' (assinante: '.$assinatura['assinante'].')' : '' }}.
                </p>
            @else
                <p class="text-sm {{ $exigirAssinatura ? 'text-red-700' : 'text-amber-800' }} mb-4" role="status">
                    <i class="ph-fill ph-warning" aria-hidden="true"></i>
                    O commit desta versão <strong>não tem assinatura verificada</strong> pelo GitHub (motivo: {{ $assinatura['motivo'] }}).
                    @if ($exigirAssinatura)
                        Este servidor exige assinatura: a atualização será bloqueada.
                    @else
                        Confira o hash com atenção antes de aplicar.
                    @endif
                </p>
            @endif
        @endif

        <form method="POST" action="{{ route('sistema.atualizacao.verificar') }}">
            @csrf
            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-5 py-2 text-sm">
                Baixar pacote e conferir
            </button>
        </form>
    @else
        <p class="text-sm text-slate-600">Nenhuma atualização disponível — você já está na versão mais recente (ou o GitHub não pôde ser consultado agora).</p>
    @endif
</div>
@endsection
