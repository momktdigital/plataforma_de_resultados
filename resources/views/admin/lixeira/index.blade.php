@extends('layouts.app')

@section('title', 'Lixeira — Avaliações')

@section('content')
<h1 class="text-2xl font-bold mb-6">Lixeira</h1>

<form method="POST" id="form-bulk-avaliacoes" action="{{ route('lixeira.avaliacoes.restoreBulk') }}">
    @csrf
    <input type="hidden" name="_method" id="bulk-avaliacoes-method" value="POST">

    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-x-auto mb-8">
        <div class="px-6 py-4 border-b border-slate-100 font-semibold flex flex-wrap items-center justify-between gap-3">
            <span>Avaliações excluídas</span>
            @if ($avaliacoes->isNotEmpty())
                <div class="flex gap-2 text-xs font-medium">
                    <button type="button" id="btn-bulk-avaliacoes-restaurar"
                            class="border border-blue-300 text-blue-700 hover:bg-blue-50 rounded-lg px-3 py-1.5 disabled:opacity-40 disabled:cursor-not-allowed" disabled>
                        Restaurar selecionadas
                    </button>
                    <button type="button" id="btn-bulk-avaliacoes-excluir"
                            class="border border-red-300 text-red-600 hover:bg-red-50 rounded-lg px-3 py-1.5 disabled:opacity-40 disabled:cursor-not-allowed" disabled>
                        Excluir selecionadas definitivamente
                    </button>
                </div>
            @endif
        </div>
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-500 text-left">
                <tr>
                    @if ($avaliacoes->isNotEmpty())
                        <th class="px-4 py-3 w-8">
                            <input type="checkbox" id="selecionar-todas-avaliacoes" aria-label="Selecionar todas as avaliações">
                        </th>
                    @endif
                    <th class="px-4 py-3">Avaliação</th>
                    <th class="px-4 py-3">Excluída em</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($avaliacoes as $avaliacao)
                    <tr>
                        <td class="px-4 py-3">
                            <input type="checkbox" class="avaliacao-checkbox" value="{{ $avaliacao->codigo }}"
                                   aria-label="Selecionar avaliação #{{ $avaliacao->codigo }}">
                        </td>
                        <td class="px-4 py-3">#{{ $avaliacao->codigo }} @if($avaliacao->nome) — {{ $avaliacao->nome }} @endif</td>
                        <td class="px-4 py-3 text-slate-500">{{ $avaliacao->deleted_at->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <button type="submit" formaction="{{ route('lixeira.avaliacoes.restore', $avaliacao->codigo) }}"
                                    onclick="return submeterAcaoIndividual(this, 'POST');"
                                    class="text-blue-600 hover:underline mr-3">Restaurar</button>
                            <button type="submit" formaction="{{ route('lixeira.avaliacoes.forceDelete', $avaliacao->codigo) }}"
                                    onclick="return submeterAcaoIndividual(this, 'DELETE', 'Excluir permanentemente a avaliação #{{ $avaliacao->codigo }} e tudo que ela contém? Esta ação não pode ser desfeita.');"
                                    class="text-red-500 hover:text-red-700">Excluir definitivamente</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-slate-400">Nenhuma avaliação na lixeira.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</form>

<form method="POST" id="form-bulk-questoes" action="{{ route('lixeira.questoes.restoreBulk') }}">
    @csrf
    <input type="hidden" name="_method" id="bulk-questoes-method" value="POST">

    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-x-auto">
        <div class="px-6 py-4 border-b border-slate-100 font-semibold flex flex-wrap items-center justify-between gap-3">
            <span>Questões excluídas</span>
            @if ($questoes->isNotEmpty())
                <div class="flex gap-2 text-xs font-medium">
                    <button type="button" id="btn-bulk-questoes-restaurar"
                            class="border border-blue-300 text-blue-700 hover:bg-blue-50 rounded-lg px-3 py-1.5 disabled:opacity-40 disabled:cursor-not-allowed" disabled>
                        Restaurar selecionadas
                    </button>
                    <button type="button" id="btn-bulk-questoes-excluir"
                            class="border border-red-300 text-red-600 hover:bg-red-50 rounded-lg px-3 py-1.5 disabled:opacity-40 disabled:cursor-not-allowed" disabled>
                        Excluir selecionadas definitivamente
                    </button>
                </div>
            @endif
        </div>
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-500 text-left">
                <tr>
                    @if ($questoes->isNotEmpty())
                        <th class="px-4 py-3 w-8">
                            <input type="checkbox" id="selecionar-todas-questoes" aria-label="Selecionar todas as questões">
                        </th>
                    @endif
                    <th class="px-4 py-3">Avaliação</th>
                    <th class="px-4 py-3">Questão</th>
                    <th class="px-4 py-3">Excluída em</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($questoes as $questao)
                    <tr>
                        <td class="px-4 py-3">
                            <input type="checkbox" class="questao-checkbox" value="{{ $questao->id }}"
                                   aria-label="Selecionar questão {{ $questao->numero }} da avaliação #{{ $questao->avaliacao->codigo }}">
                        </td>
                        <td class="px-4 py-3">#{{ $questao->avaliacao->codigo }} @if($questao->avaliacao->nome) — {{ $questao->avaliacao->nome }} @endif</td>
                        <td class="px-4 py-3 font-mono">Q{{ $questao->numero }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $questao->deleted_at->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <button type="submit" formaction="{{ route('lixeira.questoes.restore', $questao->id) }}"
                                    onclick="return submeterAcaoIndividual(this, 'POST');"
                                    class="text-blue-600 hover:underline mr-3">Restaurar</button>
                            <button type="submit" formaction="{{ route('lixeira.questoes.forceDelete', $questao->id) }}"
                                    onclick="return submeterAcaoIndividual(this, 'DELETE', 'Excluir permanentemente esta questão?');"
                                    class="text-red-500 hover:text-red-700">Excluir definitivamente</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-slate-400">Nenhuma questão na lixeira.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</form>

<script>
// As ações individuais (Restaurar/Excluir) compartilham o MESMO <form> das
// caixinhas de seleção em lote (não dá pra aninhar <form> dentro de <form>
// em HTML) — então cada botão precisa setar o _method certo pra própria
// submissão antes de disparar, senão herdaria o valor deixado por uma ação
// em lote anterior na mesma página (ex.: excluir em lote deixa _method
// como DELETE, e um "Restaurar" individual clicado depois — sem reload de
// por meio — precisa voltar pra POST).
function submeterAcaoIndividual(botao, metodo, confirmacao) {
    if (confirmacao && ! confirm(confirmacao)) return false;

    var form = botao.closest('form');
    form.querySelectorAll('input[name="ids[]"]').forEach(function (el) { el.remove(); });
    form.querySelector('input[name="_method"]').value = metodo;

    return true;
}

(function () {
    function configurarSecao(prefixo, checkboxSeletor, selecionarTodosId, form, campoMethod, rotaRestaurar, rotaExcluir) {
        var selecionarTodos = document.getElementById(selecionarTodosId);
        var btnRestaurar = document.getElementById('btn-bulk-' + prefixo + '-restaurar');
        var btnExcluir = document.getElementById('btn-bulk-' + prefixo + '-excluir');

        function checkboxes() {
            return Array.prototype.slice.call(document.querySelectorAll(checkboxSeletor));
        }

        function atualizarBotoes() {
            var marcados = checkboxes().filter(function (cb) { return cb.checked; }).length;
            if (btnRestaurar) btnRestaurar.disabled = marcados === 0;
            if (btnExcluir) btnExcluir.disabled = marcados === 0;
        }

        if (selecionarTodos) {
            selecionarTodos.addEventListener('change', function () {
                checkboxes().forEach(function (cb) { cb.checked = selecionarTodos.checked; });
                atualizarBotoes();
            });
        }

        checkboxes().forEach(function (cb) {
            cb.addEventListener('change', atualizarBotoes);
        });

        function submeter(rota, metodo, confirmacao) {
            var marcados = checkboxes().filter(function (cb) { return cb.checked; });
            if (marcados.length === 0) return;
            if (confirmacao && ! confirm(confirmacao.replace('__N__', marcados.length))) return;

            // Remove seleções antigas antes de reinjetar — evita duplicar
            // hidden inputs se o usuário mudar a seleção e clicar de novo.
            form.querySelectorAll('input[name="ids[]"]').forEach(function (el) { el.remove(); });
            marcados.forEach(function (cb) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'ids[]';
                input.value = cb.value;
                form.appendChild(input);
            });

            form.action = rota;
            campoMethod.value = metodo;
            form.submit();
        }

        if (btnRestaurar) {
            btnRestaurar.addEventListener('click', function () {
                submeter(rotaRestaurar, 'POST', null);
            });
        }
        if (btnExcluir) {
            btnExcluir.addEventListener('click', function () {
                submeter(rotaExcluir, 'DELETE', 'Excluir __N__ item(ns) selecionado(s) permanentemente? Esta ação não pode ser desfeita.');
            });
        }
    }

    configurarSecao(
        'avaliacoes', '.avaliacao-checkbox', 'selecionar-todas-avaliacoes',
        document.getElementById('form-bulk-avaliacoes'), document.getElementById('bulk-avaliacoes-method'),
        '{{ route('lixeira.avaliacoes.restoreBulk') }}', '{{ route('lixeira.avaliacoes.forceDeleteBulk') }}'
    );
    configurarSecao(
        'questoes', '.questao-checkbox', 'selecionar-todas-questoes',
        document.getElementById('form-bulk-questoes'), document.getElementById('bulk-questoes-method'),
        '{{ route('lixeira.questoes.restoreBulk') }}', '{{ route('lixeira.questoes.forceDeleteBulk') }}'
    );
})();
</script>
@endsection
