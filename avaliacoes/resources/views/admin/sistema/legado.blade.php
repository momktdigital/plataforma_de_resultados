@extends('layouts.app')

@section('title', 'Importar dados legados — Avaliações')

@section('content')
<h1 class="text-2xl font-bold mb-6">Importar dados do sistema legado</h1>

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
    <p>Nas duas opções: uma Prova é criada (ou reaproveitada) por avaliação distinta, gabaritos viram Questões,
    resultados viram Respostas + Métricas, e registros já excluídos na lixeira do sistema antigo são preservados
    como excluídos aqui. É seguro repetir a importação — dados existentes são atualizados, nunca duplicados.</p>
</div>
@endsection
