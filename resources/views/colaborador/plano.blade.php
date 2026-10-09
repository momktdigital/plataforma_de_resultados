@extends('layouts.app')

@section('title', 'Analisar plano de ação')

@php
    use App\Models\PlanoAcao;

    $campo = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary';
    $botaoNeutro = 'inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary';
@endphp

@section('content')
<div class="max-w-5xl space-y-6">
    <div>
        <a href="{{ route('colaborador.planos.index') }}" class="text-sm text-slate-500 hover:underline">&larr; Planos de ação</a>
        <h1 class="text-2xl font-black mt-2 flex items-center gap-2 break-words"><i class="ph-bold ph-clipboard-text text-primary shrink-0" aria-hidden="true"></i> {{ $plano->origem_rotulo }}</h1>
        <p class="text-sm text-slate-600 mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
            @include('plano._status', ['status' => $plano->status])
            <span>{{ $plano->curso }}</span>
            <span>{{ $plano->periodo_letivo ?: 'todos os períodos' }}</span>
            <span>por {{ $plano->autor?->username ?? '—' }}</span>
            @if ($plano->enviado_em)<span>enviado em {{ $plano->enviado_em->format('d/m/Y') }}@if ($plano->envios > 1) ({{ $plano->envios }}º envio)@endif</span>@endif
        </p>
        <div class="mt-3 print:hidden"><button type="button" onclick="window.print()" class="print:hidden inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"><i class="ph-bold ph-printer" aria-hidden="true"></i> Imprimir / salvar em PDF</button></div>
    </div>

    {{-- A decisão --}}
    @if ($plano->aguardandoAnalise())
        <section class="print:hidden bg-white border-2 border-sky-300 rounded-xl shadow-sm p-6" aria-labelledby="titulo-decisao">
            <h2 id="titulo-decisao" class="text-lg font-bold flex items-center gap-2"><i class="ph-bold ph-gavel text-sky-800" aria-hidden="true"></i> Sua decisão</h2>
            <p class="text-sm text-slate-600 mt-1">Leia o plano abaixo e marque o que ele atende. Para pedir ajustes ou recusar, a justificativa é obrigatória — ela é o que o coordenador vai ler.</p>

            <form method="POST" action="{{ route('colaborador.planos.decidir', $plano) }}" class="mt-4 space-y-5">
                @csrf
                <fieldset>
                    <legend class="text-sm font-bold mb-2">O plano atende aos critérios?</legend>
                    <div class="space-y-1.5">
                        @foreach ($criterios as $chave => $texto)
                            <label class="flex items-start gap-2 text-sm">
                                <input type="checkbox" name="criterios[]" value="{{ $chave }}" @checked(in_array($chave, old('criterios', []), true)) class="mt-0.5 h-4 w-4 rounded border-slate-300 text-emerald-700 focus:ring-primary">
                                <span>{{ $texto }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <fieldset>
                    <legend class="text-sm font-bold mb-2">Decisão</legend>
                    <div class="grid gap-2 sm:grid-cols-3">
                        @foreach ([
                            'aprovar' => ['Aprovar', 'ph-check-circle', 'O plano segue para execução.'],
                            'ajustes' => ['Pedir ajustes', 'ph-pencil-line', 'Volta ao coordenador, que edita e reenvia.'],
                            'recusar' => ['Recusar', 'ph-x-circle', 'Encerra o plano; o coordenador pode criar outro.'],
                        ] as $valor => [$rotulo, $icone, $explicacao])
                            <label class="flex cursor-pointer items-start gap-2 rounded-xl border border-slate-300 bg-white p-3 text-sm has-[:checked]:border-slate-800 has-[:checked]:ring-2 has-[:checked]:ring-slate-300">
                                <input type="radio" name="decisao" value="{{ $valor }}" required @checked(old('decisao') === $valor) class="mt-1 h-4 w-4 border-slate-300 text-emerald-700 focus:ring-primary">
                                <span>
                                    <span class="flex items-center gap-1.5 font-bold"><i class="ph-bold {{ $icone }}" aria-hidden="true"></i>{{ $rotulo }}</span>
                                    <span class="block text-xs text-slate-600">{{ $explicacao }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div>
                    <label for="justificativa" class="block text-sm font-bold mb-1">Justificativa <span class="font-normal text-slate-500">(obrigatória para pedir ajustes ou recusar; opcional ao aprovar)</span></label>
                    <textarea id="justificativa" name="justificativa" rows="4" maxlength="3000" class="{{ $campo }}">{{ old('justificativa') }}</textarea>
                </div>

                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-800 hover:bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">
                    <i class="ph-bold ph-paper-plane-tilt" aria-hidden="true"></i> Registrar decisão
                </button>
            </form>
        </section>
    @elseif ($plano->status === PlanoAcao::AJUSTES)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 flex items-center gap-2" role="status"><i class="ph-bold ph-pencil-line text-lg" aria-hidden="true"></i> Devolvido ao coordenador para ajustes. Quando ele reenviar, o plano volta para a fila.</div>
    @endif

    @if ($lacunas !== [] && $plano->aguardandoAnalise())
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" role="note">
            <p class="font-bold">Pontos deixados em branco pelo coordenador (nenhuma etapa é obrigatória):</p>
            <ul class="list-disc pl-5 mt-1">@foreach ($lacunas as $falta)<li>{{ $falta['mensagem'] }}</li>@endforeach</ul>
        </div>
    @endif

    @if ($resultado)
        @include('plano._resultado', ['resultado' => $resultado])
    @endif

    @if (in_array($plano->status, [PlanoAcao::APROVADO, PlanoAcao::CONCLUIDO, PlanoAcao::CANCELADO], true) && $plano->acoes->isNotEmpty())
        @include('plano._acompanhamento', ['plano' => $plano, 'podeAtualizar' => false])
    @endif

    @if ($plano->conclusao)
        <section class="rounded-xl border border-emerald-200 bg-emerald-50 p-5 text-sm text-emerald-900" aria-labelledby="titulo-conclusao">
            <h2 id="titulo-conclusao" class="font-bold flex items-center gap-2"><i class="ph-bold ph-flag-checkered" aria-hidden="true"></i> Síntese do encerramento ({{ $plano->encerrado_em?->format('d/m/Y') }})</h2>
            <p class="mt-1 whitespace-pre-line">{{ $plano->conclusao }}</p>
        </section>
    @endif

    @include('plano._resumo', ['plano' => $plano, 'alertas' => $alertas])

    <section class="print:hidden bg-white border border-slate-200 rounded-xl shadow-sm p-6" aria-labelledby="titulo-comentar">
        <h2 id="titulo-comentar" class="text-lg font-bold mb-2">Comentar com o coordenador</h2>
        <p class="text-sm text-slate-600 mb-2">Um comentário não muda a situação do plano; o coordenador recebe uma notificação.</p>
        <form method="POST" action="{{ route('colaborador.planos.comentar', $plano) }}" class="space-y-2">
            @csrf
            <label for="texto" class="sr-only">Comentário</label>
            <textarea id="texto" name="texto" rows="3" maxlength="3000" required class="{{ $campo }}">{{ old('texto') }}</textarea>
            <button type="submit" class="{{ $botaoNeutro }}"><i class="ph-bold ph-chat-text" aria-hidden="true"></i> Registrar comentário</button>
        </form>
    </section>

    @include('plano._linha-do-tempo', ['plano' => $plano])
</div>
@endsection
