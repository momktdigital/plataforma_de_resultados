{{--
    Cabeçalho comum das telas do painel do coordenador: saudação (Bom dia/Boa tarde/Boa noite, como no boletim do
    aluno), curso + semestre em foco e as abas do painel.
    Variáveis:
      $ctx    array com meusCursos, cursosEmFoco, cursoSelecionado, periodoSelecionado (e semCurso/semResultados)
      $usuario  Admin logado
      $aba    'visao' | 'alunos' | 'desempenho' | 'comparativo'
      $chips  (opcional) array de ['valor' => string, 'rotulo' => string] — números de destaque à direita
--}}
@php
    $semDadosCabecalho = ! empty($ctx['semCurso']) || ! empty($ctx['semResultados']);
    $nomeCursos = implode(' · ', $ctx['cursosEmFoco'] ?? []);
    $semestre = $ctx['periodoSelecionado'] ?? '';

    // Trocar de aba mantém o curso e o semestre escolhidos (semestre vazio = "Todos", por isso vai mesmo vazio).
    $manter = array_filter(['curso' => $ctx['cursoSelecionado'] ?? ''], fn ($v) => $v !== '');
    if (! $semDadosCabecalho) {
        $manter['periodo_letivo'] = $semestre;
    }

    $abas = [
        ['id' => 'visao', 'rota' => 'coordenador.painel', 'icone' => 'ph-squares-four', 'rotulo' => 'Visão geral'],
        ['id' => 'alunos', 'rota' => 'coordenador.alunos', 'icone' => 'ph-users-three', 'rotulo' => 'Alunos'],
        ['id' => 'desempenho', 'rota' => 'coordenador.desempenho', 'icone' => 'ph-chart-line-up', 'rotulo' => 'Desempenho'],
        ['id' => 'comparativo', 'rota' => 'coordenador.comparativo', 'icone' => 'ph-arrows-left-right', 'rotulo' => 'Comparar semestres'],
    ];
@endphp
<div class="relative overflow-hidden rounded-2xl shadow-lg mb-6" style="background: linear-gradient(135deg, #00b48d 0%, #009e7d 55%, #007a61 100%);">
    <div class="absolute inset-0 opacity-10 pointer-events-none" style="background-image: radial-gradient(circle at 85% 15%, white 0, transparent 45%), radial-gradient(circle at 10% 90%, white 0, transparent 40%);"></div>

    <div class="relative p-6 sm:p-8 pb-5 sm:pb-6 flex flex-col lg:flex-row lg:items-center gap-5 lg:gap-8">
        <div class="min-w-0 flex-1">
            <p id="saudacao-coordenador" data-nome="{{ $usuario->nomeParaSaudacao() }}" class="text-white/85 text-sm font-medium uppercase tracking-wide">Olá, {{ $usuario->nomeParaSaudacao() }}</p>
            <h1 class="text-2xl sm:text-3xl font-black text-white break-words">{{ $nomeCursos ?: 'Nenhum curso vinculado' }}</h1>
            <p class="text-white/85 text-sm mt-1">
                Painel da coordenação
                @if (! $semDadosCabecalho)
                    &middot; {{ $semestre !== '' ? 'período letivo '.$semestre : 'todos os períodos letivos' }}
                @endif
            </p>
        </div>

        @if (! empty($chips))
            <div class="flex flex-wrap gap-3 shrink-0">
                @foreach ($chips as $chip)
                    <div class="bg-white/15 backdrop-blur-sm rounded-2xl px-4 py-3 text-center min-w-[96px]">
                        <div class="text-2xl font-black text-white">{{ $chip['valor'] }}</div>
                        <div class="text-[11px] text-white/85 font-medium uppercase tracking-wide">{{ $chip['rotulo'] }}</div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <nav class="relative border-t border-white/20 bg-black/10 px-3 sm:px-6 flex gap-1 overflow-x-auto" aria-label="Seções do painel">
        @foreach ($abas as $item)
            @php $ativa = $aba === $item['id']; @endphp
            <a href="{{ route($item['rota'], $manter) }}" @if ($ativa) aria-current="page" @endif
               class="shrink-0 inline-flex items-center gap-2 px-4 py-3 text-sm border-b-2 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-white/80 {{ $ativa ? 'border-white text-white font-bold' : 'border-transparent text-white/85 hover:text-white hover:border-white/50 font-medium' }}">
                <i class="ph-bold {{ $item['icone'] }} text-base" aria-hidden="true"></i> {{ $item['rotulo'] }}
            </a>
        @endforeach
        <a href="{{ route('avaliacoes.index') }}"
           class="shrink-0 inline-flex items-center gap-2 px-4 py-3 text-sm border-b-2 border-transparent text-white/85 hover:text-white hover:border-white/50 font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-white/80">
            <i class="ph-bold ph-exam text-base" aria-hidden="true"></i> Avaliações
        </a>
    </nav>
</div>

<script>
(function () {
    var el = document.getElementById('saudacao-coordenador');
    if (!el) return;
    var hora = new Date().getHours();
    var saudacao = hora < 12 ? 'Bom dia' : (hora < 18 ? 'Boa tarde' : 'Boa noite');
    el.textContent = saudacao + ', ' + el.getAttribute('data-nome');
})();
</script>
