@extends('layouts.app')

@section('title', 'Backups — Avaliações')

@section('content')
<h1 class="text-2xl font-bold mb-6">Configurações do sistema</h1>

@include('admin.sistema._subnav')

<div class="flex items-center justify-between mb-6">
    <h2 class="text-lg font-semibold">Backups</h2>
    @if (in_array($backupStatus, ['pendente', 'processando'], true))
        <span class="inline-flex items-center gap-2 text-sm text-slate-500 font-medium">
            <i class="ph ph-spinner animate-spin"></i> Gerando em segundo plano&hellip;
        </span>
    @else
        <form method="POST" action="{{ route('sistema.backups.store') }}">
            @csrf
            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-5 py-2 text-sm">
                Gerar backup agora
            </button>
        </form>
    @endif
</div>

@if (in_array($backupStatus, ['pendente', 'processando'], true))
    <div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 text-blue-900 px-4 py-3 text-sm">
        <p class="font-semibold mb-1">Backup em andamento.</p>
        <p>
            Essa tela atualiza sozinha a cada alguns segundos. Um dump completo do banco pode levar de alguns
            segundos a alguns minutos, dependendo do volume de dados.
        </p>
        @if ($backupIniciadoEm)
            <p class="mt-2 text-blue-700" id="backup-aviso-demora" style="display:none">
                Isso está demorando mais que o esperado — confirme se o worker da fila
                (<code class="bg-blue-100 px-1 rounded">php artisan queue:work</code>) está rodando no servidor.
                Sem ele, o backup fica pendente indefinidamente.
            </p>
        @endif
    </div>
@elseif ($backupStatus === 'erro')
    <div class="mb-6 rounded-lg border border-red-200 bg-red-50 text-red-900 px-4 py-3 text-sm">
        <p class="font-semibold mb-1">O último backup falhou.</p>
        <p>{{ $backupErro }}</p>
    </div>
@endif

<div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 text-sm">
    O arquivo gerado contém o banco de dados completo e o `.env` da aplicação — inclui credenciais sensíveis. Guarde-o com cuidado.
</div>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-500 text-left">
            <tr>
                <th class="px-4 py-3">Arquivo</th>
                <th class="px-4 py-3">Tamanho</th>
                <th class="px-4 py-3">Gerado em</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($backups as $backup)
                <tr>
                    <td class="px-4 py-3 font-mono">{{ $backup['nome'] }}</td>
                    <td class="px-4 py-3">{{ number_format($backup['tamanho'] / 1048576, 1) }} MB</td>
                    <td class="px-4 py-3 text-slate-500">{{ \Illuminate\Support\Carbon::createFromTimestamp($backup['data'])->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('sistema.backups.download', $backup['nome']) }}" class="text-emerald-700 font-semibold hover:underline">
                            Baixar
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="px-4 py-8 text-center text-slate-400">Nenhum backup gerado ainda.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if (in_array($backupStatus, ['pendente', 'processando'], true))
    <script>
        setTimeout(function () { window.location.reload(); }, 5000);

        @if ($backupIniciadoEm)
            (function () {
                var iniciadoEm = new Date('{{ $backupIniciadoEm }}').getTime();
                if (Date.now() - iniciadoEm > 2 * 60 * 1000) {
                    document.getElementById('backup-aviso-demora').style.display = 'block';
                }
            })();
        @endif
    </script>
@endif
@endsection
