@extends('layouts.app')

@section('title', 'Importar dados legados — Avaliações')

@section('content')
<h1 class="text-2xl font-bold mb-6">Configurações do sistema</h1>

@include('admin.sistema._subnav')

<h2 class="text-lg font-semibold mb-4">Importar dados do sistema legado</h2>

<div class="grid sm:grid-cols-2 gap-6">
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
        <h2 class="font-semibold mb-1">Direto do banco compartilhado</h2>
        <p class="text-sm text-slate-500 mb-4">
            Use esta opção quando esta aplicação está configurada no <strong>mesmo banco</strong> do sistema legado.
            @if ($bancoCompartilhadoDisponivel)
                As tabelas <code>gabaritos</code>/<code>resultados</code> foram encontradas.
            @else
                <span class="text-amber-700 font-semibold">As tabelas <code>gabaritos</code>/<code>resultados</code> não foram encontradas neste banco.</span>
            @endif
        </p>
        <form method="POST" action="{{ route('sistema.legado.banco') }}"
              onsubmit="return confirm('Importar todos os gabaritos e resultados encontrados no banco compartilhado?');">
            @csrf
            <button type="submit" @disabled(! $bancoCompartilhadoDisponivel)
                    class="bg-emerald-600 hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed text-white font-semibold rounded-lg px-5 py-2 text-sm">
                Importar do banco
            </button>
        </form>
    </div>

    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
        <h2 class="font-semibold mb-1">De um arquivo de backup (.sql)</h2>
        <p class="text-sm text-slate-500 mb-4">
            Use esta opção quando o sistema legado está em <strong>outro servidor</strong> — envie o arquivo gerado
            em "Backup Manual" no painel antigo. Só as tabelas <code>gabaritos</code> e <code>resultados</code> são
            lidas do arquivo; o resto é ignorado, e o SQL do arquivo <strong>nunca é executado</strong> — só
            interpretado como dados.
        </p>
        <form method="POST" action="{{ route('sistema.legado.arquivo') }}" enctype="multipart/form-data" class="space-y-3">
            @csrf
            <input name="arquivo" type="file" accept=".sql,.txt" required class="w-full text-sm">
            <p class="text-xs text-slate-500">
                Este servidor aceita até <strong>{{ $limiteUploadMb }} MB</strong> por upload
                (<code>post_max_size</code>/<code>upload_max_filesize</code> do PHP). Arquivo maior que isso? Aumente
                esses limites no <code>php.ini</code> do servidor antes de enviar.
            </p>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="dry_run" value="1">
                Simular (mostra os números sem gravar nada)
            </label>
            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-5 py-2 text-sm">
                Importar arquivo
            </button>
        </form>
    </div>
</div>

<div class="mt-6 max-w-3xl bg-blue-50 border border-blue-200 rounded-xl p-5 text-sm text-blue-900">
    <p>Nas duas opções: uma Avaliação é criada (ou reaproveitada) por avaliação distinta, gabaritos viram Questões,
    resultados viram Respostas + Métricas, e registros já excluídos na lixeira do sistema antigo são preservados
    como excluídos aqui. É seguro repetir a importação — dados existentes são atualizados, nunca duplicados.</p>
</div>

<h2 class="text-lg font-semibold mb-4 mt-10">Depois de migrar: excluir as tabelas antigas</h2>

@if (empty($tabelasLegadasLinhas))
    <div class="max-w-3xl bg-slate-50 border border-slate-200 rounded-xl p-5 text-sm text-slate-600">
        Nenhuma tabela legada (<code>gabaritos</code>/<code>resultados</code>) encontrada neste banco — não há nada a excluir.
    </div>
@else
    <div class="max-w-3xl bg-white border border-slate-200 rounded-xl shadow-sm p-6">
        <p class="text-sm text-slate-600 mb-4">
            Depois de confirmar que a importação acima já trouxe tudo pro schema novo, você pode excluir as
            tabelas antigas do banco. <strong class="text-red-600">Esta ação é irreversível</strong> — recomendamos
            gerar um <a href="{{ route('sistema.backups.index') }}" class="underline">backup completo</a> antes.
        </p>
        <ul class="text-sm text-slate-700 mb-4 space-y-1">
            @foreach ($tabelasLegadasLinhas as $tabela => $linhas)
                <li>Tabela <code>{{ $tabela }}</code>: <strong>{{ $linhas }}</strong> linha(s).</li>
            @endforeach
            <li>Avaliações já no schema novo: <strong>{{ $avaliacoesJaMigradas }}</strong>.</li>
        </ul>

        @if ($avaliacoesJaMigradas === 0)
            <p class="text-sm text-amber-700 font-semibold mb-4">
                Nenhuma Avaliação encontrada no schema novo ainda — a exclusão fica bloqueada até que a importação
                acima seja executada, para não perder os únicos dados existentes.
            </p>
        @endif

        <form method="POST" action="{{ route('sistema.legado.tabelas.destroy') }}"
              onsubmit="return confirm('Excluir permanentemente {{ implode(', ', array_keys($tabelasLegadasLinhas)) }}? Esta ação não pode ser desfeita.');"
              class="space-y-3">
            @csrf
            @method('DELETE')
            <div>
                <label class="block text-sm font-medium mb-1" for="confirmacao">
                    Digite <code>EXCLUIR</code> para confirmar
                </label>
                <input id="confirmacao" name="confirmacao" type="text" autocomplete="off"
                       class="w-full max-w-xs rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
                @error('confirmacao')
                    <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" @disabled($avaliacoesJaMigradas === 0)
                    class="bg-red-600 hover:bg-red-700 disabled:bg-slate-300 disabled:cursor-not-allowed text-white font-semibold rounded-lg px-5 py-2 text-sm">
                Excluir tabelas legadas
            </button>
        </form>
    </div>
@endif
@endsection
