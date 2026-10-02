{{-- Selo da situação do aluno. Variável: $situacao (chave de CoordenadorAlunosService::SITUACOES). --}}
@php
    $estilo = match ($situacao) {
        'atencao' => ['bg-amber-50 text-amber-700 border-amber-200', 'ph-warning-circle'],
        'ausente' => ['bg-red-50 text-red-700 border-red-200', 'ph-user-minus'],
        'destaque' => ['bg-emerald-50 text-emerald-700 border-emerald-200', 'ph-star'],
        'sem_resultado' => ['bg-slate-50 text-slate-500 border-slate-200', 'ph-minus-circle'],
        default => ['bg-slate-100 text-slate-600 border-slate-200', 'ph-check-circle'],
    };
@endphp
<span class="inline-flex items-center gap-1 whitespace-nowrap rounded-full border px-2.5 py-0.5 text-xs font-semibold {{ $estilo[0] }}">
    <i class="ph-bold {{ $estilo[1] }}" aria-hidden="true"></i> {{ \App\Services\CoordenadorAlunosService::SITUACOES[$situacao] ?? $situacao }}
</span>
