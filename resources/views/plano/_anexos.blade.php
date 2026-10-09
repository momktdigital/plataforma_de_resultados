{{--
    Evidências anexadas (links e arquivos). O link abre o endereço informado em outra aba; o arquivo é baixado por rota
    autenticada (disco privado), pela rota do perfil de quem está lendo.
    Variáveis: $listaAnexos (coleção de PlanoAcaoAnexo), $plano.
--}}
@if ($listaAnexos->isNotEmpty())
    @php $comoCoordenador = auth('admin')->user()?->ehCoordenador(); @endphp
    <ul class="mt-1.5 space-y-1 text-xs">
        @foreach ($listaAnexos as $anexo)
            <li class="flex items-center gap-1.5">
                @if ($anexo->ehArquivo())
                    <i class="ph-bold ph-paperclip text-slate-600" aria-hidden="true"></i>
                    <a href="{{ $comoCoordenador ? route('coordenador.planos.anexos.show', [$plano, $anexo]) : route('planos.anexos.show', [$plano, $anexo]) }}"
                       class="font-semibold text-emerald-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">{{ $anexo->titulo }}</a>
                    <span class="text-slate-500">({{ $anexo->tamanhoLegivel() }})</span>
                @else
                    <i class="ph-bold ph-link text-slate-600" aria-hidden="true"></i>
                    <a href="{{ $anexo->url }}" target="_blank" rel="noopener noreferrer"
                       class="font-semibold text-emerald-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">{{ $anexo->titulo }}<span class="sr-only"> (abre em outra aba)</span></a>
                @endif
            </li>
        @endforeach
    </ul>
@endif
