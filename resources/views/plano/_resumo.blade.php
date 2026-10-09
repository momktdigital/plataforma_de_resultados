{{--
    O conteúdo de um plano de ação, só para leitura: ponto de partida (linha de base), leitura do dado, causas e ações.
    Usada na página do coordenador e na análise do colaborador.
    Variáveis: $plano (com `acoes`), $alertas (opcional, array de textos).
--}}
@php
    use App\Models\PlanoAcao;

    $fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1, ',', '.'), '0'), ',').'%';
    $texto = fn (?string $t) => trim((string) $t) === '' ? null : $t;
    $ctx = $plano->contexto ?? [];
    $secao = 'bg-white border border-slate-200 rounded-xl shadow-sm p-6';
    $rotulo = 'text-xs font-bold uppercase tracking-wide text-slate-500';
@endphp

<section class="{{ $secao }}" aria-labelledby="resumo-partida">
    <h2 id="resumo-partida" class="text-lg font-bold mb-3">Ponto de partida</h2>
    <dl class="grid gap-4 sm:grid-cols-3 text-sm mb-4">
        <div><dt class="{{ $rotulo }}">Curso</dt><dd class="mt-1 font-semibold">{{ $plano->curso }}</dd></div>
        <div><dt class="{{ $rotulo }}">Período letivo</dt><dd class="mt-1 font-semibold">{{ $plano->periodo_letivo ?: 'Todos os períodos' }}</dd></div>
        <div><dt class="{{ $rotulo }}">Categoria de avaliação</dt><dd class="mt-1 font-semibold">{{ $ctx['categoria'] ?? 'Todas as categorias do período' }}</dd></div>
    </dl>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <p class="{{ $rotulo }}">Participação atual</p>
            <p class="text-2xl font-bold mt-1">{{ $fmt($plano->participacao_atual) }}</p>
            @if (! empty($ctx['previstos']))<p class="text-xs text-slate-500 mt-1">{{ $ctx['fizeram'] }} de {{ $ctx['previstos'] }} previstos</p>@endif
        </div>
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <p class="{{ $rotulo }}">Meta de participação</p>
            <p class="text-2xl font-bold mt-1">{{ $fmt($plano->meta_participacao) }}</p>
            <p class="text-xs text-slate-500 mt-1">meta institucional</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <p class="{{ $rotulo }}">Proficiência atual</p>
            <p class="text-2xl font-bold mt-1">{{ $fmt($plano->proficiencia_atual) }}</p>
            @if (! empty($ctx['corte']))<p class="text-xs text-slate-500 mt-1">estudantes com ≥ {{ (int) $ctx['corte'] }}% de acerto</p>@endif
        </div>
        <div class="rounded-xl border border-emerald-300 bg-emerald-50 p-4">
            <p class="text-xs font-bold uppercase tracking-wide text-emerald-800">Meta de proficiência</p>
            <p class="text-2xl font-bold mt-1">{{ $fmt($plano->meta_proficiencia) }}</p>
            <p class="text-xs text-emerald-900 mt-1">para a próxima avaliação{{ $plano->data_proxima_avaliacao ? ' ('.$plano->data_proxima_avaliacao->format('d/m/Y').')' : '' }}</p>
        </div>
    </div>

    <p class="text-sm text-slate-600 mt-4 flex items-center gap-2">
        <i class="ph-bold {{ PlanoAcao::VISUAIS[$plano->origem_visual]['icone'] ?? 'ph-chart-bar' }} text-emerald-700" aria-hidden="true"></i>
        Plano iniciado em: <strong>{{ $plano->origem_rotulo }}</strong>
    </p>
    @if (! empty($ctx['mistura']))
        <p class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Os números reúnem avaliações de categorias diferentes, que não são comparáveis entre si.</p>
    @endif
    @if (! empty($ctx['linhas']))
        <details class="mt-3 rounded-xl border border-slate-200">
            <summary class="cursor-pointer px-4 py-2.5 text-sm font-semibold text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded-xl">O dado que originou o plano ({{ count($ctx['linhas']) }} linha(s))</summary>
            <table class="w-full text-sm border-t border-slate-100">
                <caption class="sr-only">Dados do visual em que o plano foi iniciado</caption>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($ctx['linhas'] as $linha)
                        <tr class="{{ ! empty($linha['destaque']) ? 'bg-emerald-50' : '' }}">
                            <th scope="row" class="px-4 py-2 text-left font-medium text-slate-700">{{ $linha['rotulo'] }}</th>
                            <td class="px-3 py-2 font-bold whitespace-nowrap">{{ $linha['valor'] }}</td>
                            <td class="px-3 py-2 text-xs text-slate-500">{{ $linha['detalhe'] ?? '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </details>
    @endif
</section>

<section class="{{ $secao }}" aria-labelledby="resumo-leitura">
    <h2 id="resumo-leitura" class="text-lg font-bold mb-3">Leitura do dado</h2>
    <dl class="space-y-4 text-sm">
        @foreach (['recorte' => 'Recorte que merece atenção', 'resultado' => 'Resultado a enfrentar', 'fragilidades' => 'Competências, objetivos de aprendizagem ou níveis cognitivos com fragilidade', 'evidencias' => 'Dados que sustentam a escolha'] as $campo => $titulo)
            <div>
                <dt class="{{ $rotulo }}">{{ $titulo }}</dt>
                <dd class="mt-1 whitespace-pre-line {{ $texto($plano->{$campo}) ? 'text-slate-800' : 'text-slate-500 italic' }}">{{ $texto($plano->{$campo}) ?? 'Não informado' }}</dd>
            </div>
        @endforeach
    </dl>
</section>

<section class="{{ $secao }}" aria-labelledby="resumo-causas">
    <h2 id="resumo-causas" class="text-lg font-bold mb-3">Causas</h2>
    @if (! empty($plano->causas))
        <div>
            <p class="{{ $rotulo }} mb-2">Possíveis causas levantadas (Ishikawa)</p>
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($plano->causas as $chave => $causa)
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
                        <p class="text-xs font-bold text-slate-600">{{ PlanoAcao::DIMENSOES[$chave] ?? $chave }}</p>
                        <p class="mt-1 whitespace-pre-line text-slate-800">{{ $causa }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
    <dl class="space-y-4 text-sm mt-4">
        <div>
            <dt class="{{ $rotulo }}">Causa priorizada</dt>
            <dd class="mt-1 whitespace-pre-line {{ $texto($plano->causa_priorizada) ? 'text-slate-800' : 'text-slate-500 italic' }}">{{ $texto($plano->causa_priorizada) ?? 'Não informada' }}</dd>
            @if ($plano->pontuacao() !== null)
                <dd class="mt-1 text-xs text-slate-600">Impacto {{ $plano->nota_impacto }} × Evidência {{ $plano->nota_evidencia }} × Governabilidade {{ $plano->nota_governabilidade }} = <strong>{{ $plano->pontuacao() }} de 27</strong></dd>
            @endif
        </div>
        @if (! empty($plano->porques))
            <div>
                <dt class="{{ $rotulo }}">Aprofundamento (5 Porquês)</dt>
                <dd class="mt-1"><ol class="list-decimal pl-5 space-y-0.5 text-slate-800">@foreach ($plano->porques as $porque)<li>{{ $porque }}</li>@endforeach</ol></dd>
            </div>
        @endif
        <div>
            <dt class="{{ $rotulo }}">Causa-raiz acionável</dt>
            <dd class="mt-1 whitespace-pre-line {{ $texto($plano->causa_raiz) ? 'text-slate-800 font-semibold' : 'text-slate-500 italic' }}">{{ $texto($plano->causa_raiz) ?? 'Não informada' }}</dd>
        </div>
    </dl>
</section>

<section class="{{ $secao }}" aria-labelledby="resumo-acoes">
    <h2 id="resumo-acoes" class="text-lg font-bold mb-3">Ações pedagógicas</h2>
    @if ($plano->acoes->isEmpty())
        <p class="text-sm text-slate-500 italic">Nenhuma ação cadastrada.</p>
    @else
        <ol class="space-y-4">
            @foreach ($plano->acoes as $i => $acao)
                <li class="rounded-xl border border-slate-200 p-4 text-sm">
                    <p class="font-bold text-slate-900"><span class="text-slate-500">{{ $i + 1 }}.</span> {{ $acao->descricao }}</p>
                    <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div class="sm:col-span-2"><dt class="{{ $rotulo }}">Como será executada</dt><dd class="mt-1 whitespace-pre-line text-slate-800">{{ $acao->execucao ?: '—' }}</dd></div>
                        <div><dt class="{{ $rotulo }}">Responsável</dt><dd class="mt-1 text-slate-800">{{ $acao->responsavel ?: '—' }}</dd></div>
                        <div><dt class="{{ $rotulo }}">Prazo</dt><dd class="mt-1 text-slate-800">{{ $acao->prazo?->format('d/m/Y') ?? '—' }}</dd></div>
                        <div class="sm:col-span-2"><dt class="{{ $rotulo }}">Como será verificada a execução e os sinais de aprendizagem</dt><dd class="mt-1 whitespace-pre-line text-slate-800">{{ $acao->verificacao ?: '—' }}</dd></div>
                    </dl>
                </li>
            @endforeach
        </ol>
    @endif
</section>

@if (! empty($alertas))
    <section class="rounded-xl border border-amber-200 bg-amber-50 p-5" aria-labelledby="resumo-alertas">
        <h2 id="resumo-alertas" class="font-bold text-amber-900 flex items-center gap-2"><i class="ph-bold ph-warning" aria-hidden="true"></i> Pontos de atenção</h2>
        <ul class="list-disc pl-5 mt-2 space-y-1 text-sm text-amber-900">
            @foreach ($alertas as $alerta)<li>{{ $alerta }}</li>@endforeach
        </ul>
    </section>
@endif
