{{--
    Seletores de curso (só com mais de um curso) e semestre do painel do coordenador. Vão DENTRO de um <form method="GET">
    da tela. Variáveis: $ctx (como em _cabecalho) e $autoEnviar (envia o formulário ao trocar).
--}}
@php $enviar = ! empty($autoEnviar) ? 'onchange="this.form.submit()"' : ''; @endphp
@if (count($ctx['meusCursos'] ?? []) > 1)
    <div>
        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1" for="seletor-curso">Curso</label>
        <select id="seletor-curso" name="curso" {!! $enviar !!}
                class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white min-w-[180px] max-w-full">
            <option value="" {{ $ctx['cursoSelecionado'] === '' ? 'selected' : '' }}>Todos os meus cursos</option>
            @foreach ($ctx['meusCursos'] as $c)
                <option value="{{ $c }}" {{ $ctx['cursoSelecionado'] === $c ? 'selected' : '' }}>{{ $c }}</option>
            @endforeach
        </select>
    </div>
@endif
<div>
    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1" for="periodo-letivo">Período letivo</label>
    <select id="periodo-letivo" name="periodo_letivo" {!! $enviar !!}
            class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white min-w-[140px]">
        <option value="" {{ $ctx['periodoSelecionado'] === '' ? 'selected' : '' }}>Todos</option>
        @foreach ($ctx['periodosDisponiveis'] as $p)
            <option value="{{ $p }}" {{ $ctx['periodoSelecionado'] === $p ? 'selected' : '' }}>{{ $p }}</option>
        @endforeach
    </select>
</div>
