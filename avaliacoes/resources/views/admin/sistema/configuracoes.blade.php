@extends('layouts.app')

@section('title', 'Configurações — Avaliações')

@section('content')
<h1 class="text-2xl font-bold mb-6">Configurações do sistema</h1>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 max-w-xl">
    <form method="POST" action="{{ route('sistema.configuracoes.update') }}" class="space-y-5">
        @csrf

        <div>
            <label class="block text-sm font-medium mb-1" for="atualizacao_repositorio">
                Repositório do GitHub para atualizações
            </label>
            <input id="atualizacao_repositorio" name="atualizacao_repositorio" type="text" required
                   value="{{ old('atualizacao_repositorio', $atualizacaoRepositorio) }}"
                   placeholder="owner/repositorio"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono">
            <p class="text-xs text-slate-500 mt-1">Repositório público consultado por "Atualizações" — formato <code>owner/repositorio</code>.</p>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1" for="backup_manter_ultimos">
                Quantos backups manter
            </label>
            <input id="backup_manter_ultimos" name="backup_manter_ultimos" type="number" min="1" max="50" required
                   value="{{ old('backup_manter_ultimos', $backupManterUltimos) }}"
                   class="w-32 rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <p class="text-xs text-slate-500 mt-1">Backups mais antigos que isso são apagados automaticamente a cada novo backup gerado.</p>
        </div>

        <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-5 py-2 text-sm">
            Salvar
        </button>
    </form>
</div>
@endsection
