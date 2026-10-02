{{--
    Selo de variação entre dois períodos (seta + sinal, nunca só a cor). Variáveis:
      $delta     ?float — atual menos referência
      $unidade   'pp' (pontos percentuais — padrão) ou '' (contagem)
      $inverter  true quando AUMENTAR é ruim (ex.: % abaixo de 60%, alunos em atenção)
--}}
@php
    $unidade = $unidade ?? 'pp';
    $inverter = $inverter ?? false;
@endphp
@if ($delta === null)
    <span class="text-slate-500 text-xs">sem comparação</span>
@elseif (abs($delta) < 0.05)
    <span class="inline-flex items-center gap-1 text-xs font-semibold text-slate-600"><i class="ph-bold ph-equals" aria-hidden="true"></i> estável</span>
@else
    @php
        $bom = ($delta > 0) !== $inverter;
        $texto = ($delta > 0 ? '+' : '−').number_format(abs($delta), $unidade === '' ? 0 : 1, ',', '.').($unidade !== '' ? ' '.$unidade : '');
    @endphp
    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-bold {{ $bom ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
        <i class="ph-bold {{ $delta > 0 ? 'ph-arrow-up' : 'ph-arrow-down' }}" aria-hidden="true"></i> {{ $texto }}
        <span class="sr-only">({{ $bom ? 'melhor' : 'pior' }})</span>
    </span>
@endif
