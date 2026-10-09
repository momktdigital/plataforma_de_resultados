{{--
    Registro de pendências em tabela (somente leitura). Variáveis: $pendencias (coleção ou paginador), $rotaItem (rota que
    abre a atividade), $mostrarCurso (bool, padrão true).
--}}
@php $mostrarCurso = $mostrarCurso ?? true; @endphp
<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <caption class="sr-only">Registro de pendências</caption>
        <thead class="bg-slate-50 text-left text-slate-600">
            <tr>
                <th scope="col" class="px-4 py-3 font-semibold">Registro</th>
                @if ($mostrarCurso)<th scope="col" class="px-4 py-3 font-semibold">Curso</th>@endif
                <th scope="col" class="px-4 py-3 font-semibold">Atividade</th>
                <th scope="col" class="px-4 py-3 font-semibold">Pendência</th>
                <th scope="col" class="px-4 py-3 font-semibold">Encaminhamento</th>
                <th scope="col" class="px-4 py-3 font-semibold">Prazo</th>
                <th scope="col" class="px-4 py-3 font-semibold">Responsável</th>
                <th scope="col" class="px-4 py-3 font-semibold">Situação</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($pendencias as $p)
                <tr class="align-top">
                    <td class="px-4 py-3 whitespace-nowrap text-slate-600">{{ $p->data->format('d/m/Y') }}</td>
                    @if ($mostrarCurso)<td class="px-4 py-3 font-medium">{{ $p->curso }}</td>@endif
                    <td class="px-4 py-3">
                        <a href="{{ route($rotaItem, $p->item_id) }}" class="font-semibold text-emerald-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">
                            {{ $p->item->rotina }} · {{ $p->item->projeto }}
                        </a>
                    </td>
                    <td class="px-4 py-3 min-w-[14rem] whitespace-pre-line">{{ $p->pendencia }}</td>
                    <td class="px-4 py-3 min-w-[12rem] whitespace-pre-line text-slate-700">{{ $p->encaminhamento ?: '—' }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        @if ($p->prazo)
                            {{ $p->prazo->format('d/m/Y') }}
                            @if ($p->estaAtrasada())<span class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700">Atrasada</span>@endif
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-4 py-3 text-slate-700">{{ $p->responsavel ?: '—' }}</td>
                    <td class="px-4 py-3">@include('cronograma._status', ['status' => $p->status])</td>
                </tr>
            @empty
                <tr><td colspan="{{ $mostrarCurso ? 8 : 7 }}" class="px-4 py-8 text-center text-slate-500">{{ $vazio ?? 'Nenhuma pendência registrada.' }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
