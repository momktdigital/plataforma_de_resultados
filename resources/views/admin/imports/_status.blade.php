{{-- Banner de status de um import assíncrono — usado pelas telas de import de
     resultados/questões/matrícula. Espera $status no formato de
     App\Support\ImportStatusTracker::status() e, opcionalmente, $voltar (link
     mostrado junto do resumo de sucesso). --}}
@if ($status['status'] === 'processando')
    <div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 text-blue-900 px-4 py-3 text-sm">
        <p class="font-semibold mb-1 flex items-center gap-2">
            <i class="ph ph-spinner animate-spin"></i> Import em andamento&hellip;
        </p>
        <p>Essa tela atualiza sozinha a cada alguns segundos. Uma planilha grande pode levar alguns minutos.</p>
        @if ($status['iniciadoEm'])
            <p class="mt-2 text-blue-700" id="import-aviso-demora" style="display:none">
                Isso está demorando mais que o esperado — confirme se o worker da fila
                (<code class="bg-blue-100 px-1 rounded">php artisan queue:work</code>) está rodando no servidor.
                Sem ele, o import fica pendente indefinidamente.
            </p>
        @endif
    </div>
    <script>
        setTimeout(function () { window.location.reload(); }, 5000);

        @if ($status['iniciadoEm'])
            (function () {
                var iniciadoEm = new Date('{{ $status['iniciadoEm'] }}').getTime();
                if (Date.now() - iniciadoEm > 2 * 60 * 1000) {
                    document.getElementById('import-aviso-demora').style.display = 'block';
                }
            })();
        @endif
    </script>
@elseif ($status['status'] === 'erro')
    <div class="mb-6 rounded-lg border border-red-200 bg-red-50 text-red-900 px-4 py-3 text-sm">
        <p class="font-semibold mb-1">O último import falhou.</p>
        <p>{{ $status['erro'] }}</p>
    </div>
@elseif ($status['status'] === 'concluido' && $status['resumo'])
    <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm">
        <p class="font-semibold mb-1">Último import concluído.</p>
        <p>{{ $status['resumo'] }}</p>
        @if (! empty($status['ignoradas']))
            <details class="mt-2">
                <summary class="cursor-pointer font-medium">Ver linhas ignoradas ({{ count($status['ignoradas']) }})</summary>
                <ul class="mt-1.5 space-y-0.5 text-emerald-800">
                    @foreach ($status['ignoradas'] as $ignorada)
                        <li>Linha {{ $ignorada['linha'] }}: {{ $ignorada['motivo'] }}</li>
                    @endforeach
                </ul>
            </details>
        @endif
        @isset($voltar)
            <p class="mt-2"><a href="{{ $voltar }}" class="font-semibold hover:underline">Ver avaliação &rarr;</a></p>
        @endisset
    </div>
@endif
