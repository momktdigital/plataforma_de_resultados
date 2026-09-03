@extends('layouts.app')

@section('title', 'Integração Avalia — Avaliações')

@section('content')
<h1 class="text-2xl font-bold mb-6">Configurações do sistema</h1>

@include('admin.sistema._subnav')

<h2 class="text-lg font-semibold mb-4">Integração Avalia</h2>

<p class="text-sm text-slate-500 mb-6 max-w-2xl">
    Sincroniza avaliações, questões e resultados do Avalia (+A Data / Redshift) automaticamente a cada 12h.
    Avaliações sincronizadas por aqui não podem ser editadas manualmente — o Avalia é a fonte da verdade delas.
</p>

@unless ($conexaoConfigurada)
    <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 text-sm max-w-2xl">
        <p class="font-semibold mb-1">Conexão com o Redshift não configurada.</p>
        <p>Defina <code class="bg-amber-100 px-1 rounded">REDSHIFT_HOST</code> e as demais variáveis <code class="bg-amber-100 px-1 rounded">REDSHIFT_*</code> no <code class="bg-amber-100 px-1 rounded">.env</code> do servidor antes de testar a conexão ou sincronizar.</p>
    </div>
@endunless

{{-- Card de status da conexão --}}
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 max-w-2xl mb-6">
    <div class="flex items-center justify-between mb-4">
        <h3 class="font-semibold">Conexão com o Redshift</h3>
        <button type="button" id="btn-testar-conexao"
                class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold rounded-lg px-4 py-2 text-sm">
            Testar conexão
        </button>
    </div>
    <div id="resultado-teste-conexao" class="text-sm" style="display:none"></div>

    <form method="POST" action="{{ route('sistema.integracao-avalia.configuracoes') }}" class="space-y-3 mt-4 pt-4 border-t border-slate-100">
        @csrf
        @method('PUT')
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium mb-1" for="avalia_tenant_sk">Tenant (tenant_sk)</label>
                <input id="avalia_tenant_sk" name="avalia_tenant_sk" type="text" value="{{ old('avalia_tenant_sk', $tenantSk) }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" for="avalia_environment_sk">Ambiente(s) (environment_sk)</label>
                <input id="avalia_environment_sk" name="avalia_environment_sk" type="text" value="{{ old('avalia_environment_sk', $environmentSk) }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono">
            </div>
        </div>
        <p class="text-xs text-slate-400">
            Identificam a instituição dentro do Data Warehouse do Avalia (compartilhado entre clientes) — confirme os valores corretos com o suporte do Avalia antes de sincronizar.
            Um campus/polo por ambiente: se houver mais de um (ex.: sede + polo Arcoverde), separe os <code class="bg-slate-100 px-1 rounded">environment_sk</code> por vírgula.
        </p>
        <button type="submit" class="bg-slate-700 hover:bg-slate-800 text-white font-semibold rounded-lg px-4 py-2 text-sm">
            Salvar
        </button>
    </form>
</div>

{{-- Card de sincronização por produto --}}
<div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-2xl mb-6">
    @foreach ($produtos as $produto)
        @php $ultima = $ultimaPorProduto[$produto]; @endphp
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-semibold">{{ $produto === 'avalia_pro' ? 'Avalia Pro' : 'Avalia Online' }}</h3>
                @if ($ultima && $ultima->status === 'processando')
                    <span class="inline-flex items-center gap-2 text-xs text-slate-500 font-medium">
                        <i class="ph ph-spinner animate-spin"></i> Sincronizando&hellip;
                    </span>
                @endif
            </div>

            @php $modo = $modoPorProduto[$produto]; $selecionadasCount = $selecionadasCountPorProduto[$produto]; @endphp
            <p class="text-xs text-slate-500 mb-3">
                @if ($modo === 'todas')
                    Modo: <span class="font-semibold text-slate-700">todas as provas</span>
                @else
                    Modo: <span class="font-semibold text-slate-700">só as selecionadas</span> ({{ $selecionadasCount }} escolhida(s))
                    @if ($selecionadasCount === 0)
                        <span class="text-amber-700">— nada será sincronizado até escolher alguma abaixo</span>
                    @endif
                @endif
            </p>

            @if ($ultima === null)
                <p class="text-sm text-slate-400 mb-4">Nunca sincronizado.</p>
            @else
                <p class="text-sm text-slate-500 mb-1">Última execução</p>
                <p class="text-sm font-medium mb-3">
                    {{ $ultima->iniciado_em->format('d/m/Y H:i') }} —
                    @if ($ultima->status === 'sucesso')
                        <span class="text-emerald-700">sucesso</span> ({{ $ultima->linhas_gravadas ?? 0 }} linha(s) gravada(s))
                    @elseif ($ultima->status === 'erro')
                        <span class="text-red-700">falhou</span>
                    @else
                        <span class="text-slate-500">em andamento</span>
                    @endif
                </p>
                @if ($ultima->status === 'erro' && $ultima->mensagem_erro)
                    <p class="text-xs text-red-700 bg-red-50 border border-red-200 rounded px-2 py-1 mb-3">{{ $ultima->mensagem_erro }}</p>
                @endif
            @endif

            <form method="POST" action="{{ route('sistema.integracao-avalia.store') }}">
                @csrf
                <input type="hidden" name="produto" value="{{ $produto }}">
                <button type="submit"
                        {{ $ultima && $ultima->status === 'processando' ? 'disabled' : '' }}
                        class="bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-semibold rounded-lg px-4 py-2 text-sm">
                    Forçar sincronização agora
                </button>
            </form>
        </div>
    @endforeach
</div>

{{-- Seleção de provas a sincronizar --}}
<div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-4xl mb-6">
    @foreach ($produtos as $produto)
        @php $catalogo = $catalogoPorProduto[$produto]; $modo = $modoPorProduto[$produto]; @endphp
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-semibold">Provas — {{ $produto === 'avalia_pro' ? 'Avalia Pro' : 'Avalia Online' }}</h3>
                <form method="POST" action="{{ route('sistema.integracao-avalia.catalogo') }}">
                    @csrf
                    <input type="hidden" name="produto" value="{{ $produto }}">
                    <button type="submit" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold rounded-lg px-3 py-1.5 text-xs">
                        Atualizar lista de provas disponíveis
                    </button>
                </form>
            </div>

            @if ($catalogo->isEmpty())
                <p class="text-sm text-slate-400 mb-2">Nenhuma prova listada ainda — clique em "Atualizar lista" acima.</p>
            @else
                <form method="POST" action="{{ route('sistema.integracao-avalia.selecao') }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="produto" value="{{ $produto }}">

                    <div class="flex gap-4 mb-3 text-sm">
                        <label class="flex items-center gap-1.5">
                            <input type="radio" name="modo" value="selecionadas" {{ $modo !== 'todas' ? 'checked' : '' }}>
                            Só as selecionadas
                        </label>
                        <label class="flex items-center gap-1.5">
                            <input type="radio" name="modo" value="todas" {{ $modo === 'todas' ? 'checked' : '' }}>
                            Todas
                        </label>
                    </div>

                    <input type="text" id="busca-{{ $produto }}" placeholder="Buscar prova, curso ou disciplina..."
                           class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm mb-2">

                    <div id="arvore-{{ $produto }}" class="max-h-72 overflow-y-auto border border-slate-100 rounded-lg mb-3">
                        @foreach ($catalogo as $prova)
                            @php $temFilhos = $prova->disciplinasPorCurso->isNotEmpty(); @endphp
                            <details class="js-prova border-b border-slate-100 last:border-b-0" data-search="{{ Str::lower($prova->nome ?? $prova->id_externo) }}" {{ $temFilhos ? '' : 'open' }}>
                                <summary class="flex items-center gap-2 px-3 py-2 text-sm cursor-pointer hover:bg-slate-50 select-none">
                                    @if ($temFilhos)
                                        <input type="checkbox" class="js-check-prova" onclick="event.stopPropagation()">
                                    @else
                                        <input type="checkbox" name="selecionadas[]" value="{{ $prova->id }}" {{ $prova->selecionada ? 'checked' : '' }} onclick="event.stopPropagation()">
                                    @endif
                                    <span class="flex-1 font-medium">{{ $prova->nome ?? $prova->id_externo }}</span>
                                    @if ($prova->data_referencia)
                                        <span class="text-xs text-slate-400">{{ $prova->data_referencia->format('d/m/Y') }}</span>
                                    @endif
                                </summary>

                                @if ($temFilhos)
                                    <div class="pl-6 js-cursos">
                                        @foreach ($prova->disciplinasPorCurso as $curso => $disciplinas)
                                            <details class="js-curso border-t border-slate-50" data-search="{{ Str::lower($curso) }}">
                                                <summary class="flex items-center gap-2 px-3 py-1.5 text-sm cursor-pointer hover:bg-slate-50 select-none">
                                                    <input type="checkbox" class="js-check-curso" onclick="event.stopPropagation()">
                                                    <span class="flex-1 text-slate-600">{{ $curso }} <span class="text-xs text-slate-400">({{ $disciplinas->count() }})</span></span>
                                                </summary>
                                                <div class="pl-6 js-leaves">
                                                    @foreach ($disciplinas as $disciplina)
                                                        <label class="js-leaf flex items-center gap-2 px-3 py-1.5 text-sm hover:bg-slate-50" data-search="{{ Str::lower($disciplina->nome ?? $disciplina->id_externo) }}">
                                                            <input type="checkbox" name="selecionadas[]" value="{{ $disciplina->id }}" {{ $disciplina->selecionada ? 'checked' : '' }}>
                                                            <span>{{ $disciplina->nome ?? $disciplina->id_externo }}</span>
                                                        </label>
                                                    @endforeach
                                                </div>
                                            </details>
                                        @endforeach
                                    </div>
                                @endif
                            </details>
                        @endforeach
                    </div>

                    <button type="submit" class="bg-slate-700 hover:bg-slate-800 text-white font-semibold rounded-lg px-4 py-2 text-sm">
                        Salvar seleção
                    </button>
                </form>
            @endif
        </div>
    @endforeach
</div>

{{-- Histórico --}}
<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-x-auto max-w-4xl">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-500 text-left">
            <tr>
                <th class="px-4 py-3">Produto</th>
                <th class="px-4 py-3">Disparado por</th>
                <th class="px-4 py-3">Início</th>
                <th class="px-4 py-3">Duração</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">Linhas</th>
                <th class="px-4 py-3">Sem identificador</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($execucoes as $execucao)
                <tr>
                    <td class="px-4 py-3">{{ $execucao->produto === 'avalia_pro' ? 'Avalia Pro' : 'Avalia Online' }}</td>
                    <td class="px-4 py-3 text-slate-500">
                        {{ $execucao->disparado_por === 'manual' ? 'Manual' : 'Agendado' }}
                        @if ($execucao->admin)
                            <span class="text-xs">({{ $execucao->admin->username }})</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-slate-500">{{ $execucao->iniciado_em->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-3 text-slate-500">
                        {{ $execucao->concluido_em ? $execucao->iniciado_em->diffForHumans($execucao->concluido_em, true) : '—' }}
                    </td>
                    <td class="px-4 py-3">
                        @if ($execucao->status === 'sucesso')
                            <span class="text-emerald-700 font-medium">Sucesso</span>
                        @elseif ($execucao->status === 'erro')
                            <span class="text-red-700 font-medium" title="{{ $execucao->mensagem_erro }}">Erro</span>
                        @else
                            <span class="text-slate-500 font-medium">Em andamento</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-slate-500">{{ $execucao->linhas_gravadas ?? '—' }} / {{ $execucao->linhas_lidas ?? '—' }}</td>
                    <td class="px-4 py-3">
                        @if ($execucao->linhas_sem_identificador)
                            <span class="text-amber-700 font-medium" title="Linhas do Avalia sem CPF cadastrado — não puderam ser vinculadas a um aluno.">
                                {{ $execucao->linhas_sem_identificador }}
                            </span>
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-4 py-8 text-center text-slate-400">Nenhuma sincronização registrada ainda.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<script>
    document.getElementById('btn-testar-conexao').addEventListener('click', async function () {
        const botao = this;
        const resultado = document.getElementById('resultado-teste-conexao');

        botao.disabled = true;
        botao.textContent = 'Testando...';
        resultado.style.display = 'none';

        try {
            const res = await fetch('{{ route('sistema.integracao-avalia.testar-conexao') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            });
            const json = await res.json();

            resultado.style.display = 'block';
            if (json.status === 'success') {
                resultado.className = 'text-sm text-emerald-700';
                resultado.textContent = 'Conexão bem-sucedida.';
            } else {
                resultado.className = 'text-sm text-red-700';
                resultado.textContent = 'Falha: ' + json.message;
            }
        } catch (e) {
            resultado.style.display = 'block';
            resultado.className = 'text-sm text-red-700';
            resultado.textContent = 'Falha ao testar a conexão: ' + e.message;
        } finally {
            botao.disabled = false;
            botao.textContent = 'Testar conexão';
        }
    });

    @if (collect($ultimaPorProduto)->contains(fn ($e) => $e && $e->status === 'processando'))
        setTimeout(function () { window.location.reload(); }, 5000);
    @endif

    // Marcar a prova ou o curso propaga a marcação pros checkboxes reais
    // (as disciplinas) dentro dela — a prova/curso em si nunca é enviada no
    // formulário (só as disciplinas têm `name="selecionadas[]"`; quando uma
    // prova não tem disciplinas — caso do Avalia Online — ela própria é a
    // folha marcável).
    document.querySelectorAll('.js-check-prova').forEach(function (cb) {
        cb.addEventListener('change', function () {
            cb.closest('.js-prova').querySelectorAll('input[type=checkbox]').forEach(function (filho) {
                if (filho !== cb) filho.checked = cb.checked;
            });
        });
    });
    document.querySelectorAll('.js-check-curso').forEach(function (cb) {
        cb.addEventListener('change', function () {
            cb.closest('.js-curso').querySelectorAll('input[type=checkbox]').forEach(function (filho) {
                if (filho !== cb) filho.checked = cb.checked;
            });
        });
    });

    // Busca: filtra por prova, curso ou disciplina — sem servidor, a lista
    // inteira já está na página. Casa acento/maiúscula de forma tolerante
    // (normalize + remove diacríticos) porque os nomes vêm cheios de
    // acentuação ("Nutrição", "Estética").
    function normalizarBusca(texto) {
        return (texto || '').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    function configurarBuscaDeProvas(produto) {
        const input = document.getElementById('busca-' + produto);
        const raiz = document.getElementById('arvore-' + produto);
        if (!input || !raiz) return;

        input.addEventListener('input', function () {
            const termo = normalizarBusca(input.value);

            raiz.querySelectorAll(':scope > .js-prova').forEach(function (prova) {
                const provaCasa = termo === '' || normalizarBusca(prova.dataset.search).includes(termo);
                const semFilhos = prova.querySelectorAll('.js-leaf').length === 0;
                let algumCursoVisivel = false;

                prova.querySelectorAll(':scope > .js-cursos > .js-curso').forEach(function (curso) {
                    const cursoCasa = provaCasa || termo === '' || normalizarBusca(curso.dataset.search).includes(termo);
                    let algumLeafVisivel = false;

                    curso.querySelectorAll(':scope > .js-leaves > .js-leaf').forEach(function (leaf) {
                        const visivel = cursoCasa || normalizarBusca(leaf.dataset.search).includes(termo);
                        leaf.style.display = visivel ? '' : 'none';
                        if (visivel) algumLeafVisivel = true;
                    });

                    curso.style.display = algumLeafVisivel ? '' : 'none';
                    curso.open = termo !== '' && algumLeafVisivel;
                    if (algumLeafVisivel) algumCursoVisivel = true;
                });

                const visivel = semFilhos ? provaCasa : (provaCasa || algumCursoVisivel);
                prova.style.display = visivel ? '' : 'none';
                prova.open = termo === '' ? semFilhos : (visivel && !semFilhos);
            });
        });
    }

    configurarBuscaDeProvas('avalia_pro');
    configurarBuscaDeProvas('avalia_online');
</script>
@endsection
