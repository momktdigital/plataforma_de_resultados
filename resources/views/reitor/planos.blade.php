@extends('layouts.app')

@section('title', 'Planos de ação — Reitoria')

@section('content')
<div class="max-w-7xl">
    <div class="mb-5">
        <h1 class="text-2xl font-black flex items-center gap-2"><i class="ph-bold ph-clipboard-text text-primary" aria-hidden="true"></i> Planos de ação</h1>
        <p class="text-sm text-slate-600 mt-1 max-w-3xl">
            Como andam os planos que os coordenadores montaram a partir dos resultados das avaliações: quantos em cada situação, quanto das ações já foi
            executado e quanto tempo a análise leva. Só números por curso — o conteúdo dos planos é do coordenador e do colaborador.
        </p>
    </div>

    @if ($linhas === [])
        <div class="bg-slate-50 border border-slate-200 text-slate-600 rounded-xl p-6 text-sm">Nenhum plano de ação foi enviado ainda.</div>
    @else
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
            @foreach ([
                [$totais['planos'], 'Planos enviados', false],
                [$totais['em_execucao'], 'Em execução', false],
                [$totais['pct_acoes'] === null ? '—' : $totais['pct_acoes'].'%', 'Ações concluídas', false],
                [$totais['acoes_atrasadas'], 'Ações com prazo vencido', $totais['acoes_atrasadas'] > 0],
                [$totais['taxa_aprovacao'] === null ? '—' : $totais['taxa_aprovacao'].'%', 'Aprovados dos decididos', false],
            ] as [$numero, $rotulo, $alerta])
                <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4">
                    <div class="text-2xl font-black {{ $alerta ? 'text-red-700' : '' }}">{{ $numero }}</div>
                    <div class="text-xs font-medium uppercase tracking-wide text-slate-600">{{ $rotulo }}</div>
                </div>
            @endforeach
        </div>

        @include('plano._quadro', ['linhas' => $linhas, 'totais' => $totais])
    @endif
</div>
@endsection
