@extends('layouts.app')

@section('title', "Painel BI — Prova #{$prova->codigo}")

@section('content')
<a href="{{ route('provas.show', $prova) }}" class="text-sm text-slate-500 hover:underline">&larr; Prova #{{ $prova->codigo }}</a>
<h1 class="text-2xl font-bold mt-2 mb-6">Painel BI</h1>

<form method="GET" action="{{ route('provas.bi', $prova) }}" class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 mb-6 flex gap-3">
    <select name="periodo" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <option value="">Todos os períodos</option>
        @foreach ($periodosDisponiveis as $p)
            <option value="{{ $p }}" {{ $periodo === $p ? 'selected' : '' }}>{{ $p === '' ? '(sem período)' : $p }}</option>
        @endforeach
    </select>
    <button type="submit" class="bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-lg px-4 py-2 text-sm">
        Filtrar
    </button>
</form>

@if (!empty($dados['semGabarito']))
    <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-xl p-6 text-sm">
        Esta prova ainda não tem gabarito cadastrado — importe ou cadastre as questões antes de ver o painel.
    </div>
@elseif (!empty($dados['semRespostas']))
    <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-xl p-6 text-sm">
        Nenhum resultado importado ainda para este filtro.
    </div>
@else
    <div class="grid lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
            <h2 class="font-semibold mb-4">Distribuição de acertos ({{ $dados['totalRespondentes'] }} respondente(s))</h2>
            <canvas id="grafico-histograma" height="220"></canvas>
        </div>

        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
            <h2 class="font-semibold mb-4">Desempenho médio por disciplina</h2>
            @if (empty($dados['radar']))
                <p class="text-sm text-slate-400">Nenhuma questão desta prova tem disciplina cadastrada na matriz (import de questões, coluna "Matriz (disciplina)").</p>
            @else
                <canvas id="grafico-radar" height="220"></canvas>
            @endif
        </div>
    </div>

    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 font-semibold">Top 5</div>
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-500 text-left">
                <tr>
                    <th class="px-4 py-3">RA</th>
                    <th class="px-4 py-3">CPF</th>
                    <th class="px-4 py-3">Período</th>
                    <th class="px-4 py-3">Acertos</th>
                    <th class="px-4 py-3">%</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($dados['top5'] as $r)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $r['ra'] ?: '—' }}</td>
                        <td class="px-4 py-3">{{ $r['cpf'] ?: '—' }}</td>
                        <td class="px-4 py-3">{{ $r['periodo'] !== '' ? $r['periodo'] : '—' }}</td>
                        <td class="px-4 py-3">{{ $r['acertos'] }}/{{ $r['total'] }}</td>
                        <td class="px-4 py-3 font-bold text-emerald-700">{{ $r['percentual'] }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <script>
        new Chart(document.getElementById('grafico-histograma'), {
            type: 'bar',
            data: {
                labels: [@foreach($dados['histograma'] as $i => $c) '{{ $i * 10 }}-{{ $i * 10 + 9 }}%', @endforeach],
                datasets: [{ label: 'Respondentes', data: {{ Js::from($dados['histograma']) }}, backgroundColor: '#10b981' }],
            },
            options: { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { display: false } } },
        });

        @if (!empty($dados['radar']))
        new Chart(document.getElementById('grafico-radar'), {
            type: 'radar',
            data: {
                labels: {{ Js::from(array_keys($dados['radar'])) }},
                datasets: [{ label: '% de acerto', data: {{ Js::from(array_values($dados['radar'])) }}, backgroundColor: 'rgba(16,185,129,0.2)', borderColor: '#10b981' }],
            },
            options: { scales: { r: { beginAtZero: true, max: 100 } } },
        });
        @endif
    </script>
@endif
@endsection
