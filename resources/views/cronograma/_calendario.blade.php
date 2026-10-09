{{--
    Calendário mensal de atividades (domingo a sábado) — o mesmo para o coordenador (só as atividades dos cursos dele) e
    para o colaborador (todas, com um "+" em cada dia para cadastrar). Em telas estreitas vira uma agenda por dia.
    Variáveis:
      $calendario    resultado de CronogramaService::mes()
      $rotaItem      nome da rota que abre uma atividade
      $rotaMes       nome da rota desta tela (os links de mês voltam para ela)
      $query         filtros a manter nos links de mês (curso, rotina...)
      $cursosDoUsuario  (opcional) cursos do coordenador — null = visão geral
      $rotaNovo      (opcional) rota de "nova atividade": liga o "+" em cada dia
--}}
@php
    $mes = $calendario['mes'];
    $hoje = today();
    $cursosDoUsuario = $cursosDoUsuario ?? null;
    $rotaNovo = $rotaNovo ?? null;
    $nomeMes = ucfirst($mes->locale('pt_BR')->translatedFormat('F \d\e Y'));
    $link = fn (array $extra) => route($rotaMes, array_filter([...$query, ...$extra], fn ($v) => $v !== '' && $v !== null));
    $diasDaSemana = [['Dom', 'domingo'], ['Seg', 'segunda-feira'], ['Ter', 'terça-feira'], ['Qua', 'quarta-feira'], ['Qui', 'quinta-feira'], ['Sex', 'sexta-feira'], ['Sáb', 'sábado']];
    $itensDoMes = $calendario['itens']->filter(fn ($grupo, $data) => str_starts_with($data, $mes->format('Y-m')));
    $botao = 'rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary';
@endphp
<section class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden" aria-labelledby="titulo-calendario">
    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-b border-slate-200">
        <h2 id="titulo-calendario" class="text-lg font-black flex items-center gap-2">
            <i class="ph-bold ph-calendar-blank text-slate-600" aria-hidden="true"></i> {{ $nomeMes }}
            <span class="text-sm font-medium text-slate-500">{{ $calendario['total'] }} {{ $calendario['total'] === 1 ? 'atividade' : 'atividades' }}</span>
        </h2>
        <nav class="flex items-center gap-2" aria-label="Navegar entre os meses">
            <a href="{{ $link(['mes' => $mes->subMonth()->format('Y-m')]) }}" class="{{ $botao }}" aria-label="Mês anterior"><i class="ph-bold ph-caret-left" aria-hidden="true"></i></a>
            <a href="{{ $link(['mes' => '']) }}" class="{{ $botao }}">Hoje</a>
            <a href="{{ $link(['mes' => $mes->addMonth()->format('Y-m')]) }}" class="{{ $botao }}" aria-label="Próximo mês"><i class="ph-bold ph-caret-right" aria-hidden="true"></i></a>
        </nav>
    </div>

    {{-- Telas largas: grade do mês. --}}
    <table class="hidden md:table w-full table-fixed border-collapse" aria-labelledby="titulo-calendario">
        <thead>
            <tr>
                @foreach ($diasDaSemana as [$abreviado, $inteiro])
                    <th scope="col" class="border-b border-slate-200 bg-slate-50 py-2 text-xs font-semibold uppercase tracking-wide text-slate-600"><abbr title="{{ $inteiro }}" class="no-underline">{{ $abreviado }}</abbr></th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($calendario['semanas'] as $semana)
                <tr>
                    @foreach ($semana as $dia)
                        @php
                            $doMes = $dia->isSameMonth($mes);
                            $ehHoje = $dia->isSameDay($hoje);
                            $doDia = $calendario['itens']->get($dia->toDateString(), collect());
                        @endphp
                        <td class="h-28 border border-slate-100 p-1.5 align-top {{ $doMes ? 'bg-white' : 'bg-slate-50' }}">
                            <div class="mb-1 flex items-center justify-between">
                                <span class="inline-flex min-w-[1.5rem] justify-center rounded-full px-1 text-xs font-bold {{ $ehHoje ? 'bg-slate-800 text-white' : ($doMes ? 'text-slate-700' : 'text-slate-500') }}">
                                    {{ $dia->day }}<span class="sr-only"> de {{ $dia->locale('pt_BR')->translatedFormat('F') }}{{ $ehHoje ? ' (hoje)' : '' }}</span>
                                </span>
                                @if ($rotaNovo)
                                    <a href="{{ route($rotaNovo, ['data' => $dia->toDateString()]) }}" aria-label="Nova atividade em {{ $dia->format('d/m/Y') }}" title="Nova atividade neste dia"
                                       class="rounded p-0.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"><i class="ph-bold ph-plus text-xs" aria-hidden="true"></i></a>
                                @endif
                            </div>
                            <div class="space-y-1">
                                @foreach ($doDia as $item)
                                    @include('cronograma._chip', ['item' => $item, 'rotaItem' => $rotaItem, 'cursosDoUsuario' => $cursosDoUsuario])
                                @endforeach
                            </div>
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Telas estreitas: só os dias que têm atividade. --}}
    <div class="md:hidden divide-y divide-slate-100">
        @forelse ($itensDoMes as $data => $doDia)
            @php $dia = \Carbon\CarbonImmutable::parse($data); @endphp
            <div class="px-4 py-3">
                <h3 class="mb-2 text-sm font-bold {{ $dia->isSameDay($hoje) ? 'text-slate-900' : 'text-slate-700' }}">
                    {{ ucfirst($dia->locale('pt_BR')->translatedFormat('l, d/m')) }}{{ $dia->isSameDay($hoje) ? ' (hoje)' : '' }}
                </h3>
                <div class="space-y-1.5">
                    @foreach ($doDia as $item)
                        @include('cronograma._chip', ['item' => $item, 'rotaItem' => $rotaItem, 'cursosDoUsuario' => $cursosDoUsuario])
                    @endforeach
                </div>
            </div>
        @empty
            <p class="px-4 py-6 text-sm text-slate-500">Nenhuma atividade neste mês.</p>
        @endforelse
    </div>

    @if ($calendario['total'] === 0)
        <p class="hidden md:block border-t border-slate-100 px-4 py-4 text-sm text-slate-500">Nenhuma atividade neste mês.</p>
    @endif

    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600" aria-label="Legenda">
        <span class="font-semibold">Rotinas:</span>
        @foreach (\App\Models\CronogramaItem::ROTINAS as $rotina => $quem)
            @php $cor = match ($rotina) { 'ROD' => 'bg-sky-100 border-sky-400', 'ROC' => 'bg-violet-100 border-violet-400', default => 'bg-amber-100 border-amber-400' }; @endphp
            <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm border {{ $cor }}" aria-hidden="true"></span><strong>{{ $rotina }}</strong> {{ $quem }}</span>
        @endforeach
    </div>
</section>
