{{--
    As atividades em lista (da data mais antiga para a mais recente), com a situação de cada curso e quantas pendências estão
    abertas. Variáveis: $itens (paginador), $rotaItem (rota que abre a atividade), $escopo (cursos do coordenador; null = todos).
--}}
@php
    $escopo = $escopo ?? null;
    $hoje = today();
@endphp
<section class="bg-white border border-slate-200 rounded-xl shadow-sm" aria-labelledby="titulo-lista">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-3">
        <h2 id="titulo-lista" class="text-lg font-black flex items-center gap-2"><i class="ph-bold ph-list-bullets text-slate-600" aria-hidden="true"></i> Atividades
            <span class="text-sm font-medium text-slate-500">{{ $itens->total() }} {{ $itens->total() === 1 ? 'encontrada' : 'encontradas' }}</span>
        </h2>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <caption class="sr-only">Atividades do cronograma</caption>
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th scope="col" class="px-4 py-3 font-semibold">Data</th>
                    <th scope="col" class="px-4 py-3 font-semibold">Rotina</th>
                    <th scope="col" class="px-4 py-3 font-semibold">Atividade</th>
                    <th scope="col" class="px-4 py-3 font-semibold">{{ $escopo === null ? 'Situação nos cursos' : 'Situação' }}</th>
                    <th scope="col" class="px-4 py-3 font-semibold">Pendências abertas</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($itens as $item)
                    @php
                        $cursos = $item->cursosDe($escopo);
                        $porStatus = $cursos->groupBy('status');
                        $cor = match ($item->rotina) { 'ROD' => 'bg-sky-100 text-sky-800', 'ROC' => 'bg-violet-100 text-violet-800', default => 'bg-amber-100 text-amber-800' };
                    @endphp
                    <tr class="align-top {{ $item->data->isSameDay($hoje) ? 'bg-emerald-50/50' : '' }}">
                        <td class="px-4 py-3 whitespace-nowrap">
                            <span class="font-semibold">{{ $item->data->format('d/m/Y') }}</span>
                            <span class="block text-xs text-slate-500">{{ $item->data->locale('pt_BR')->translatedFormat('l') }}{{ $item->data->isSameDay($hoje) ? ' · hoje' : '' }}</span>
                        </td>
                        <td class="px-4 py-3"><span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-bold {{ $cor }}">{{ $item->rotina }}</span></td>
                        <td class="px-4 py-3 min-w-[16rem]">
                            <a href="{{ route($rotaItem, $item) }}" class="font-semibold text-emerald-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">{{ $item->projeto }}</a>
                            <p class="text-slate-700">{{ $item->descricao }}</p>
                        </td>
                        <td class="px-4 py-3 min-w-[14rem]">
                            @if ($cursos->count() <= 3)
                                <ul class="space-y-1">
                                    @foreach ($cursos as $c)
                                        <li class="flex flex-wrap items-center gap-x-2 gap-y-0.5"><span class="text-slate-700">{{ $c->curso }}</span> @include('cronograma._status', ['status' => $c->status, 'pequeno' => true])</li>
                                    @endforeach
                                </ul>
                            @else
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach (\App\Models\CronogramaItem::STATUS as $chave => $rotulo)
                                        @if ($porStatus->has($chave))
                                            <span class="inline-flex items-center gap-1">@include('cronograma._status', ['status' => $chave, 'pequeno' => true])<span class="text-xs font-semibold text-slate-600">× {{ $porStatus[$chave]->count() }}</span></span>
                                        @endif
                                    @endforeach
                                </div>
                                <details class="mt-1">
                                    <summary class="cursor-pointer text-xs font-semibold text-emerald-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">Ver os {{ $cursos->count() }} cursos</summary>
                                    <ul class="mt-1 space-y-1">
                                        @foreach ($cursos as $c)
                                            <li class="flex flex-wrap items-center gap-x-2 gap-y-0.5"><span class="text-slate-700">{{ $c->curso }}</span> @include('cronograma._status', ['status' => $c->status, 'pequeno' => true])</li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            @if ($item->pendencias_abertas > 0)
                                <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-bold text-amber-800"><i class="ph-bold ph-warning" aria-hidden="true"></i>{{ $item->pendencias_abertas }}</span>
                            @else
                                <span class="text-slate-500">—</span><span class="sr-only">nenhuma</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">Nenhuma atividade encontrada com esses filtros.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($itens->hasPages())
        <div class="border-t border-slate-100 px-4 py-3">{{ $itens->links() }}</div>
    @endif
</section>
