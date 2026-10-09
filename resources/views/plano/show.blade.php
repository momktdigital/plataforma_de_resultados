@extends('layouts.app')

@section('title', 'Plano de ação')

@php
    use App\Models\PlanoAcao;
    use App\Models\PlanoAcaoEvento;

    $campo = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary';
    $botaoNeutro = 'inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary';
    $decisao = $plano->eventos->first(fn ($e) => in_array($e->tipo, [PlanoAcaoEvento::APROVADO, PlanoAcaoEvento::AJUSTES, PlanoAcaoEvento::RECUSADO], true));
    $progresso = $plano->progresso();
@endphp

@section('content')
<div class="max-w-5xl space-y-6">
    <div>
        <a href="{{ route('coordenador.planos.index') }}" class="text-sm text-slate-500 hover:underline">&larr; Planos de ação</a>
        <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-2xl font-black flex items-center gap-2 break-words"><i class="ph-bold ph-clipboard-text text-primary shrink-0" aria-hidden="true"></i> {{ $plano->origem_rotulo }}</h1>
                <p class="text-sm text-slate-600 mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
                    @include('plano._status', ['status' => $plano->status])
                    <span>{{ $plano->curso }}</span>
                    <span>{{ $plano->periodo_letivo ?: 'todos os períodos' }}</span>
                    @if ($plano->enviado_em)<span>enviado em {{ $plano->enviado_em->format('d/m/Y') }}@if ($plano->envios > 1) ({{ $plano->envios }}º envio)@endif</span>@endif
                </p>
            </div>

            <div class="flex flex-wrap gap-2 print:hidden">
                <button type="button" onclick="window.print()" class="print:hidden inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"><i class="ph-bold ph-printer" aria-hidden="true"></i> Imprimir / salvar em PDF</button>
            @if ($podeEscrever)
                    @if ($plano->editavel())
                        <a href="{{ route('coordenador.planos.edit', $plano) }}" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 px-4 py-2 text-sm font-semibold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"><i class="ph-bold ph-pencil-simple" aria-hidden="true"></i> {{ $plano->status === PlanoAcao::AJUSTES ? 'Fazer os ajustes' : 'Continuar editando' }}</a>
                    @endif
                    @if ($plano->aguardandoAnalise())
                        <form method="POST" action="{{ route('coordenador.planos.retirar', $plano) }}" onsubmit="return confirm('Retirar o plano da análise? Ele volta a ser rascunho.')">
                            @csrf
                            <button type="submit" class="{{ $botaoNeutro }}"><i class="ph-bold ph-arrow-u-up-left" aria-hidden="true"></i> Retirar da análise</button>
                        </form>
                    @endif
                    @if ($plano->finalizado() || $plano->emExecucao())
                        <form method="POST" action="{{ route('coordenador.planos.duplicar', $plano) }}">
                            @csrf
                            <button type="submit" class="{{ $botaoNeutro }}"><i class="ph-bold ph-copy" aria-hidden="true"></i> Criar cópia como rascunho</button>
                        </form>
                    @endif
                    @if ($plano->status === PlanoAcao::RASCUNHO)
                        <form method="POST" action="{{ route('coordenador.planos.destroy', $plano) }}" onsubmit="return confirm('Excluir este rascunho? Esta ação não pode ser desfeita.')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-red-200 bg-white px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"><i class="ph-bold ph-trash" aria-hidden="true"></i> Excluir rascunho</button>
                        </form>
                    @endif
            @endif
            </div>
        </div>
    </div>

    {{-- A decisão do colaborador --}}
    @if ($decisao && in_array($plano->status, [PlanoAcao::APROVADO, PlanoAcao::AJUSTES, PlanoAcao::RECUSADO], true))
        @php
            [$icone, $faixa] = match ($plano->status) {
                PlanoAcao::APROVADO => ['ph-check-circle', 'border-emerald-200 bg-emerald-50 text-emerald-900'],
                PlanoAcao::AJUSTES => ['ph-pencil-line', 'border-amber-200 bg-amber-50 text-amber-900'],
                default => ['ph-x-circle', 'border-red-200 bg-red-50 text-red-900'],
            };
        @endphp
        <div class="rounded-xl border p-4 text-sm {{ $faixa }}" role="note">
            <p class="font-bold flex items-center gap-2"><i class="ph-bold {{ $icone }}" aria-hidden="true"></i> {{ $decisao->rotulo() }} por {{ $decisao->admin?->username ?? 'colaborador' }} em {{ $decisao->created_at?->format('d/m/Y') }}</p>
            @if ($decisao->texto)<p class="mt-1 whitespace-pre-line">{{ $decisao->texto }}</p>@endif
        </div>
    @endif

    @if ($plano->aguardandoAnalise())
        <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 flex items-center gap-2" role="status">
            <i class="ph-bold ph-hourglass-medium text-lg" aria-hidden="true"></i> O plano está com o colaborador para análise. Você será avisado da decisão.
        </div>
    @endif

    @if ($plano->editavel() && $lacunas !== [])
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" role="status">
            <p class="font-bold flex items-center gap-2"><i class="ph-bold ph-list-checks" aria-hidden="true"></i> Ainda em branco <span class="font-normal">(nada é obrigatório: você pode enviar assim mesmo)</span></p>
            <ul class="list-disc pl-5 mt-1 space-y-0.5">
                @foreach ($lacunas as $falta)
                    <li>{{ $falta['mensagem'] }} @if ($podeEscrever)<a href="{{ route('coordenador.planos.edit', [$plano, 'etapa' => $falta['etapa']]) }}" class="underline font-semibold">Ir para a etapa {{ $falta['etapa'] }}</a>@endif</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($plano->aguardandoAnalise() || $plano->status === PlanoAcao::AJUSTES)
        @include('plano._mudancas', ['mudancasDoEnvio' => $mudancasDoEnvio])
    @endif

    @if ($resultado)
        @include('plano._resultado', ['resultado' => $resultado])
    @endif

    @if (in_array($plano->status, [PlanoAcao::APROVADO, PlanoAcao::CONCLUIDO, PlanoAcao::CANCELADO], true) && $plano->acoes->isNotEmpty())
        @include('plano._acompanhamento', ['plano' => $plano, 'podeAtualizar' => $podeEscrever && $plano->emExecucao()])
    @endif

    {{-- Encerrar ou cancelar o plano --}}
    @if ($podeEscrever && $plano->emExecucao())
        <section class="print:hidden bg-white border border-slate-200 rounded-xl shadow-sm p-6" aria-labelledby="titulo-encerrar">
            <h2 id="titulo-encerrar" class="text-lg font-bold mb-1">Encerrar o plano</h2>
            <p class="text-sm text-slate-600 mb-3">Quando todas as ações estiverem concluídas (ou canceladas), registre a síntese do que foi feito e aprendido.
                @if ($progresso['total'] - $progresso['concluidas'] > 0) Ainda há {{ $progresso['total'] - $progresso['concluidas'] }} ação(ões) aberta(s). @endif</p>
            <form method="POST" enctype="multipart/form-data" action="{{ route('coordenador.planos.encerrar', $plano) }}" class="space-y-3">
                @csrf
                <div>
                    <label for="conclusao" class="block text-sm font-semibold mb-1">Síntese do que foi feito e aprendido</label>
                    <textarea id="conclusao" name="conclusao" rows="4" maxlength="5000" class="{{ $campo }}">{{ old('conclusao') }}</textarea>
                </div>
                @include('plano._campos-evidencia', ['prefixo' => 'encerrar'])
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 px-4 py-2 text-sm font-semibold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"><i class="ph-bold ph-flag-checkered" aria-hidden="true"></i> Encerrar plano</button>
                </div>
            </form>
            <details class="mt-4">
                <summary class="cursor-pointer text-sm font-semibold text-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">Cancelar o plano</summary>
                <form method="POST" action="{{ route('coordenador.planos.cancelar', $plano) }}" class="mt-2 space-y-2" onsubmit="return confirm('Cancelar este plano? Ele deixa de ser acompanhado.')">
                    @csrf
                    <label for="motivo" class="block text-sm font-semibold">Motivo do cancelamento</label>
                    <textarea id="motivo" name="motivo" rows="3" maxlength="3000" required class="{{ $campo }}"></textarea>
                    <button type="submit" class="rounded-lg border border-red-200 bg-white px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">Cancelar plano</button>
                </form>
            </details>
        </section>
    @elseif ($podeEscrever && $plano->status === PlanoAcao::AJUSTES)
        <details class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
            <summary class="cursor-pointer text-sm font-semibold text-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">Desistir deste plano (cancelar)</summary>
            <form method="POST" action="{{ route('coordenador.planos.cancelar', $plano) }}" class="mt-2 space-y-2" onsubmit="return confirm('Cancelar este plano?')">
                @csrf
                <label for="motivo" class="block text-sm font-semibold">Motivo do cancelamento</label>
                <textarea id="motivo" name="motivo" rows="3" maxlength="3000" required class="{{ $campo }}"></textarea>
                <button type="submit" class="rounded-lg border border-red-200 bg-white px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">Cancelar plano</button>
            </form>
        </details>
    @endif

    @if ($plano->conclusao)
        <section class="rounded-xl border border-emerald-200 bg-emerald-50 p-5 text-sm text-emerald-900" aria-labelledby="titulo-conclusao">
            <h2 id="titulo-conclusao" class="font-bold flex items-center gap-2"><i class="ph-bold ph-flag-checkered" aria-hidden="true"></i> Síntese do encerramento ({{ $plano->encerrado_em?->format('d/m/Y') }})</h2>
            <p class="mt-1 whitespace-pre-line">{{ $plano->conclusao }}</p>
            @include('plano._anexos', ['listaAnexos' => $plano->anexos->where('acao_id', null)->whereNotNull('evento_id'), 'plano' => $plano])
        </section>
    @endif

    @include('plano._resumo', ['plano' => $plano, 'alertas' => $alertas])

    {{-- Comentário solto (conversa com o colaborador) --}}
    @if ($podeEscrever && $plano->status !== PlanoAcao::RASCUNHO)
        <section class="print:hidden bg-white border border-slate-200 rounded-xl shadow-sm p-6" aria-labelledby="titulo-comentar">
            <h2 id="titulo-comentar" class="text-lg font-bold mb-2">Comentar</h2>
            <form method="POST" action="{{ route('coordenador.planos.comentarios.store', $plano) }}" class="space-y-2">
                @csrf
                <label for="texto" class="sr-only">Comentário</label>
                <textarea id="texto" name="texto" rows="3" maxlength="3000" required placeholder="Uma dúvida, um registro para o colaborador…" class="{{ $campo }}"></textarea>
                <button type="submit" class="{{ $botaoNeutro }}"><i class="ph-bold ph-chat-text" aria-hidden="true"></i> Registrar comentário</button>
            </form>
        </section>
    @endif

    @include('plano._linha-do-tempo', ['plano' => $plano])
</div>
@endsection
