@extends('layouts.app')

@section('title', "Dashboard — Avaliação #{$avaliacao->codigo}")

@section('content')
@include('admin.avaliacoes.bi._cabecalho')

@include('admin.avaliacoes.bi._filtros')

@include('admin.avaliacoes.bi._avisos')

@include('admin.avaliacoes.bi._estatisticas-gerais')

@include('admin.avaliacoes.bi._comparacao-avaliacoes')

@include('admin.avaliacoes.bi._mapa-itens')

@include('admin.avaliacoes.bi._histograma')

@include('admin.avaliacoes.bi._radar-disciplina')

@include('admin.avaliacoes.bi._lista-alunos')

@include('admin.avaliacoes.bi._distribuicao-turma')

@include('admin.avaliacoes.bi._curva-dificuldade')

@include('admin.avaliacoes.bi._dispersao-tri')

@include('admin.avaliacoes.bi._heatmap-habilidade')

@include('admin.avaliacoes.bi._perfil-e-equidade')

@include('admin.avaliacoes.bi._desempenho-area')

@include('admin.avaliacoes.bi._desempenho-tema')

@include('admin.avaliacoes.bi._bloom-miller')

@include('admin.avaliacoes.bi._analise-alternativas')

@include('admin.avaliacoes.bi._correlacao-metricas')

@include('admin.avaliacoes.bi._evolucao-categoria')

@include('admin.avaliacoes.bi._alinhamento-referencias')

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1"></script>
@include('_viz')
@include('partials.linha-desempenho')

@include('admin.avaliacoes.bi.scripts._mapa-itens')
<script>
@include('admin.avaliacoes.bi.scripts._comparacao')
@include('admin.avaliacoes.bi.scripts._histograma-radar')
@include('admin.avaliacoes.bi.scripts._area')
@include('admin.avaliacoes.bi.scripts._turma')
@include('admin.avaliacoes.bi.scripts._tri')
@include('admin.avaliacoes.bi.scripts._bloom-miller')
@include('admin.avaliacoes.bi.scripts._perfil-demografico')
@include('admin.avaliacoes.bi.scripts._evolucao')
@include('admin.avaliacoes.bi.scripts._ordenacao-alternativas')
</script>
@include('admin.avaliacoes.bi.scripts._lista-alunos')
@endsection
