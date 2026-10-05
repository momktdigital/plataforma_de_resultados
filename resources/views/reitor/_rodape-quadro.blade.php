{{--
    Botões "Sobre este quadro" (o que o quadro mede e como ler) e "Ver leitura" (o que os números de agora dizem, com
    o tom) abaixo de cada visual. O comportamento (abrir/fechar) vem de _base.blade.php.
    Variáveis: $id (único na página), $sobre (texto), $leitura (?array{texto, tom}), $botoes (opcional: HTML extra).
--}}
@php
    $tons = [
        'bom' => ['rotulo' => 'Bom sinal', 'classe' => 'bg-emerald-50 text-emerald-700'],
        'atencao' => ['rotulo' => 'Atenção', 'classe' => 'bg-amber-50 text-amber-700'],
        'ruim' => ['rotulo' => 'Precisa de ação', 'classe' => 'bg-red-50 text-red-700'],
    ];
    $tomLeitura = $leitura['tom'] ?? null;
    $classeBotao = 'inline-flex items-center gap-2 rounded-full border border-slate-300 bg-white px-4 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary';
@endphp
<div class="mt-4 flex flex-wrap items-center gap-2 print:hidden">
    <button type="button" data-alternar="sobre-{{ $id }}" aria-expanded="false" aria-controls="sobre-{{ $id }}" class="{{ $classeBotao }}">
        <i class="ph ph-book-open text-base" aria-hidden="true"></i> Sobre este quadro
    </button>
    @if (! empty($leitura['texto']))
        <button type="button" data-alternar="leitura-{{ $id }}" aria-expanded="false" aria-controls="leitura-{{ $id }}" class="{{ $classeBotao }}">
            <i class="ph ph-lightbulb text-base" aria-hidden="true"></i> Ver leitura
        </button>
    @endif
    {!! $botoes ?? '' !!}
</div>
<div id="sobre-{{ $id }}" hidden class="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700 leading-relaxed">{{ $sobre }}</div>
@if (! empty($leitura['texto']))
    <div id="leitura-{{ $id }}" hidden class="mt-3 rounded-lg border border-slate-200 bg-white p-4 text-sm leading-relaxed">
        @if ($tomLeitura !== null && isset($tons[$tomLeitura]))
            <span class="mb-1 inline-block rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide {{ $tons[$tomLeitura]['classe'] }}">{{ $tons[$tomLeitura]['rotulo'] }}</span>
        @endif
        <p class="font-semibold text-slate-800">{{ $leitura['texto'] }}</p>
    </div>
@endif
