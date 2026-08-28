@extends('layouts.app')

@section('title', 'Categorias — Avaliações')

@section('content')
<h1 class="text-2xl font-bold mb-6">Categorias de avaliação</h1>
<p class="text-sm text-slate-500 mb-6 max-w-2xl">
    Organizam o boletim do aluno no portal público em árvore (categoria →
    subcategorias → avaliações). Uma avaliação sem categoria aparece à parte, em
    "Sem categoria".
</p>

<div class="grid lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 bg-white border border-slate-200 rounded-xl shadow-sm p-6">
        <h2 class="font-semibold mb-4">Árvore de categorias</h2>

        @if ($raizes->isEmpty())
            <p class="text-sm text-slate-400">Nenhuma categoria cadastrada ainda.</p>
        @else
            <ul>
                @foreach ($raizes as $categoria)
                    @include('admin.categorias._no', ['categoria' => $categoria, 'porPai' => $porPai, 'opcoesSelect' => $opcoesSelect])
                @endforeach
            </ul>
        @endif
    </div>

    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
        <h2 class="font-semibold mb-4">Nova categoria</h2>
        <form method="POST" action="{{ route('categorias.store') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium mb-1" for="nome">Nome</label>
                <input id="nome" name="nome" type="text" required value="{{ old('nome') }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" for="categoria_pai_id">Categoria-mãe (opcional)</label>
                <select id="categoria_pai_id" name="categoria_pai_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">— Categoria raiz —</option>
                    @foreach ($opcoesSelect as $opcao)
                        <option value="{{ $opcao['id'] }}" {{ (int) old('categoria_pai_id') === $opcao['id'] ? 'selected' : '' }}>
                            {{ $opcao['label'] }}
                        </option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-4 py-2 text-sm">
                Criar categoria
            </button>
        </form>
    </div>
</div>

<div id="modal-excluir-categoria" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4"
     role="dialog" aria-modal="true" aria-labelledby="modal-excluir-categoria-titulo">
    <div class="bg-white rounded-2xl shadow-xl max-w-sm w-full p-5">
        <div class="flex items-center justify-between mb-4">
            <h3 id="modal-excluir-categoria-titulo" class="font-bold text-slate-800">Excluir categoria</h3>
            <button type="button" id="modal-excluir-categoria-fechar" aria-label="Fechar" class="text-slate-400 hover:text-slate-600">
                <i class="ph-bold ph-x text-lg"></i>
            </button>
        </div>
        <p class="text-sm text-slate-600 mb-4">
            <strong id="modal-excluir-categoria-nome"></strong> tem
            <strong id="modal-excluir-categoria-qtd"></strong> avaliação(ões) vinculada(s).
            Pra onde elas devem ir?
        </p>
        <div class="mb-4">
            <label class="block text-sm font-medium mb-1" for="modal-excluir-categoria-destino">Mover avaliações para</label>
            <select id="modal-excluir-categoria-destino" name="mover_avaliacoes_para"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="">— Sem categoria —</option>
                @foreach ($opcoesSelect as $opcao)
                    <option value="{{ $opcao['id'] }}" data-categoria-id="{{ $opcao['id'] }}">{{ $opcao['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex gap-3">
            <button type="button" id="modal-excluir-categoria-cancelar" class="flex-1 border border-slate-300 text-slate-700 hover:bg-slate-50 font-semibold rounded-lg px-4 py-2 text-sm">
                Cancelar
            </button>
            <button type="button" id="modal-excluir-categoria-confirmar" class="flex-1 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-lg px-4 py-2 text-sm">
                Excluir
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('modal-excluir-categoria');
    var nomeLabel = document.getElementById('modal-excluir-categoria-nome');
    var qtdLabel = document.getElementById('modal-excluir-categoria-qtd');
    var destinoSelect = document.getElementById('modal-excluir-categoria-destino');
    var btnFechar = document.getElementById('modal-excluir-categoria-fechar');
    var btnCancelar = document.getElementById('modal-excluir-categoria-cancelar');
    var btnConfirmar = document.getElementById('modal-excluir-categoria-confirmar');
    var formAlvo = null;
    var ultimoFoco = null;

    function focaveisDoModal() {
        return Array.prototype.slice.call(
            modal.querySelectorAll('button, select, [href]')
        ).filter(function (el) { return ! el.disabled && el.offsetParent !== null; });
    }

    function abrirModal(botao) {
        formAlvo = botao.closest('form');
        ultimoFoco = botao;

        nomeLabel.textContent = botao.dataset.categoriaNome;
        qtdLabel.textContent = botao.dataset.avaliacoesCount;

        // A própria categoria sendo excluída não pode ser o destino.
        Array.prototype.forEach.call(destinoSelect.options, function (opcao) {
            opcao.hidden = opcao.dataset.categoriaId === botao.dataset.categoriaId;
        });
        destinoSelect.value = '';

        modal.classList.remove('hidden');
        modal.classList.add('flex');
        destinoSelect.focus();
    }

    function fecharModal() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        formAlvo = null;
        if (ultimoFoco) {
            ultimoFoco.focus();
            ultimoFoco = null;
        }
    }

    document.querySelectorAll('.categoria-excluir-btn').forEach(function (botao) {
        botao.addEventListener('click', function () { abrirModal(botao); });
    });

    btnFechar.addEventListener('click', fecharModal);
    btnCancelar.addEventListener('click', fecharModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) fecharModal();
    });

    btnConfirmar.addEventListener('click', function () {
        if (! formAlvo) return;

        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'mover_avaliacoes_para';
        input.value = destinoSelect.value;
        formAlvo.appendChild(input);
        formAlvo.submit();
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
})();
</script>
@endsection
