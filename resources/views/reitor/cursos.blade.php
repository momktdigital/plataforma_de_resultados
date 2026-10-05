@extends('layouts.app')

@section('title', 'Análise do curso — Painel da reitoria')

@php
    $fmt = fn ($v, $casas = 1) => $v === null ? '—' : number_format($v, $casas, ',', '.');
    $porChave = $est['cursos'] ?? [];
@endphp

@section('content')
@include('reitor._cabecalho', ['ctx' => $ctx, 'aba' => 'cursos', 'chips' => [['valor' => count($cursos), 'rotulo' => 'Cursos']]])

<div class="mb-6 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
    <i class="ph-bold ph-info mr-1" aria-hidden="true"></i>
    Escolha um curso para abrir a <strong>visão do coordenador</strong> dele: painel, alunos do curso e ficha do aluno, desempenho, comparar semestres,
    avaliações e Dashboard. É <strong>somente leitura</strong>, mostra dados dos alunos do curso e cada abertura fica registrada na auditoria.
    Para voltar, use "Voltar à reitoria" no menu.
</div>

<section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5" aria-labelledby="titulo-cursos">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 id="titulo-cursos" class="text-sm font-bold uppercase tracking-wide text-[#1e3a5f]">Cursos</h2>
            @if (empty($ctx['semResultados']))
                <p class="text-xs text-slate-600 mt-1">Números de <strong>{{ $ctx['avaliacao']['nome'] }}</strong>{{ $ctx['avaliacao']['todosPeriodos'] ? ' · todos os períodos' : ($ctx['avaliacao']['periodoLetivo'] !== '' ? ' · '.$ctx['avaliacao']['periodoLetivo'] : '') }}. O recorte se escolhe na <a class="font-semibold text-emerald-700 hover:underline" href="{{ route('reitor.visao') }}">Visão institucional</a>.</p>
            @endif
        </div>
        <div class="w-full max-w-xs">
            <label class="sr-only" for="filtro-tabela-cursos">Buscar curso</label>
            <div class="relative">
                <i class="ph ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-500" aria-hidden="true"></i>
                <input id="filtro-tabela-cursos" type="search" autocomplete="off" placeholder="Buscar curso..." data-filtro-tabela="tabela-cursos" class="w-full rounded-lg border border-slate-300 py-1.5 pl-9 pr-3 text-sm">
            </div>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table id="tabela-cursos" data-ordenavel class="w-full text-sm">
            <caption class="sr-only">Cursos da instituição com participação, proficiência e média no recorte atual, e o botão para abrir a análise de cada um.</caption>
            <thead class="text-left text-[11px] font-bold uppercase tracking-wide text-slate-600">
                <tr class="border-b border-slate-200">
                    @foreach ([['Curso', 'texto', ''], ['Previstos', 'numero', 'text-right'], ['Participação', 'numero', 'text-right'], ['Proficientes', 'numero', 'text-right'], ['Média', 'numero', 'text-right']] as [$rotulo, $tipo, $alinhamento])
                        <th scope="col" data-ordem="{{ $tipo }}" class="px-3 py-2 {{ $alinhamento }}">
                            <button type="button" class="inline-flex items-center gap-1 font-bold uppercase tracking-wide hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">{{ $rotulo }} <i class="ph ph-arrows-down-up text-xs" aria-hidden="true"></i></button>
                        </th>
                    @endforeach
                    <th scope="col" class="px-3 py-2"><span class="sr-only">Ação</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($cursos as $curso)
                    @php $c = $porChave[$curso['chave']] ?? null; @endphp
                    <tr data-nome="{{ $curso['nome'] }}" class="border-b border-slate-100 hover:bg-slate-50">
                        <td data-valor="{{ $curso['nome'] }}" class="px-3 py-3 font-medium text-slate-800">{{ $curso['nome'] }}</td>
                        <td data-valor="{{ $c['previstos'] ?? '' }}" class="px-3 py-3 text-right font-mono">{{ $fmt($c['previstos'] ?? null, 0) }}</td>
                        <td data-valor="{{ $c['participacao'] ?? '' }}" class="px-3 py-3 text-right font-mono">{{ $c === null ? '—' : $fmt($c['participacao']).'%' }}</td>
                        <td data-valor="{{ $c['proficienciaPct'] ?? '' }}" class="px-3 py-3 text-right font-mono">{{ $c === null ? '—' : $fmt($c['proficienciaPct']).'%' }}</td>
                        <td data-valor="{{ $c['media'] ?? '' }}" class="px-3 py-3 text-right font-mono">{{ $c === null ? '—' : $fmt($c['media']).'%' }}</td>
                        <td class="px-3 py-3 text-right whitespace-nowrap">
                            <a href="{{ route('reitor.curso.abrir', ['curso' => $curso['chave']]) }}"
                               class="inline-flex items-center gap-2 rounded-lg bg-[#1e3a5f] px-3 py-1.5 text-sm font-semibold text-white hover:bg-[#17304f] focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                               aria-label="Abrir a análise do curso {{ $curso['nome'] }}">
                                <i class="ph-bold ph-magnifying-glass-plus" aria-hidden="true"></i> Analisar
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if (count($cursos) === 0)
        <p class="mt-3 text-sm text-slate-600">Ainda não há cursos cadastrados (importe as matrículas dos alunos).</p>
    @endif
</section>

@include('reitor._base')
@endsection
