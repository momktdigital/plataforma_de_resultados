@extends('layouts.app')

@section('title', "Respondente — Avaliação #{$avaliacao->codigo}")

@section('content')
<a href="{{ route('avaliacoes.respondentes.index', $avaliacao) }}" class="text-sm text-slate-500 hover:underline">&larr; Resultados por aluno</a>
<div class="flex items-start justify-between gap-4 mt-2 mb-6">
    <div>
        <h1 class="text-2xl font-bold mb-1">
            {{ $aluno?->nome ?: ($respostas->first()->ra ?: $respostas->first()->cpf ?: $chave) }}
        </h1>
        <p class="text-slate-500">
            @if ($aluno?->nome)
                RA {{ $respostas->first()->ra ?: $aluno->ra }} &middot;
            @endif
            Período: {{ $periodo !== '' ? $periodo : '(sem período)' }}
        </p>
    </div>
    @if ($total !== null)
        <div class="text-right shrink-0">
            <div class="text-2xl font-black text-emerald-700">{{ $acertos }}/{{ $total }}</div>
            <div class="text-xs text-slate-500 font-medium">acertos</div>
        </div>
    @endif
</div>

@if ($metricas->isNotEmpty())
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
        <h2 class="font-semibold mb-4">Notas finais</h2>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            @foreach ($metricas as $metrica)
                <div class="bg-slate-50 border border-slate-100 rounded-lg p-3">
                    <div class="text-xs font-bold text-slate-500 uppercase truncate" title="{{ $metrica->nome_metrica }}">{{ $metrica->nome_metrica }}</div>
                    <div class="text-xl font-black text-emerald-700">{{ $metrica->valor }}</div>
                </div>
            @endforeach
        </div>
    </div>
@endif

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
    <h2 class="font-semibold mb-4">Respostas</h2>
    <div class="grid grid-cols-5 sm:grid-cols-8 md:grid-cols-10 gap-2">
        @foreach ($respostas as $resposta)
            @php
                $correta = $gabaritos[$resposta->questao_numero] ?? null;
                $marcada = $resposta->resposta ?: '';
                $cor = 'bg-slate-400';
                $statusIcone = null; // 'ph-check' | 'ph-x' | null — sinal além da cor, pra quem tem daltonismo
                if ($correta !== null && $correta !== '') {
                    if ($marcada === $correta) {
                        $cor = 'bg-green-500';
                        $statusIcone = 'ph-check';
                    } elseif ($marcada !== '') {
                        $cor = 'bg-red-500';
                        $statusIcone = 'ph-x';
                    }
                }
            @endphp
            <div class="rounded overflow-hidden border border-slate-200">
                <div class="{{ $cor }} text-white text-[10px] text-center font-bold py-1 flex items-center justify-center gap-0.5">
                    <span>Q{{ $resposta->questao_numero }}</span>
                    @if ($statusIcone)
                        <i class="ph-bold {{ $statusIcone }}" aria-hidden="true"></i>
                    @endif
                </div>
                <button type="button" class="resposta-editar-btn w-full bg-white text-center font-bold text-sm py-1.5 hover:bg-slate-50 {{ $marcada === '' ? 'text-slate-300' : 'text-slate-700' }}"
                        data-questao="{{ $resposta->questao_numero }}"
                        data-resposta-id="{{ $resposta->id }}"
                        data-resposta-atual="{{ $marcada }}"
                        data-update-url="{{ route('avaliacoes.respondentes.respostas.update', ['avaliacao' => $avaliacao, 'resposta' => $resposta->id]) }}"
                        title="Clique para corrigir esta resposta">
                    {{ $marcada !== '' ? $marcada : '-' }}
                </button>
            </div>
        @endforeach
    </div>
    <p class="text-xs text-slate-400 mt-3">Clique numa resposta pra corrigi-la (ex.: bolha mal escaneada) sem reimportar o período inteiro.</p>
</div>

<div id="modal-editar-resposta" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4"
     role="dialog" aria-modal="true" aria-labelledby="modal-editar-resposta-titulo">
    <div class="bg-white rounded-2xl shadow-xl max-w-sm w-full p-5">
        <div class="flex items-center justify-between mb-4">
            <h3 id="modal-editar-resposta-titulo" class="font-bold text-slate-800">Corrigir resposta — Questão <span id="modal-editar-resposta-numero"></span></h3>
            <button type="button" id="modal-editar-resposta-fechar" aria-label="Fechar" class="text-slate-400 hover:text-slate-600">
                <i class="ph-bold ph-x text-lg"></i>
            </button>
        </div>
        <form method="POST" id="form-editar-resposta">
            @csrf
            @method('PUT')
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1" for="modal-editar-resposta-valor">Nova resposta</label>
                <input id="modal-editar-resposta-valor" name="resposta" type="text" maxlength="10"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm uppercase">
                <p class="text-xs text-slate-400 mt-1">Deixe em branco para marcar como "sem resposta".</p>
            </div>
            <div class="flex gap-3">
                <button type="button" id="modal-editar-resposta-cancelar" class="flex-1 border border-slate-300 text-slate-700 hover:bg-slate-50 font-semibold rounded-lg px-4 py-2 text-sm">
                    Cancelar
                </button>
                <button type="submit" class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-4 py-2 text-sm">
                    Salvar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('modal-editar-resposta');
    var form = document.getElementById('form-editar-resposta');
    var campoValor = document.getElementById('modal-editar-resposta-valor');
    var numeroLabel = document.getElementById('modal-editar-resposta-numero');
    var btnFechar = document.getElementById('modal-editar-resposta-fechar');
    var btnCancelar = document.getElementById('modal-editar-resposta-cancelar');
    var ultimoFoco = null;

    function focaveisDoModal() {
        return Array.prototype.slice.call(
            modal.querySelectorAll('button, input, [href]')
        ).filter(function (el) { return ! el.disabled && el.offsetParent !== null; });
    }

    function abrirModal(botao) {
        numeroLabel.textContent = botao.dataset.questao;
        campoValor.value = botao.dataset.respostaAtual || '';
        form.action = botao.dataset.updateUrl;

        ultimoFoco = botao;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        campoValor.focus();
    }

    function fecharModal() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        if (ultimoFoco) {
            ultimoFoco.focus();
            ultimoFoco = null;
        }
    }

    document.querySelectorAll('.resposta-editar-btn').forEach(function (botao) {
        botao.addEventListener('click', function () { abrirModal(botao); });
    });

    btnFechar.addEventListener('click', fecharModal);
    btnCancelar.addEventListener('click', fecharModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) fecharModal();
    });

    document.addEventListener('keydown', function (event) {
        if (modal.classList.contains('hidden')) return;

        if (event.key === 'Escape') {
            fecharModal();

            return;
        }

        if (event.key === 'Tab') {
            var focaveis = focaveisDoModal();
            var primeiro = focaveis[0];
            var ultimo = focaveis[focaveis.length - 1];

            if (event.shiftKey && document.activeElement === primeiro) {
                event.preventDefault();
                ultimo.focus();
            } else if (! event.shiftKey && document.activeElement === ultimo) {
                event.preventDefault();
                primeiro.focus();
            }
        }
    });

    form.addEventListener('submit', function (event) {
        if (! confirm('Corrigir esta resposta recalcula o boletim deste aluno agora mesmo, sem desfazer. Continuar?')) {
            event.preventDefault();
        }
    });
})();
</script>
@endsection
