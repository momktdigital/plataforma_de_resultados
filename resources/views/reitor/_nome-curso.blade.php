{{--
    Nome de curso que, para o REITOR, é um atalho para a análise daquele curso (a visão do coordenador — ver
    ReitorCursoController). Para os demais perfis é só o texto.
    Variáveis: $chave (NomeCurso::chave), $nome, $destino (opcional: painel|alunos|desempenho|comparativo), $extra (opcional: array de
    parâmetros, ex.: ['periodo_curso' => 5]). O período letivo em foco vai junto quando o recorte é de um semestre só.
--}}
@php
    $drill = auth('admin')->user()?->ehReitor();
    $periodoDoDrill = ! empty($ctx['avaliacao']) && empty($ctx['avaliacao']['todosPeriodos']) && ($ctx['avaliacao']['periodoLetivo'] ?? '') !== '' ? $ctx['avaliacao']['periodoLetivo'] : null;
@endphp
@if ($drill)
    <a href="{{ route('reitor.curso.abrir', array_filter(['curso' => $chave, 'destino' => $destino ?? null, 'periodo_letivo' => $periodoDoDrill] + ($extra ?? []), fn ($v) => $v !== null)) }}"
       class="hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded" title="{{ ($destino ?? null) === 'alunos' ? 'Abrir a lista de alunos desse recorte (visão do coordenador)' : 'Abrir a análise de '.$nome }}">{{ $nome }}</a>
@else
    {{ $nome }}
@endif
