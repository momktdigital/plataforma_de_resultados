@extends('layouts.app')

@section('title', 'Painel da reitoria')

@section('content')
@include('reitor._cabecalho', ['ctx' => $ctx, 'aba' => $aba])

<div class="bg-slate-50 border border-slate-200 text-slate-600 rounded-xl p-6 text-sm">
    <p class="font-semibold text-slate-700 mb-1">Ainda não há resultados para o painel.</p>
    Os indicadores aparecem assim que houver uma avaliação com resultados importados e alunos com curso conhecido
    (importação de matrículas). Peça a um administrador para importar os resultados.
</div>
@endsection
