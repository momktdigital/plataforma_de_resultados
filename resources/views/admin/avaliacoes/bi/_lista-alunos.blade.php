@if ($estado['ranking_completo']['visivelAdmin'] && $rankingCompleto !== null)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-slate-100 font-semibold flex flex-wrap items-center gap-3">
            <span class="flex items-center gap-2">
                <span>Alunos da avaliação ({{ $rankingTotal }} respondente(s))</span>
                @include('_explicacao', ['explicacao' => $explicacoes['ranking_completo'] ?? null])
            </span>
            <a href="{{ route('avaliacoes.bi.alunos.xlsx', array_filter(['avaliacao' => $avaliacao->codigo, 'periodo' => $periodo])) }}"
               class="ml-auto inline-flex items-center gap-1.5 border border-slate-300 text-slate-700 hover:bg-slate-50 font-semibold rounded-lg px-3 py-1.5 text-sm">
                <i class="ph-bold ph-file-xls text-emerald-600"></i> Baixar XLSX
            </a>
        </div>
        @if (empty($rankingCompleto))
            <p class="px-6 py-6 text-sm text-slate-500">Nenhum respondente{{ $somenteLeitura ? ' dos seus cursos' : '' }} para o filtro selecionado.</p>
        @else
            <div id="lista-alunos" class="max-h-[32rem] overflow-auto"
                 data-url="{{ route('avaliacoes.bi.alunos.linhas', ['avaliacao' => $avaliacao->codigo]) }}"
                 data-periodo="{{ $periodo }}"
                 data-total="{{ $rankingTotal }}"
                 data-proximo="{{ $rankingTotal > count($rankingCompleto) ? count($rankingCompleto) : '' }}">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-slate-500 text-left sticky top-0 z-10">
                        <tr>
                            <th class="px-4 py-3">#</th>
                            <th class="px-4 py-3">Aluno</th>
                            <th class="px-4 py-3">RA</th>
                            <th class="px-4 py-3">Curso</th>
                            <th class="px-4 py-3">Período</th>
                            <th class="px-4 py-3">Turma</th>
                            @if ($listaComEsperadas)
                                <th class="px-4 py-3 whitespace-nowrap" title="Questões acertadas entre as que o aluno precisava acertar pelo período em que está (as de períodos à frente não contam)">Acertos dentro do esperado</th>
                            @endif
                            <th class="px-4 py-3">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @include('admin.avaliacoes._linhas-alunos', ['linhas' => $rankingCompleto, 'inicio' => 0, 'comEsperadas' => $listaComEsperadas])
                    </tbody>
                </table>
                {{-- A lista tem milhares de linhas com foto: só a primeira página vai no HTML; o resto chega por aqui. --}}
                @if ($rankingTotal > count($rankingCompleto))
                    <div id="lista-alunos-mais" class="px-4 py-3 text-center">
                        <button type="button" id="lista-alunos-botao" class="text-sm font-semibold text-emerald-700 hover:underline">
                            Mostrar mais ({{ $rankingTotal - count($rankingCompleto) }} restantes)
                        </button>
                        <span id="lista-alunos-carregando" class="hidden text-sm text-slate-500">Carregando…</span>
                    </div>
                @endif
            </div>
        @endif
    </div>
@endif
