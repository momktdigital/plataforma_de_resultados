{{--
    Cartão com barras de desempenho (cor pela regra de 60%: verde ≥ 60%, amarelo abaixo).
    Variáveis:
      $titulo, $subtitulo
      $itens   array de ['rotulo' => string, 'extra' => ?string, 'valor' => float]
      $larga   true quando o cartão ocupa a linha inteira: as barras então se distribuem em
               colunas, para a tela nunca ficar com um "buraco" ao lado de um cartão estreito
      $rodape  ?string (nota pequena no fim)
--}}
@php use App\Support\CorDesempenho; @endphp
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
    <h3 class="font-semibold mb-1">{{ $titulo }}</h3>
    <p class="text-sm text-slate-500 mb-4">{{ $subtitulo }}</p>
    <div class="{{ $larga ? 'grid gap-x-10 gap-y-3 sm:grid-cols-2 xl:grid-cols-3' : 'space-y-3 max-h-96 overflow-y-auto pr-1' }}">
        @foreach ($itens as $item)
            <div class="min-w-0">
                <div class="flex items-center justify-between gap-2 text-xs mb-1">
                    <span class="font-medium text-slate-600 truncate" title="{{ $item['rotulo'] }}">
                        {{ $item['rotulo'] }}@if (! empty($item['extra'])) <span class="text-slate-400">{{ $item['extra'] }}</span>@endif
                    </span>
                    <span class="font-bold {{ CorDesempenho::classeTexto($item['valor']) }} shrink-0">{{ number_format($item['valor'], 1, ',', '.') }}%</span>
                </div>
                <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
                    <div class="h-full rounded-full {{ CorDesempenho::classeBg($item['valor']) }}" style="width: {{ max(3, min(100, $item['valor'])) }}%"></div>
                </div>
            </div>
        @endforeach
    </div>
    @if (! empty($rodape))
        <p class="text-xs text-slate-400 mt-4">{{ $rodape }}</p>
    @endif
</div>
