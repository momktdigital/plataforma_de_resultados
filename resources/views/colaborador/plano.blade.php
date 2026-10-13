@extends('layouts.app')

@section('title', 'Acompanhar plano de ação')

@php
    use App\Models\PlanoAcao;
    use Illuminate\Support\Facades\Auth;

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
            @if ($plano->enviado_em)<span>publicado em {{ $plano->enviado_em->format('d/m/Y') }}</span>@endif
        </p>
        <div class="mt-3 print:hidden"><button type="button" onclick="window.print()" class="print:hidden inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"><i class="ph-bold ph-printer" aria-hidden="true"></i> Imprimir / salvar em PDF</button></div>
    </div>

    @if ($lacunas !== [])
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" role="note">
            <p class="font-bold">Pontos deixados em branco pelo coordenador (nenhuma etapa é obrigatória):</p>
            <ul class="list-disc pl-5 mt-1">@foreach ($lacunas as $falta)<li>{{ $falta['mensagem'] }}</li>@endforeach</ul>
        </div>
    @endif

    @if ($resultado)
        @include('plano._resultado', ['resultado' => $resultado])
    @endif

    @if (in_array($plano->status, [PlanoAcao::EM_EXECUCAO, PlanoAcao::CONCLUIDO, PlanoAcao::CANCELADO], true) && $plano->acoes->isNotEmpty())
        @include('plano._acompanhamento', ['plano' => $plano, 'podeAtualizar' => false])
    @endif

    @if ($plano->conclusao)
        <section class="rounded-xl border border-emerald-200 bg-emerald-50 p-5 text-sm text-emerald-900" aria-labelledby="titulo-conclusao">
            <h2 id="titulo-conclusao" class="font-bold flex items-center gap-2"><i class="ph-bold ph-flag-checkered" aria-hidden="true"></i> Síntese do encerramento ({{ $plano->encerrado_em?->format('d/m/Y') }})</h2>
            <p class="mt-1 whitespace-pre-line">{{ $plano->conclusao }}</p>
            @include('plano._anexos', ['listaAnexos' => $plano->anexos->where('acao_id', null)->whereNotNull('evento_id'), 'plano' => $plano])
        </section>
    @endif

    @include('plano._resumo', ['plano' => $plano, 'alertas' => $alertas])

    <section class="print:hidden bg-white border border-slate-200 rounded-xl shadow-sm p-6" aria-labelledby="titulo-comentar">
        <h2 id="titulo-comentar" class="text-lg font-bold mb-2">Comentar com o coordenador</h2>
        <p class="text-sm text-slate-600 mb-2">O coordenador recebe uma notificação (e um e-mail, se o envio estiver ativo).</p>
        <form method="POST" action="{{ route('colaborador.planos.comentar', $plano) }}" class="space-y-2">
            @csrf
            <label for="texto" class="sr-only">Comentário</label>
            <textarea id="texto" name="texto" rows="3" maxlength="3000" required class="{{ $campo }}">{{ old('texto') }}</textarea>
            <button type="submit" class="{{ $botaoNeutro }}"><i class="ph-bold ph-chat-text" aria-hidden="true"></i> Registrar comentário</button>
        </form>
    </section>

    @if ($outrosDoCurso->isNotEmpty())
        <section class="print:hidden bg-white border border-slate-200 rounded-xl shadow-sm p-6" aria-labelledby="titulo-outros">
            <h2 id="titulo-outros" class="text-lg font-bold mb-2">Outros planos deste curso</h2>
            <p class="text-sm text-slate-600 mb-3">O que já foi proposto antes para {{ $plano->curso }}, para acompanhar este plano com o histórico em mente.</p>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($outrosDoCurso as $outro)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span class="min-w-0">
                            <a href="{{ route('colaborador.planos.show', $outro) }}" class="font-semibold text-slate-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">{{ $outro->origem_rotulo }}</a>
                            <span class="block text-xs text-slate-500">{{ $outro->periodo_letivo ?: 'todos os períodos' }}@if ($outro->causa_raiz) · causa-raiz: {{ \Illuminate\Support\Str::limit($outro->causa_raiz, 90) }}@endif</span>
                        </span>
                        @include('plano._status', ['status' => $outro->status, 'pequeno' => true])
                    </li>
                @endforeach
            </ul>
        </section>
    @endif


    @if (Auth::guard('admin')->user()?->ehAdministrador())
        <section class="print:hidden bg-white border border-red-200 rounded-xl shadow-sm p-6" aria-labelledby="titulo-admin">
            <h2 id="titulo-admin" class="text-lg font-bold mb-1 flex items-center gap-2"><i class="ph-bold ph-shield-warning text-red-700" aria-hidden="true"></i> Ações do administrador</h2>
            <p class="text-sm text-slate-600 mb-4">Ficam na auditoria com o motivo. Escreva o motivo (mínimo de 10 caracteres) antes de usar um dos botões.</p>
            @error('motivo')<p class="text-sm text-red-700 mb-2" role="alert">{{ $message }}</p>@enderror
            <div class="grid gap-4 md:grid-cols-2">
                <form method="POST" action="{{ route('colaborador.planos.reverter', $plano) }}" class="space-y-2" onsubmit="return confirm('Devolver este plano ao coordenador como rascunho? Ele deixa de aparecer na fila de acompanhamento até ser publicado de novo.')">
                    @csrf
                    <label for="motivo-reverter" class="block text-sm font-semibold">Reverter para rascunho</label>
                    <p class="text-xs text-slate-600">O coordenador pode corrigir e publicar de novo. O histórico e as evidências ficam.</p>
                    <textarea id="motivo-reverter" name="motivo" rows="2" maxlength="1000" required class="{{ $campo }}" placeholder="Motivo da reversão"></textarea>
                    <button type="submit" class="{{ $botaoNeutro }}"><i class="ph-bold ph-arrow-u-up-left" aria-hidden="true"></i> Reverter para rascunho</button>
                </form>
                <form method="POST" action="{{ route('colaborador.planos.excluir', $plano) }}" class="space-y-2" onsubmit="return confirm('Excluir este plano DEFINITIVAMENTE? Ações, histórico e evidências são apagados e não há como desfazer.')">
                    @csrf
                    @method('DELETE')
                    <label for="motivo-excluir" class="block text-sm font-semibold text-red-700">Excluir definitivamente</label>
                    <p class="text-xs text-slate-600">Apaga o plano, as ações, o histórico e as evidências anexas. Não tem volta.</p>
                    <textarea id="motivo-excluir" name="motivo" rows="2" maxlength="1000" required class="{{ $campo }}" placeholder="Motivo da exclusão"></textarea>
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700"><i class="ph-bold ph-trash" aria-hidden="true"></i> Excluir definitivamente</button>
                </form>
            </div>
        </section>
    @endif

    @include('plano._linha-do-tempo', ['plano' => $plano])
</div>
@endsection
