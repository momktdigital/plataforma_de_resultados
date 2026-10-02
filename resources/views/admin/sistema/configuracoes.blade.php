@extends('layouts.app')

@section('title', 'Configurações — Avaliações')

@section('content')
<h1 class="text-2xl font-bold mb-6">Configurações do sistema</h1>

@include('admin.sistema._subnav')

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 max-w-xl">
    <form method="POST" action="{{ route('sistema.configuracoes.update') }}" class="space-y-5">
        @csrf

        <div>
            <p class="block text-sm font-medium mb-1">Repositório do GitHub para atualizações</p>
            <p class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-mono text-slate-700">{{ $atualizacaoRepositorio }}</p>
            <p class="text-xs text-slate-600 mt-1">
                Definido no servidor (<code>ATUALIZACAO_REPOSITORIO</code> no arquivo <code>.env</code>) e não pode ser alterado por aqui:
                o atualizador baixa e executa o código desse repositório.
            </p>
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
