@extends('layouts.app')

@section('title', "Visualizações — Avaliação #{$avaliacao->codigo}")

@section('content')
<a href="{{ route('avaliacoes.show', $avaliacao) }}" class="text-sm text-slate-500 hover:underline">&larr; Avaliação #{{ $avaliacao->codigo }}</a>
<h1 class="text-2xl font-bold mt-2 mb-1">Configurar visualizações</h1>
<p class="text-sm text-slate-500 mb-6">
    Escolha quais gráficos e painéis aparecem no boletim do aluno (Portal) e no painel administrativo (BI) desta
    avaliação. Um visual só pode ser habilitado quando a avaliação já tem os dados necessários para calculá-lo —
    quando faltar algo, o motivo aparece ao lado da opção desabilitada.
</p>

@if (session('status'))
    <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm">
        {{ session('status') }}
    </div>
@endif

<form method="POST" action="{{ route('avaliacoes.visualizacoes.update', $avaliacao) }}">
    @csrf
    @method('PUT')

    @foreach ([\App\Services\Visualizacoes\VisualCatalog::GRUPO_ADMIN, \App\Services\Visualizacoes\VisualCatalog::GRUPO_ALUNO] as $grupo)
        @php $itensDoGrupo = collect($estado)->filter(fn ($item) => $item['grupo'] === $grupo); @endphp
        @continue($itensDoGrupo->isEmpty())

        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-slate-100 font-semibold">{{ $grupo }}</div>
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-left">
                    <tr>
                        <th class="px-4 py-3">Visual</th>
                        <th class="px-4 py-3 w-28 text-center">Exibir p/ aluno</th>
                        <th class="px-4 py-3 w-28 text-center">Exibir p/ admin</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($itensDoGrupo as $chave => $item)
                        <tr>
                            <td class="px-4 py-3">
                                <div class="font-medium text-slate-800">{{ $item['label'] }}</div>
                                @if (! $item['disponivel'])
                                    <div class="text-xs text-amber-700 mt-0.5 flex items-center gap-1">
                                        <i class="ph ph-warning-circle"></i> {{ $item['pendencia'] }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center">
                                @if ($item['aluno'])
                                    <input type="checkbox" name="visuais[{{ $chave }}][aluno]" value="1"
                                           {{ $item['visivelAluno'] ? 'checked' : '' }}
                                           {{ $item['disponivel'] ? '' : 'disabled' }}
                                           title="{{ $item['disponivel'] ? '' : $item['pendencia'] }}"
                                           class="w-4 h-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500 disabled:opacity-30 disabled:cursor-not-allowed">
                                @else
                                    <span class="text-slate-300">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center">
                                @if ($item['admin'])
                                    <input type="checkbox" name="visuais[{{ $chave }}][admin]" value="1"
                                           {{ $item['visivelAdmin'] ? 'checked' : '' }}
                                           {{ $item['disponivel'] ? '' : 'disabled' }}
                                           title="{{ $item['disponivel'] ? '' : $item['pendencia'] }}"
                                           class="w-4 h-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500 disabled:opacity-30 disabled:cursor-not-allowed">
                                @else
                                    <span class="text-slate-300">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach

    <button type="submit" class="bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-lg px-5 py-2 text-sm">
        Salvar visualizações
    </button>
</form>
@endsection
