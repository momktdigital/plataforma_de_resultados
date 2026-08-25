@extends('layouts.app')

@section('title', "Avaliação #{$avaliacao->codigo} — Avaliações")

@section('content')
<div class="mb-6 flex items-start justify-between gap-4">
    <div>
        <a href="{{ route('avaliacoes.index') }}" class="text-sm text-slate-500 hover:underline">&larr; Todas as avaliações</a>
        <h1 class="text-2xl font-bold mt-2">Avaliação #{{ $avaliacao->codigo }} @if($avaliacao->nome) — {{ $avaliacao->nome }} @endif</h1>
        @if ($avaliacao->tipo)
            <p class="text-slate-500">{{ $avaliacao->tipo }}</p>
        @endif
        @if ($avaliacao->link_comentado)
            <a href="{{ $avaliacao->link_comentado }}" target="_blank" rel="noopener" class="text-sm text-emerald-700 hover:underline">
                Gabarito comentado &nearr;
            </a>
        @endif
    </div>
    <form method="POST" action="{{ route('avaliacoes.destroy', $avaliacao) }}"
          onsubmit="return confirm('ATENÇÃO: excluir esta avaliação remove também TODAS as questões, respostas e métricas associadas. Continuar?');">
        @csrf
        @method('DELETE')
        <button type="submit" class="text-sm text-red-600 border border-red-200 hover:bg-red-50 rounded-lg px-3 py-1.5">
            Excluir avaliação
        </button>
    </form>
</div>

@if (session('importIgnoradas') && count(session('importIgnoradas')))
    <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 text-sm">
        <p class="font-semibold mb-2">{{ count(session('importIgnoradas')) }} linha(s) ignorada(s):</p>
        <ul class="list-disc pl-5 space-y-0.5 max-h-48 overflow-y-auto">
            @foreach (session('importIgnoradas') as $ignorada)
                <li>Linha {{ $ignorada['linha'] }}: {{ $ignorada['motivo'] }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="grid sm:grid-cols-2 gap-6 mb-6">
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
        <h2 class="font-semibold mb-1">Questões e gabarito</h2>
        <p class="text-sm text-slate-500 mb-4">{{ $avaliacao->questoes_count }} questão(ões) cadastrada(s).</p>
        <a href="{{ route('avaliacoes.questoes.import', $avaliacao) }}"
           class="inline-block bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-4 py-2 text-sm">
            Importar questões
        </a>
    </div>

    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
        <h2 class="font-semibold mb-1">Resultados</h2>
        <p class="text-sm text-slate-500 mb-4">
            {{ $avaliacao->resultados_count }} resposta(s) registrada(s).
            @if ($avaliacao->metricas_count)
                &middot; {{ $avaliacao->metricas_count }} métrica(s) agregada(s) (ex.: notas finais).
            @endif
        </p>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('avaliacoes.resultados.import', $avaliacao) }}"
               class="inline-block bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-4 py-2 text-sm">
                Importar resultados
            </a>
            <a href="{{ route('avaliacoes.respondentes.index', $avaliacao) }}"
               class="inline-block border border-slate-300 text-slate-700 hover:bg-slate-50 font-semibold rounded-lg px-4 py-2 text-sm">
                Ver por aluno
            </a>
            <a href="{{ route('avaliacoes.bi', $avaliacao) }}"
               class="inline-block border border-slate-300 text-slate-700 hover:bg-slate-50 font-semibold rounded-lg px-4 py-2 text-sm">
                Painel BI
            </a>
        </div>
    </div>
</div>

{{-- Editar configurações básicas --}}
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
    <h2 class="font-semibold mb-4">Editar configurações</h2>
    <form method="POST" action="{{ route('avaliacoes.update', $avaliacao) }}" class="flex flex-wrap gap-3 items-end">
        @csrf
        @method('PUT')
        <div class="flex-1 min-w-[200px]">
            <label class="block text-sm font-medium mb-1" for="nome">Nome</label>
            <input id="nome" name="nome" type="text" value="{{ old('nome', $avaliacao->nome) }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div class="flex-1 min-w-[160px]">
            <label class="block text-sm font-medium mb-1" for="tipo">Tipo</label>
            <input id="tipo" name="tipo" type="text" value="{{ old('tipo', $avaliacao->tipo) }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div class="flex-1 min-w-[200px]">
            <label class="block text-sm font-medium mb-1" for="link_comentado">Link do gabarito comentado</label>
            <input id="link_comentado" name="link_comentado" type="url" value="{{ old('link_comentado', $avaliacao->link_comentado) }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div class="flex-1 min-w-[160px]">
            <label class="block text-sm font-medium mb-1" for="categoria_id">Categoria</label>
            <select id="categoria_id" name="categoria_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="">— Sem categoria —</option>
                @foreach ($opcoesCategoria as $opcao)
                    <option value="{{ $opcao['id'] }}" {{ (int) old('categoria_id', $avaliacao->categoria_id) === $opcao['id'] ? 'selected' : '' }}>
                        {{ $opcao['label'] }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="min-w-[140px]">
            <label class="block text-sm font-medium mb-1" for="data_avaliacao">Data da avaliação</label>
            <input id="data_avaliacao" name="data_avaliacao" type="text" placeholder="DD/MM/AAAA"
                   value="{{ old('data_avaliacao', $avaliacao->data_avaliacao?->format('d/m/Y')) }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <button type="submit" class="bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-lg px-5 py-2 text-sm">
            Salvar
        </button>
    </form>
</div>

<script src="https://unpkg.com/imask"></script>
<script>
    IMask(document.getElementById('data_avaliacao'), {
        mask: Date,
        pattern: 'd/m/Y',
        blocks: {
            d: {mask: IMask.MaskedRange, from: 1, to: 31, maxLength: 2},
            m: {mask: IMask.MaskedRange, from: 1, to: 12, maxLength: 2},
            Y: {mask: IMask.MaskedRange, from: 1900, to: 2999},
        },
        format: function (date) {
            var day = String(date.getDate()).padStart(2, '0');
            var month = String(date.getMonth() + 1).padStart(2, '0');
            return [day, month, date.getFullYear()].join('/');
        },
        parse: function (str) {
            var partes = str.split('/');
            return new Date(partes[2], partes[1] - 1, partes[0]);
        },
    });
</script>

{{-- Editor manual de gabarito --}}
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
    <h2 class="font-semibold mb-1">Editor manual de gabarito</h2>
    <p class="text-sm text-slate-500 mb-4">Adicione ou corrija uma questão sem precisar reimportar a planilha inteira.</p>

    <form method="POST" action="{{ route('avaliacoes.questoes.store', $avaliacao) }}" class="flex flex-wrap gap-3 items-end mb-6">
        @csrf
        <div>
            <label class="block text-sm font-medium mb-1" for="numero">Número</label>
            <input id="numero" name="numero" type="number" min="1" required
                   class="w-28 rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1" for="gabarito">Gabarito</label>
            <input id="gabarito" name="gabarito" type="text" maxlength="10" required
                   class="w-28 rounded-lg border border-slate-300 px-3 py-2 text-sm uppercase">
        </div>
        <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-5 py-2 text-sm">
            Salvar questão
        </button>
    </form>

    @if ($questoes->isEmpty())
        <p class="text-sm text-slate-400">Nenhuma questão cadastrada ainda.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-left">
                    <tr>
                        <th class="px-3 py-2">Nº</th>
                        <th class="px-3 py-2">Gabarito</th>
                        <th class="px-3 py-2"></th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($questoes as $questao)
                        <tr class="{{ $questao->trashed() ? 'opacity-50' : '' }}">
                            <td class="px-3 py-2 font-mono">{{ $questao->numero }}</td>
                            <td class="px-3 py-2 font-bold">{{ $questao->gabarito ?: '—' }}</td>
                            <td class="px-3 py-2 text-slate-400">{{ $questao->trashed() ? 'Excluída' : '' }}</td>
                            <td class="px-3 py-2 text-right">
                                @if ($questao->trashed())
                                    <form method="POST" action="{{ route('avaliacoes.questoes.restore', [$avaliacao, $questao->id]) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-blue-600 hover:underline">Restaurar</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('avaliacoes.questoes.destroy', [$avaliacao, $questao]) }}" class="inline"
                                          onsubmit="return confirm('Excluir a questão {{ $questao->numero }}?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-500 hover:text-red-700">Excluir</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- Questões críticas --}}
@if (! empty($estatisticasErro))
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
        <h2 class="font-semibold mb-1">Questões críticas</h2>
        <p class="text-sm text-slate-500 mb-4">Maiores índices de erro entre os respondentes desta avaliação.</p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-left">
                    <tr>
                        <th class="px-3 py-2">Questão</th>
                        <th class="px-3 py-2">Acertos</th>
                        <th class="px-3 py-2">Erros</th>
                        <th class="px-3 py-2">Em branco</th>
                        <th class="px-3 py-2">Taxa de erro</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($estatisticasErro as $stat)
                        <tr>
                            <td class="px-3 py-2 font-mono">Q{{ $stat['numero'] }}</td>
                            <td class="px-3 py-2">{{ $stat['acertos'] }}</td>
                            <td class="px-3 py-2">{{ $stat['erros'] }}</td>
                            <td class="px-3 py-2">{{ $stat['em_branco'] }}</td>
                            <td class="px-3 py-2 font-bold text-red-600">{{ $stat['taxa_erro'] }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
