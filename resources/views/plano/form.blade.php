@extends('layouts.app')

@section('title', ($plano->exists ? 'Editar plano de ação' : 'Novo plano de ação'))

@php
    use App\Models\PlanoAcao;

    $campo = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary';
    $etiqueta = 'block text-sm font-semibold mb-1';
    $dica = 'text-xs text-slate-500 mb-1.5';
    $fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1, ',', '.'), '0'), ',').'%';

    $existe = $plano->exists;
    $ind = $origem['indicadores'] ?? null;
    // Plano já criado: a linha de base é a foto gravada. Plano novo: o que o painel mostra agora.
    $participacao = $existe ? $plano->participacao_atual : ($ind['participacao'] ?? null);
    $metaParticipacao = $existe ? $plano->meta_participacao : ($ind['meta_participacao'] ?? null);
    $proficiencia = $existe ? $plano->proficiencia_atual : ($ind['proficiencia'] ?? null);

    $etapas = [1 => 'Ponto de partida', 2 => 'Leitura do dado', 3 => 'Causas', 4 => 'Ações', 5 => 'Síntese e envio'];
    $acoes = old('acoes') ?? ($existe ? $plano->acoes->map(fn ($a) => [
        'id' => $a->id, 'descricao' => $a->descricao, 'execucao' => $a->execucao, 'responsavel' => $a->responsavel,
        'prazo' => $a->prazo?->toDateString(), 'verificacao' => $a->verificacao,
    ])->all() : []);
    if ($acoes === []) {
        $acoes = [['id' => null, 'descricao' => '', 'execucao' => '', 'responsavel' => '', 'prazo' => '', 'verificacao' => '']];
    }
    $causas = old('causas', $plano->causas ?? []);
    $porques = old('porques', $plano->porques ?? []);
    $precisaCurso = ! empty($origem['precisaCurso']);
    $semDados = ! empty($origem['semDados']) && ! $precisaCurso;
    $volta = $existe ? route('coordenador.planos.show', $plano) : route('coordenador.planos.index');
@endphp

@section('content')
<div class="max-w-6xl">
    <a href="{{ $volta }}" class="text-sm text-slate-500 hover:underline">&larr; {{ $existe ? 'Plano' : 'Planos de ação' }}</a>
    <div class="mt-2 mb-5">
        <h1 class="text-2xl font-black flex items-center gap-2"><i class="ph-bold ph-clipboard-text text-primary" aria-hidden="true"></i> {{ $existe ? 'Editar plano de ação' : 'Novo plano de ação' }}</h1>
        <p class="text-sm text-slate-600 mt-1">
            Do dado à ação pedagógica: leia o resultado, investigue as causas com o NDE e defina o que muda antes do próximo DI.
            Origem: <strong>{{ $existe ? $plano->origem_rotulo : $origem['rotulo'] }}</strong>.
        </p>
        @if ($existe && $plano->status === PlanoAcao::AJUSTES)
            @php $devolucao = $plano->eventos()->where('tipo', \App\Models\PlanoAcaoEvento::AJUSTES)->latest('id')->first(); @endphp
            @if ($devolucao)
                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" role="note">
                    <p class="font-bold flex items-center gap-2"><i class="ph-bold ph-pencil-line" aria-hidden="true"></i> O colaborador pediu ajustes</p>
                    <p class="mt-1 whitespace-pre-line">{{ $devolucao->texto }}</p>
                    @php $naoAtendidos = collect($devolucao->dados['criterios'] ?? [])->filter(fn ($ok) => ! $ok)->keys(); @endphp
                    @if ($naoAtendidos->isNotEmpty())
                        <p class="mt-2 font-semibold">Critérios não atendidos:</p>
                        <ul class="list-disc pl-5">
                            @foreach ($naoAtendidos as $chave)
                                <li>{{ \App\Services\PlanoAcaoService::CRITERIOS[$chave] ?? $chave }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
        @endif
    </div>

    {{-- Recorte do plano (só antes de criar): curso, período letivo e categoria. Muda o que o painel calcula. --}}
    @unless ($existe)
        @if (true)
            <form method="GET" action="{{ route('coordenador.planos.novo') }}" class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 mb-5 flex flex-wrap items-end gap-3">
                <input type="hidden" name="visual" value="{{ $origem['visual'] }}">
                @if (! empty($origem['item']))<input type="hidden" name="item" value="{{ $origem['item'] }}">@endif
                @if (! empty($origem['avaliacao_codigo']))<input type="hidden" name="avaliacao" value="{{ $origem['avaliacao_codigo'] }}">@endif
                @if (count($origem['cursos'] ?? []) > 1)
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1" for="recorte-curso">Curso</label>
                        <select id="recorte-curso" name="curso" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white min-w-[180px] max-w-full">
                            @if ($precisaCurso)<option value="" selected disabled>Escolha o curso…</option>@endif
                            @foreach ($origem['cursos'] as $c)
                                <option value="{{ $c }}" @selected(($origem['curso'] ?? null) === $c)>{{ $c }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                @unless ($precisaCurso)
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1" for="recorte-periodo">Período letivo</label>
                        <select id="recorte-periodo" name="periodo_letivo" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white min-w-[140px]">
                            <option value="" @selected(($origem['periodo_letivo'] ?? '') === '')>Todos</option>
                            @foreach ($origem['periodos'] ?? [] as $p)
                                <option value="{{ $p }}" @selected(($origem['periodo_letivo'] ?? '') === $p)>{{ $p }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if (! empty($origem['categorias']) && count($origem['categorias']) > 1)
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1" for="recorte-categoria">Categoria</label>
                            <select id="recorte-categoria" name="categoria" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white min-w-[180px] max-w-full">
                                <option value="" @selected(($origem['categoria_id'] ?? null) === null)>Todas as categorias</option>
                                @foreach ($origem['categorias'] as $c)
                                    @if ($c['id'] !== null)
                                        <option value="{{ $c['id'] }}" @selected(($origem['categoria_id'] ?? null) === $c['id'])>{{ $c['nome'] }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                    @endif
                @endunless
                <button type="submit" class="rounded-lg bg-slate-800 hover:bg-slate-900 px-4 py-2 text-sm font-semibold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">
                    {{ $precisaCurso ? 'Continuar' : 'Atualizar recorte' }}
                </button>
                @if (! empty($origem['categoria_automatica']))
                    <p class="basis-full text-xs text-slate-600">O período tem mais de uma categoria de avaliação; usamos <strong>{{ $origem['categoria_nome'] }}</strong>, a com mais participantes. Troque acima se o plano é sobre outra.</p>
                @endif
            </form>
        @endif
    @endunless

    @if ($precisaCurso)
        <div class="bg-slate-50 border border-slate-200 rounded-xl p-6 text-sm text-slate-700">Você coordena mais de um curso: escolha acima para qual deles é este plano.</div>
    @elseif ($semDados)
        <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-xl p-6 text-sm">
            Não há resultados deste curso no recorte escolhido, então não há indicadores para abrir um plano. Escolha outro período letivo ou categoria acima.
        </div>
    @else
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] items-start">
            <form id="form-plano" method="POST" action="{{ $existe ? route('coordenador.planos.update', $plano) : route('coordenador.planos.store') }}"
                  data-etapa-inicial="{{ $etapa }}" class="min-w-0 space-y-5">
                @csrf
                @if ($existe) @method('PUT') @endif
                <input type="hidden" name="etapa_atual" id="etapa-atual" value="{{ $etapa }}">
                @unless ($existe)
                    <input type="hidden" name="origem[curso]" value="{{ $origem['curso'] }}">
                    <input type="hidden" name="origem[periodo_letivo]" value="{{ $origem['periodo_letivo'] ?? '' }}">
                    @if (! empty($origem['categoria_id']))<input type="hidden" name="origem[categoria]" value="{{ $origem['categoria_id'] }}">@endif
                    @if (! empty($origem['avaliacao_codigo']))<input type="hidden" name="origem[avaliacao]" value="{{ $origem['avaliacao_codigo'] }}">@endif
                    <input type="hidden" name="origem[visual]" value="{{ $origem['visual'] }}">
                    @if (! empty($origem['item']))<input type="hidden" name="origem[item]" value="{{ $origem['item'] }}">@endif
                @endunless

                {{-- Etapas --}}
                <nav aria-label="Etapas do plano de ação" class="flex flex-wrap gap-2">
                    @foreach ($etapas as $numero => $rotulo)
                        <button type="button" data-ir-etapa="{{ $numero }}" @if ($numero === $etapa) aria-current="step" @endif
                                class="etapa-botao inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-primary {{ $numero === $etapa ? 'bg-slate-800 border-slate-800 text-white' : 'bg-white border-slate-300 text-slate-700 hover:bg-slate-50' }}">
                            <span class="flex h-5 w-5 items-center justify-center rounded-full text-xs {{ $numero === $etapa ? 'bg-white text-slate-800' : 'bg-slate-100 text-slate-700' }}" aria-hidden="true">{{ $numero }}</span>{{ $rotulo }}
                        </button>
                    @endforeach
                </nav>

                {{-- 1. Ponto de partida --}}
                <section data-etapa="{{ 1 }}" aria-labelledby="titulo-etapa-1" class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 space-y-5">
                    <div>
                        <h2 id="titulo-etapa-1" class="text-lg font-bold">1. Ponto de partida</h2>
                        <p class="text-sm text-slate-600">O painel já preencheu a identificação do curso e os indicadores do recorte. Falta só pactuar a meta de proficiência.</p>
                    </div>

                    <dl class="grid gap-4 sm:grid-cols-3 text-sm">
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Curso</dt>
                            <dd class="mt-1 font-semibold">{{ $existe ? $plano->curso : $origem['curso'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Período letivo</dt>
                            <dd class="mt-1 font-semibold">{{ ($existe ? $plano->periodo_letivo : ($origem['periodo_letivo'] ?? '')) ?: 'Todos os períodos' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Categoria de avaliação</dt>
                            <dd class="mt-1 font-semibold">{{ ($existe ? ($plano->contexto['categoria'] ?? null) : ($origem['categoria_nome'] ?? null)) ?: 'Todas as categorias do período' }}</dd>
                        </div>
                    </dl>

                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Participação atual</p>
                            <p class="text-2xl font-bold mt-1">{{ $fmt($participacao) }}</p>
                            @if ($ind && ! $existe)<p class="text-xs text-slate-500 mt-1">{{ $ind['fizeram'] }} de {{ $ind['previstos'] }} previstos</p>@endif
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Meta de participação</p>
                            <p class="text-2xl font-bold mt-1">{{ $fmt($metaParticipacao) }}</p>
                            <p class="text-xs text-slate-500 mt-1">meta institucional</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Proficiência atual</p>
                            <p class="text-2xl font-bold mt-1">{{ $fmt($proficiencia) }}</p>
                            <p class="text-xs text-slate-500 mt-1">estudantes com ≥ {{ (int) ($ind['corte'] ?? ($plano->contexto['corte'] ?? 60)) }}% de acerto</p>
                        </div>
                        <div class="rounded-xl border border-emerald-300 bg-emerald-50 p-4">
                            <label for="meta_proficiencia" class="block text-xs font-semibold uppercase tracking-wide text-emerald-800">Meta de proficiência (%)</label>
                            <input id="meta_proficiencia" name="meta_proficiencia" type="text" inputmode="decimal" maxlength="5" placeholder="Ex.: 70"
                                   value="{{ old('meta_proficiencia', $plano->meta_proficiencia !== null ? rtrim(rtrim(number_format($plano->meta_proficiencia, 1, ',', ''), '0'), ',') : '') }}"
                                   class="mt-1 w-full rounded-lg border border-emerald-300 bg-white px-3 py-1.5 text-lg font-bold focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                            <p class="text-xs text-emerald-900 mt-1">pactuada com o NDE para o próximo DI</p>
                        </div>
                    </div>

                    @if (! empty($ind['mistura']) || ! empty($plano->contexto['mistura']))
                        <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Os números reúnem avaliações de categorias diferentes, que não são comparáveis entre si. Se o plano é sobre uma prova específica, escolha a categoria no recorte acima.</p>
                    @endif

                    @php $linhasDado = $existe ? ($plano->contexto['linhas'] ?? []) : ($origem['linhas'] ?? []); @endphp
                    @if ($linhasDado !== [])
                        <div>
                            <h3 class="text-sm font-bold mb-2 flex items-center gap-2"><i class="ph-bold {{ PlanoAcao::VISUAIS[$existe ? $plano->origem_visual : $origem['visual']]['icone'] ?? 'ph-chart-bar' }} text-primary" aria-hidden="true"></i> O dado que originou o plano</h3>
                            <div class="rounded-xl border border-slate-200 overflow-x-auto">
                                <table class="w-full text-sm">
                                    <caption class="sr-only">Dados do visual em que o plano foi iniciado</caption>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach ($linhasDado as $linha)
                                            <tr class="{{ ! empty($linha['destaque']) ? 'bg-emerald-50' : '' }}">
                                                <th scope="row" class="px-3 py-2 text-left font-medium text-slate-700">{{ $linha['rotulo'] }}</th>
                                                <td class="px-3 py-2 font-bold whitespace-nowrap">{{ $linha['valor'] }}</td>
                                                <td class="px-3 py-2 text-xs text-slate-500">{{ $linha['detalhe'] ?? '' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <p class="text-xs text-slate-500 mt-1">Esta cópia dos números fica guardada no plano, para quem for analisá-lo.</p>
                        </div>
                    @endif

                    <div class="max-w-xs">
                        <label for="data_proximo_di" class="{{ $etiqueta }}">Data prevista do próximo DI <span class="font-normal text-slate-500">(opcional)</span></label>
                        <p class="{{ $dica }}">Serve para conferir se os prazos das ações cabem antes da próxima aplicação.</p>
                        <input id="data_proximo_di" name="data_proximo_di" type="date" value="{{ old('data_proximo_di', $plano->data_proximo_di?->toDateString()) }}" class="{{ $campo }}">
                    </div>
                </section>

                {{-- 2. Leitura do dado --}}
                <section data-etapa="2" aria-labelledby="titulo-etapa-2" class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 space-y-5">
                    <div>
                        <h2 id="titulo-etapa-2" class="text-lg font-bold">2. Leitura do dado</h2>
                        <p class="text-sm text-slate-600">Localize a fragilidade que pode ser enfrentada. Descreva o que o dado mostra, sem antecipar a causa. Use “Inserir sugestão” para partir do que o painel calculou — e edite à vontade.</p>
                    </div>
                    @foreach ([
                        'recorte' => ['Qual recorte merece atenção?', 'Período, grupo ou faixa de desempenho.', 3],
                        'resultado' => ['Qual resultado precisa ser enfrentado?', 'O resultado observado, sem antecipar a causa.', 3],
                        'fragilidades' => ['Quais competências, objetivos de aprendizagem ou níveis cognitivos apresentam fragilidade?', 'Registre apenas os recortes que o painel de fato mostra.', 4],
                        'evidencias' => ['Quais dados sustentam a escolha?', 'Indicadores, recortes, itens ou observações do painel.', 5],
                    ] as $nome => [$pergunta, $ajudaCampo, $linhas])
                        <div>
                            <div class="flex flex-wrap items-end justify-between gap-2">
                                <label for="{{ $nome }}" class="{{ $etiqueta }} mb-0">{{ $pergunta }}</label>
                                <button type="button" data-sugestao="{{ $nome }}" class="text-xs font-semibold text-emerald-800 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">Inserir sugestão do painel</button>
                            </div>
                            <p class="{{ $dica }} mt-0.5">{{ $ajudaCampo }}</p>
                            <textarea id="{{ $nome }}" name="{{ $nome }}" rows="{{ $linhas }}" maxlength="5000" class="{{ $campo }}">{{ old($nome, $plano->{$nome}) }}</textarea>
                        </div>
                    @endforeach
                    <div class="rounded-lg border-l-4 border-slate-400 bg-slate-50 px-4 py-3 text-sm text-slate-700"><strong>Evite:</strong> concluir que uma turma “desaprendeu” só porque um período tem resultado inferior ao anterior — a comparação pode envolver grupos diferentes.</div>
                </section>

                {{-- 3. Causas --}}
                <section data-etapa="3" aria-labelledby="titulo-etapa-3" class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 space-y-6">
                    <div>
                        <h2 id="titulo-etapa-3" class="text-lg font-bold">3. Causas</h2>
                        <p class="text-sm text-slate-600">O painel mostra <strong>onde</strong> está a fragilidade; a investigação das causas depende da discussão com o NDE e de evidências complementares.</p>
                    </div>

                    <fieldset>
                        <legend class="text-sm font-bold">Possíveis causas levantadas (Ishikawa)</legend>
                        <p class="{{ $dica }}">Preencha as dimensões em que há hipóteses; deixe em branco as demais. Hipótese não é conclusão comprovada.</p>
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach (PlanoAcao::DIMENSOES as $chave => $rotuloDimensao)
                                <div>
                                    <label for="causa-{{ $chave }}" class="block text-xs font-semibold text-slate-700 mb-1">{{ $rotuloDimensao }}</label>
                                    <textarea id="causa-{{ $chave }}" name="causas[{{ $chave }}]" rows="3" maxlength="3000" class="{{ $campo }}">{{ $causas[$chave] ?? '' }}</textarea>
                                </div>
                            @endforeach
                        </div>
                    </fieldset>

                    <fieldset class="space-y-3">
                        <legend class="text-sm font-bold">Priorização da causa mais promissora</legend>
                        <div>
                            <label for="causa_priorizada" class="{{ $etiqueta }}">Causa a priorizar</label>
                            <p class="{{ $dica }}">Uma hipótese específica e verificável.</p>
                            <textarea id="causa_priorizada" name="causa_priorizada" rows="3" maxlength="5000" class="{{ $campo }}">{{ old('causa_priorizada', $plano->causa_priorizada) }}</textarea>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-3">
                            @foreach (['nota_impacto' => 'Impacto', 'nota_evidencia' => 'Evidência', 'nota_governabilidade' => 'Governabilidade'] as $nome => $rotuloNota)
                                <div>
                                    <label for="{{ $nome }}" class="block text-xs font-semibold text-slate-700 mb-1">{{ $rotuloNota }} (1–3)</label>
                                    <select id="{{ $nome }}" name="{{ $nome }}" data-nota class="{{ $campo }}">
                                        <option value="">Selecione</option>
                                        @foreach ([1 => '1 — baixo', 2 => '2 — médio', 3 => '3 — alto'] as $valor => $texto)
                                            <option value="{{ $valor }}" @selected((int) old($nome, $plano->{$nome}) === $valor)>{{ $texto }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endforeach
                        </div>
                        <p id="pontuacao" class="rounded-lg bg-slate-50 border border-slate-200 px-3 py-2 text-sm" aria-live="polite">Pontuação = Impacto × Evidência × Governabilidade: <strong data-pontuacao>não calculada</strong></p>
                        <p class="text-xs text-slate-500">A pontuação orienta a discussão; não substitui a validação pelo NDE.</p>
                    </fieldset>

                    <fieldset>
                        <legend class="text-sm font-bold">Por que essa causa ocorre? (aprofundamento pelos 5 Porquês)</legend>
                        <p class="{{ $dica }}">Pergunte “por quê?” a cada resposta. Não é obrigatório chegar a cinco se a causa já estiver suficientemente explicada.</p>
                        <ol class="space-y-2">
                            @foreach (range(0, 4) as $i)
                                <li class="flex items-start gap-2">
                                    <span class="mt-2 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-slate-700" aria-hidden="true">{{ $i + 1 }}</span>
                                    <div class="flex-1">
                                        <label for="porque-{{ $i }}" class="sr-only">Porquê {{ $i + 1 }}</label>
                                        <input id="porque-{{ $i }}" name="porques[]" type="text" maxlength="1000" value="{{ $porques[$i] ?? '' }}" placeholder="{{ $i === 0 ? 'Por que o resultado está abaixo do esperado?' : 'E por que isso acontece?' }}" class="{{ $campo }}">
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </fieldset>

                    <div>
                        <label for="causa_raiz" class="{{ $etiqueta }}">Causa-raiz acionável</label>
                        <p class="{{ $dica }}">Algo específico, sustentado por evidências e modificável antes do próximo DI.</p>
                        <textarea id="causa_raiz" name="causa_raiz" rows="3" maxlength="5000" class="{{ $campo }}">{{ old('causa_raiz', $plano->causa_raiz) }}</textarea>
                    </div>
                </section>

                {{-- 4. Ações --}}
                <section data-etapa="4" aria-labelledby="titulo-etapa-4" class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 space-y-5">
                    <div>
                        <h2 id="titulo-etapa-4" class="text-lg font-bold">4. Ações</h2>
                        <p class="text-sm text-slate-600">Transforme a análise em intervenção: uma mudança concreta na experiência de aprendizagem, ligada à causa-raiz e executável antes do próximo DI. Um plano pode ter mais de uma ação.</p>
                    </div>

                    <div id="lista-acoes" class="space-y-4">
                        @foreach ($acoes as $i => $acao)
                            @include('plano._acao-linha', ['i' => $i, 'acao' => $acao, 'campo' => $campo, 'etiqueta' => $etiqueta, 'dica' => $dica])
                        @endforeach
                    </div>
                    <button type="button" id="adicionar-acao" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                        <i class="ph-bold ph-plus" aria-hidden="true"></i> Adicionar outra ação
                    </button>
                    <template id="modelo-acao">
                        @include('plano._acao-linha', ['i' => '__I__', 'acao' => ['id' => null, 'descricao' => '', 'execucao' => '', 'responsavel' => '', 'prazo' => '', 'verificacao' => ''], 'campo' => $campo, 'etiqueta' => $etiqueta, 'dica' => $dica])
                    </template>

                    <div class="rounded-lg border-l-4 border-slate-400 bg-slate-50 px-4 py-3 text-sm text-slate-700"><strong>Teste de coerência:</strong> se a ação for executada, ela enfrenta a causa-raiz e pode contribuir para a meta? Se não, reformule.</div>
                </section>

                {{-- 5. Síntese e envio --}}
                <section data-etapa="5" aria-labelledby="titulo-etapa-5" class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 space-y-5">
                    <div>
                        <h2 id="titulo-etapa-5" class="text-lg font-bold">5. Síntese e envio</h2>
                        <p class="text-sm text-slate-600">Revise o que foi preenchido. Ao enviar, o plano segue para a análise do colaborador; você será avisado da decisão.</p>
                    </div>

                    <div id="faltas" class="{{ $faltas === [] ? 'hidden' : '' }} rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" role="status">
                        <p class="font-bold flex items-center gap-2"><i class="ph-bold ph-list-checks" aria-hidden="true"></i> Falta completar antes de enviar</p>
                        <ul id="lista-faltas" class="list-disc pl-5 mt-1 space-y-0.5">
                            @foreach ($faltas as $falta)
                                <li>{{ $falta['mensagem'] }} <button type="button" data-ir-etapa="{{ $falta['etapa'] }}" class="underline font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">Ir para a etapa {{ $falta['etapa'] }}</button></li>
                            @endforeach
                        </ul>
                    </div>

                    <div id="sintese" class="rounded-xl border border-slate-200 divide-y divide-slate-100 text-sm" aria-live="polite"></div>

                    <div class="flex flex-wrap items-center gap-3 pt-2">
                        <button type="submit" name="acao" value="enviar" data-enviar
                                class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 px-5 py-2.5 text-sm font-semibold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">
                            <i class="ph-bold ph-paper-plane-tilt" aria-hidden="true"></i> Enviar para análise
                        </button>
                        <span class="text-xs text-slate-500">Enquanto estiver em análise, você pode retirar o plano para editar de novo.</span>
                    </div>
                </section>

                {{-- Barra de navegação do roteiro --}}
                <div class="flex flex-wrap items-center justify-between gap-3 bg-white border border-slate-200 rounded-xl shadow-sm px-4 py-3 sticky bottom-2 z-10">
                    <button type="button" id="etapa-anterior" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">&larr; Voltar</button>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="submit" name="acao" value="salvar" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                            <i class="ph-bold ph-floppy-disk" aria-hidden="true"></i> Salvar rascunho
                        </button>
                        <button type="button" id="etapa-proxima" class="rounded-lg bg-slate-800 hover:bg-slate-900 px-4 py-2 text-sm font-semibold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">Próxima etapa &rarr;</button>
                    </div>
                </div>
            </form>

            <aside class="lg:sticky lg:top-4 space-y-3" aria-labelledby="titulo-ajuda">
                <h2 id="titulo-ajuda" class="font-bold flex items-center gap-2"><i class="ph-bold ph-lifebuoy text-primary" aria-hidden="true"></i> Dúvidas desta etapa</h2>
                @include('plano._ajuda', ['etapa' => $etapa])
                <p class="text-xs text-slate-500">Orientações fixas do roteiro. Não consultam os dados do curso; questões específicas dependem das evidências e da discussão com o NDE.</p>
            </aside>
        </div>

        <script type="application/json" id="plano-sugestoes">{!! json_encode($origem['sugestoes'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_PARTIAL_OUTPUT_ON_ERROR) !!}</script>
        <script type="application/json" id="plano-dimensoes">{!! json_encode(PlanoAcao::DIMENSOES, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
        <script src="{{ asset('assets/js/plano-acao-form.js') }}?v={{ @filemtime(public_path('assets/js/plano-acao-form.js')) }}"></script>
    @endif
</div>
@endsection
