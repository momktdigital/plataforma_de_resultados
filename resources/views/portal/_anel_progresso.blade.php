{{--
    Anel de progresso circular reaproveitável — usado tanto no card resumido
    da lista de resultados (pequeno) quanto no cabeçalho do detalhe da
    avaliação (grande). SVG puro (sem canvas/Chart.js) de propósito: fica
    leve o bastante pra repetir em cada card da lista, e renderiza de forma
    confiável dentro do PDF exportado via html2canvas.

    Parâmetros:
    - $percentual (float|null)
    - $tamanho (int, px) — padrão 64
    - $espessura (int, px) — padrão 7
    - $tamanhoTexto (string, classe Tailwind) — padrão 'text-sm'
--}}
@php
    $tamanho ??= 64;
    $espessura ??= 7;
    $tamanhoTexto ??= 'text-sm';
    $raio = ($tamanho - $espessura) / 2;
    $circunferencia = 2 * M_PI * $raio;
    $fracao = $percentual !== null ? max(0, min(100, $percentual)) / 100 : 0;
    $offset = $circunferencia * (1 - $fracao);
@endphp
<div class="relative shrink-0" style="width: {{ $tamanho }}px; height: {{ $tamanho }}px;">
    <svg width="{{ $tamanho }}" height="{{ $tamanho }}" viewBox="0 0 {{ $tamanho }} {{ $tamanho }}" class="-rotate-90">
        <circle cx="{{ $tamanho / 2 }}" cy="{{ $tamanho / 2 }}" r="{{ $raio }}"
                fill="none" stroke="#e2e8f0" stroke-width="{{ $espessura }}"/>
        @if ($percentual !== null)
            <circle cx="{{ $tamanho / 2 }}" cy="{{ $tamanho / 2 }}" r="{{ $raio }}"
                    fill="none" stroke="#00b48d" stroke-width="{{ $espessura }}" stroke-linecap="round"
                    stroke-dasharray="{{ $circunferencia }}" stroke-dashoffset="{{ $offset }}"/>
        @endif
    </svg>
    <div class="absolute inset-0 flex items-center justify-center">
        <span class="{{ $tamanhoTexto }} font-black {{ $percentual !== null ? 'text-primary' : 'text-slate-300' }}">
            {{ $percentual !== null ? round($percentual).'%' : '—' }}
        </span>
    </div>
</div>
