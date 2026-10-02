{{-- Linhas da lista nominal do Dashboard. $linhas = página de RelatorioAdminService::rankingCompleto(); $inicio = nº de linhas que vieram antes dela (a posição é contínua entre páginas). --}}
@foreach ($linhas as $i => $r)
    @php
        $nomeAluno = $r['aluno_nome'] ?: '—';
        $inicial = mb_strtoupper(mb_substr($r['aluno_nome'] ?: ($r['ra'] ?: '?'), 0, 1));
    @endphp
    <tr class="{{ $r['ausente'] ? 'bg-slate-50/60 text-slate-500' : '' }}">
        <td class="px-4 py-3 text-slate-500">{{ $r['ausente'] ? '—' : $inicio + $i + 1 }}</td>
        <td class="px-4 py-3">
            <div class="flex items-center gap-3 min-w-[14rem]">
                @if ($r['foto'])
                    <img src="{{ $r['foto'] }}" alt="Foto de {{ $nomeAluno }}" loading="lazy" width="36" height="36"
                         class="w-9 h-9 rounded-full object-cover bg-slate-100 shrink-0"
                         onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                    <span style="display:none" class="w-9 h-9 rounded-full bg-slate-200 text-slate-600 font-bold items-center justify-center shrink-0">{{ $inicial }}</span>
                @else
                    <span class="w-9 h-9 rounded-full bg-slate-200 text-slate-600 font-bold flex items-center justify-center shrink-0">{{ $inicial }}</span>
                @endif
                <span class="font-medium {{ $r['ausente'] ? '' : 'text-slate-800' }}">{{ $nomeAluno }}</span>
                @if ($r['ausente'])
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-slate-200 text-slate-600">Ausente</span>
                @endif
            </div>
        </td>
        <td class="px-4 py-3">{{ $r['ra'] ?: '—' }}</td>
        <td class="px-4 py-3">{{ $r['curso'] ?: '—' }}</td>
        <td class="px-4 py-3 whitespace-nowrap">{{ $r['periodo_curso'] ?: '—' }}</td>
        <td class="px-4 py-3">{{ $r['turma'] ?: '—' }}</td>
        <td class="px-4 py-3">
            @if ($r['percentual'] === null)
                <span class="text-slate-500">—</span>
            @else
                <div class="flex items-center gap-2 min-w-[9rem]">
                    <span class="tabular-nums">{{ $r['acertos'] }}/{{ $r['total'] }}</span>
                    {{-- Abaixo de 60%: amarelo (mesma regra de cor do painel do aluno). --}}
                    @php $adequado = $r['percentual'] >= \App\Services\CoordenadorDashboardService::LIMIAR_ADEQUADO; @endphp
                    <div class="relative w-20">
                        <div class="absolute inset-y-0 left-0 {{ $adequado ? 'bg-emerald-100' : 'bg-amber-100' }} rounded" style="width: {{ $r['percentual'] }}%"></div>
                        <span class="relative font-bold {{ $adequado ? 'text-emerald-800' : 'text-amber-800' }} px-1">{{ number_format($r['percentual'], 1, ',', '.') }}%</span>
                    </div>
                </div>
            @endif
        </td>
    </tr>
@endforeach
