@extends('layouts.app')

@section('title', 'Lixeira — Avaliações')

@section('content')
<h1 class="text-2xl font-bold mb-6">Lixeira</h1>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden mb-8">
    <div class="px-6 py-4 border-b border-slate-100 font-semibold">Provas excluídas</div>
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-500 text-left">
            <tr>
                <th class="px-4 py-3">Prova</th>
                <th class="px-4 py-3">Excluída em</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($provas as $prova)
                <tr>
                    <td class="px-4 py-3">#{{ $prova->codigo }} @if($prova->nome) — {{ $prova->nome }} @endif</td>
                    <td class="px-4 py-3 text-slate-500">{{ $prova->deleted_at->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <form method="POST" action="{{ route('lixeira.provas.restore', $prova->codigo) }}" class="inline">
                            @csrf
                            <button type="submit" class="text-blue-600 hover:underline mr-3">Restaurar</button>
                        </form>
                        <form method="POST" action="{{ route('lixeira.provas.forceDelete', $prova->codigo) }}" class="inline"
                              onsubmit="return confirm('Excluir permanentemente a prova #{{ $prova->codigo }} e tudo que ela contém? Esta ação não pode ser desfeita.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-red-500 hover:text-red-700">Excluir definitivamente</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" class="px-4 py-8 text-center text-slate-400">Nenhuma prova na lixeira.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 font-semibold">Questões excluídas</div>
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-500 text-left">
            <tr>
                <th class="px-4 py-3">Prova</th>
                <th class="px-4 py-3">Questão</th>
                <th class="px-4 py-3">Excluída em</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($questoes as $questao)
                <tr>
                    <td class="px-4 py-3">#{{ $questao->prova->codigo }} @if($questao->prova->nome) — {{ $questao->prova->nome }} @endif</td>
                    <td class="px-4 py-3 font-mono">Q{{ $questao->numero }}</td>
                    <td class="px-4 py-3 text-slate-500">{{ $questao->deleted_at->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <form method="POST" action="{{ route('lixeira.questoes.restore', $questao->id) }}" class="inline">
                            @csrf
                            <button type="submit" class="text-blue-600 hover:underline mr-3">Restaurar</button>
                        </form>
                        <form method="POST" action="{{ route('lixeira.questoes.forceDelete', $questao->id) }}" class="inline"
                              onsubmit="return confirm('Excluir permanentemente esta questão?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-red-500 hover:text-red-700">Excluir definitivamente</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="px-4 py-8 text-center text-slate-400">Nenhuma questão na lixeira.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
