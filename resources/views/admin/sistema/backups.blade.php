@extends('layouts.app')

@section('title', 'Backups — Avaliações')

@section('content')
<h1 class="text-2xl font-bold mb-6">Configurações do sistema</h1>

@include('admin.sistema._subnav')

<div class="flex items-center justify-between mb-6">
    <h2 class="text-lg font-semibold">Backups</h2>
    <form method="POST" action="{{ route('sistema.backups.store') }}">
        @csrf
        <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-5 py-2 text-sm">
            Gerar backup agora
        </button>
    </form>
</div>

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
@endsection
