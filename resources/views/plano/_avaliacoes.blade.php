{{--
    As avaliações a que o plano se refere, cada uma com o link para o Dashboard dela (quando quem lê pode abri-lo).
    Variáveis:
      $listaAvaliacoes  [['codigo', 'nome', 'data', 'periodoLetivo']]
      $avaliacoesAcessiveis  códigos que o usuário pode abrir (o Dashboard mostra alunos: coordenador do curso e administrador);
                             quem não pode (colaborador) vê só o nome, a data e o código
      $novaAba          (opcional) abre os links em outra aba (no formulário, para não perder o que foi digitado)
--}}
@php $novaAba = $novaAba ?? false; @endphp
@if (! empty($listaAvaliacoes))
    <div>
        <h3 class="text-sm font-bold mb-2 flex items-center gap-2"><i class="ph-bold ph-exam text-primary" aria-hidden="true"></i> {{ count($listaAvaliacoes) === 1 ? 'Avaliação do plano' : 'Avaliações do recorte' }}</h3>
        <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200 text-sm">
            @foreach ($listaAvaliacoes as $a)
                <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                    <span class="min-w-0">
                        <span class="font-medium text-slate-800">{{ $a['nome'] }}</span>
                        <span class="font-mono text-xs text-slate-500">#{{ $a['codigo'] }}</span>
                        @if (! empty($a['data']))<span class="text-xs text-slate-500">· {{ \Illuminate\Support\Carbon::parse($a['data'])->format('d/m/Y') }}</span>@endif
                    </span>
                    @if (in_array($a['codigo'], $avaliacoesAcessiveis ?? [], true))
                        <a href="{{ route('avaliacoes.bi', $a['codigo']) }}" @if ($novaAba) target="_blank" rel="noopener" @endif
                           class="inline-flex items-center gap-1 font-semibold text-emerald-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">
                            Abrir a avaliação<i class="ph-bold ph-arrow-square-out" aria-hidden="true"></i>@if ($novaAba)<span class="sr-only"> (abre em outra aba)</span>@endif
                        </a>
                    @endif
                </li>
            @endforeach
        </ul>
        @if (empty(array_intersect(array_column($listaAvaliacoes, 'codigo'), $avaliacoesAcessiveis ?? [])))
            <p class="mt-1 text-xs text-slate-500">O Dashboard da avaliação mostra dados de alunos e só abre para o coordenador do curso e o administrador.</p>
        @endif
    </div>
@endif
