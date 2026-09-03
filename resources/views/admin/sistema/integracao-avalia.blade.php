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
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-slate-400">Nenhuma sincronização registrada ainda.</td>
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
</script>
@endsection
