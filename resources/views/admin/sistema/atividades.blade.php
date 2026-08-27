@extends('layouts.app')

@section('title', 'Auditoria — Avaliações')

@section('content')
<h1 class="text-2xl font-bold mb-6">Configurações do sistema</h1>

@include('admin.sistema._subnav')

<h2 class="text-lg font-semibold mb-1">Trilha de auditoria</h2>
<p class="text-sm text-slate-500 mb-4">
    Quem fez o quê e quando — edição de gabarito, anulação de questão, exclusão/restauração de período,
    imports e mudanças em contas de administrador. Mais recentes primeiro.
</p>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-500 text-left">
            <tr>
                <th class="px-4 py-3 whitespace-nowrap">Quando</th>
                <th class="px-4 py-3">Admin</th>
                <th class="px-4 py-3">Ação</th>
                <th class="px-4 py-3">Alvo</th>
                <th class="px-4 py-3">Detalhes</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($atividades as $atividade)
                <tr>
                    <td class="px-4 py-3 text-slate-500 whitespace-nowrap">{{ $atividade->created_at->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-3 font-medium whitespace-nowrap">{{ $atividade->admin_username ?? '—' }}</td>
                    <td class="px-4 py-3 font-mono text-xs whitespace-nowrap">{{ $atividade->acao }}</td>
                    <td class="px-4 py-3 text-slate-500 whitespace-nowrap">
                        {{ $atividade->alvo_tipo ? "{$atividade->alvo_tipo} #{$atividade->alvo_id}" : '—' }}
                    </td>
                    <td class="px-4 py-3 text-slate-500">
                        @if ($atividade->detalhes)
                            <details>
                                <summary class="cursor-pointer text-emerald-700">Ver</summary>
                                <pre class="text-xs mt-1 whitespace-pre-wrap">{{ json_encode($atividade->detalhes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            </details>
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-8 text-center text-slate-400">Nenhuma atividade registrada ainda.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $atividades->links() }}
</div>
@endsection
