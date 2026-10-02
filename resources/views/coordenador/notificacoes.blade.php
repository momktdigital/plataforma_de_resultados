@extends('layouts.app')

@section('title', 'Notificações')

@php
    $estilo = fn (string $tom) => match ($tom) {
        'positivo' => ['bg' => 'bg-emerald-50', 'icone' => 'text-emerald-700'],
        'atencao' => ['bg' => 'bg-amber-50', 'icone' => 'text-amber-700'],
        default => ['bg' => 'bg-slate-100', 'icone' => 'text-slate-600'],
    };
@endphp

@section('content')
<div class="max-w-4xl">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
        <div>
            <h1 class="text-2xl font-black flex items-center gap-2"><i class="ph-bold ph-bell text-primary" aria-hidden="true"></i> Notificações</h1>
            <p class="text-sm text-slate-500 mt-1">
                Avisos quando chegam resultados do seu curso, a média cai, a presença é baixa ou alunos passam a precisar de atenção.
            </p>
        </div>
        @if ($naoLidas > 0)
            <form method="POST" action="{{ route('notificacoes.lidas') }}">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                    <i class="ph-bold ph-checks text-lg" aria-hidden="true"></i> Marcar todas como lidas
                </button>
            </form>
        @endif
    </div>

    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 mb-4 flex flex-wrap items-center gap-3">
        <i class="ph-bold ph-browser text-xl text-slate-600" aria-hidden="true"></i>
        <p id="estado-avisos-navegador" class="text-sm text-slate-600 flex-1 min-w-[220px]" role="status"></p>
        <button type="button" id="ativar-avisos-navegador" class="rounded-lg bg-slate-800 hover:bg-slate-900 px-4 py-2 text-sm font-semibold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">
            Ativar avisos no navegador
        </button>
        <button type="button" id="testar-aviso-navegador" class="hidden rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
            Enviar aviso de teste
        </button>
    </div>

    <div id="ajuda-avisos-navegador" class="hidden bg-amber-50 border border-amber-200 rounded-xl p-4 mb-4 text-sm text-slate-700" role="region" aria-label="Como liberar os avisos manualmente">
        <p class="font-semibold mb-2">Como liberar os avisos manualmente</p>
        <ol class="list-decimal pl-5 space-y-1.5">
            <li>
                Abra o endereço <code id="ajuda-avisos-endereco" class="rounded bg-white px-1.5 py-0.5 border border-amber-200 font-mono text-xs"></code>
                <button type="button" id="ajuda-avisos-copiar" class="ml-1 underline text-slate-700 hover:text-slate-900">Copiar endereço</button>
                numa nova aba (por segurança o navegador não deixa a página abri-lo).
            </li>
            <li>Em <strong>Permitido o envio de notificações</strong> (ou "Allowed to send notifications"), clique em <strong>Adicionar</strong> e informe <code id="ajuda-avisos-site" class="rounded bg-white px-1.5 py-0.5 border border-amber-200 font-mono text-xs"></code>.</li>
            <li>Volte aqui, recarregue a página e use <strong>Enviar aviso de teste</strong>.</li>
        </ol>
        <p class="mt-2 text-slate-600">Se o sistema estiver aberto dentro de outro aplicativo (como o navegador embutido do Claude), abra-o no Chrome, Edge ou Firefox: esses aplicativos geralmente não mostram o pedido de permissão.</p>
    </div>

    <nav class="flex gap-2 mb-4" aria-label="Filtrar notificações">
        @foreach ([['Todas', route('notificacoes.index'), ! $soNaoLidas], ['Não lidas ('.$naoLidas.')', route('notificacoes.index', ['filtro' => 'nao-lidas']), $soNaoLidas]] as [$rotulo, $link, $ativo])
            <a href="{{ $link }}" @if ($ativo) aria-current="true" @endif
               class="rounded-full border px-3.5 py-1.5 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-primary {{ $ativo ? 'bg-slate-800 border-slate-800 text-white' : 'bg-white border-slate-300 text-slate-700 hover:bg-slate-50' }}">{{ $rotulo }}</a>
        @endforeach
    </nav>

    @if ($notificacoes->isEmpty())
        <div class="bg-slate-50 border border-slate-200 text-slate-600 rounded-xl p-6 text-sm">
            {{ $soNaoLidas ? 'Você não tem notificações não lidas.' : 'Ainda não há notificações. Elas aparecem quando resultados do seu curso são importados.' }}
        </div>
    @else
        <ul class="space-y-3">
            @foreach ($notificacoes as $n)
                @php $a = $n->aparencia(); $e = $estilo($a['tom']); @endphp
                <li class="bg-white border rounded-xl shadow-sm p-4 flex items-start gap-3 {{ $n->estaLida() ? 'border-slate-200' : 'border-emerald-300 ring-1 ring-emerald-100' }}">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg {{ $e['bg'] }}"><i class="ph-bold {{ $a['icone'] }} {{ $e['icone'] }} text-xl" aria-hidden="true"></i></span>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold">
                            {{ $n->titulo }}
                            @unless ($n->estaLida())<span class="ml-1 inline-block rounded-full bg-emerald-100 px-2 py-0.5 align-middle text-xs font-bold text-emerald-800">nova</span>@endunless
                        </p>
                        <p class="text-sm text-slate-600 mt-0.5">{{ $n->texto }}</p>
                        <p class="text-xs text-slate-500 mt-1">
                            <time datetime="{{ $n->created_at->toIso8601String() }}" title="{{ $n->created_at->format('d/m/Y H:i') }}">{{ $n->created_at->locale('pt_BR')->diffForHumans() }}</time>
                        </p>
                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            @if ($n->url)
                                <a href="{{ route('notificacoes.abrir', $n) }}" class="text-sm font-semibold text-emerald-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">Ver detalhes</a>
                            @endif
                            @unless ($n->estaLida())
                                <form method="POST" action="{{ route('notificacoes.lida', $n) }}">
                                    @csrf
                                    <button type="submit" class="text-sm text-slate-600 hover:text-slate-900 underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">Marcar como lida</button>
                                </form>
                            @endunless
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">{{ $notificacoes->links() }}</div>
    @endif
</div>
@endsection
