@extends('layouts.app')

@section('title', 'Configurações — Avaliações')

@section('content')
<h1 class="text-2xl font-bold mb-6">Configurações do sistema</h1>

@include('admin.sistema._subnav')

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 max-w-xl">
    <form method="POST" action="{{ route('sistema.configuracoes.update') }}" class="space-y-5">
        @csrf

        <div>
            <p class="block text-sm font-medium mb-1">Repositório do GitHub para atualizações</p>
            <p class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-mono text-slate-700">{{ $atualizacaoRepositorio }}</p>
            <p class="text-xs text-slate-600 mt-1">
                Definido no servidor (<code>ATUALIZACAO_REPOSITORIO</code> no arquivo <code>.env</code>) e não pode ser alterado por aqui:
                o atualizador baixa e executa o código desse repositório.
            </p>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1" for="backup_manter_ultimos">
                Quantos backups manter
            </label>
            <input id="backup_manter_ultimos" name="backup_manter_ultimos" type="number" min="1" max="50" required
                   value="{{ old('backup_manter_ultimos', $backupManterUltimos) }}"
                   class="w-32 rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <p class="text-xs text-slate-500 mt-1">Backups mais antigos que isso são apagados automaticamente a cada novo backup gerado.</p>
        </div>

        <fieldset class="border-t border-slate-200 pt-5">
            <legend class="text-sm font-semibold text-slate-800 pr-2">Painel da reitoria</legend>

            <div class="mt-3">
                <label class="block text-sm font-medium mb-1" for="reitor_corte_proficiencia">Critério de proficiência (% de acerto)</label>
                <input id="reitor_corte_proficiencia" name="reitor_corte_proficiencia" type="number" min="30" max="90" step="1" required
                       value="{{ old('reitor_corte_proficiencia', $reitorCorte) }}"
                       class="w-32 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <p class="text-xs text-slate-600 mt-1">O estudante é "proficiente" quando acerta esse percentual ou mais da prova. É um critério interno, sem validação contra exames externos (ENADE/ENAMED). Padrão: 60.</p>
            </div>

            <div class="mt-4">
                <label class="block text-sm font-medium mb-1" for="reitor_meta_participacao">Meta de participação (% dos previstos)</label>
                <input id="reitor_meta_participacao" name="reitor_meta_participacao" type="number" min="50" max="100" step="0.1" required
                       value="{{ old('reitor_meta_participacao', $reitorMeta) }}"
                       class="w-32 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <p class="text-xs text-slate-600 mt-1">Quantos por cento dos estudantes previstos devem fazer a avaliação. Padrão: 98.</p>
            </div>
        </fieldset>

        <fieldset class="border-t border-slate-200 pt-5">
            <legend class="text-sm font-semibold text-slate-800 pr-2">Estudante em risco</legend>
            <input type="hidden" name="risco_enviado" value="1">
            <p class="text-xs text-slate-600 mt-1 max-w-2xl">
                Define quem aparece como "em atenção" na lista de alunos do coordenador e quantos estudantes entram na conta de
                risco do painel da reitoria. Marque um critério ou os dois. Cada avaliação pode ter uma regra própria
                (Avaliações &rarr; abrir a avaliação &rarr; Estudante em risco).
            </p>
            @error('risco_acerto_ativo')
                <p class="text-sm text-red-700 mt-2" role="alert">{{ $message }}</p>
            @enderror

            <div class="mt-3 flex flex-wrap items-center gap-2">
                <input id="risco_acerto_ativo" name="risco_acerto_ativo" type="checkbox" value="1" class="rounded border-slate-300"
                       {{ old('risco_enviado') ? (old('risco_acerto_ativo') ? 'checked' : '') : ($regraDeRisco->acerto !== null ? 'checked' : '') }}>
                <label for="risco_acerto_ativo" class="text-sm font-medium">Percentual de acerto: a média do estudante fica abaixo de</label>
                <input id="risco_acerto" name="risco_acerto" type="text" inputmode="decimal" aria-label="Percentual de acerto abaixo do qual o estudante está em risco"
                       value="{{ old('risco_acerto', $regraDeRisco->acerto !== null ? \App\Support\RegraDeRisco::numero($regraDeRisco->acerto) : '60') }}"
                       class="w-20 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <span class="text-sm">%</span>
            </div>
            @error('risco_acerto')
                <p class="text-sm text-red-700 mt-1" role="alert">{{ $message }}</p>
            @enderror
            <p class="text-xs text-slate-600 mt-1 ml-6">Só conta as provas em que o estudante esteve presente. Padrão: 60.</p>

            <div class="mt-3 flex flex-wrap items-center gap-2">
                <input id="risco_faltas_ativo" name="risco_faltas_ativo" type="checkbox" value="1" class="rounded border-slate-300"
                       {{ old('risco_enviado') ? (old('risco_faltas_ativo') ? 'checked' : '') : ($regraDeRisco->faltas !== null ? 'checked' : '') }}>
                <label for="risco_faltas_ativo" class="text-sm font-medium">Faltas: o estudante faltou a</label>
                <input id="risco_faltas" name="risco_faltas" type="number" min="1" max="50" step="1" aria-label="Número de faltas a partir do qual o estudante está em risco"
                       value="{{ old('risco_faltas', $regraDeRisco->faltas ?? 2) }}"
                       class="w-20 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <span class="text-sm">aplicações ou mais</span>
            </div>
            @error('risco_faltas')
                <p class="text-sm text-red-700 mt-1" role="alert">{{ $message }}</p>
            @enderror
            <p class="text-xs text-slate-600 mt-1 ml-6">Falta é a prova inteira em branco. Com "1", uma única falta já coloca o estudante em risco. Padrão: 2.</p>

            <div class="mt-4" role="radiogroup" aria-label="Como combinar os critérios">
                <p class="text-sm font-medium mb-1">Quando os dois critérios estão marcados</p>
                @foreach (\App\Support\RegraDeRisco::OPERADORES as $valor => $rotulo)
                    <label class="mr-5 inline-flex items-center gap-2 text-sm">
                        <input type="radio" name="risco_operador" value="{{ $valor }}" class="border-slate-300"
                               {{ old('risco_operador', $regraDeRisco->operador) === $valor ? 'checked' : '' }}>
                        {{ $valor === 'ou' ? 'Basta um dos dois (acerto ou faltas)' : 'Os dois ao mesmo tempo (acerto e faltas)' }}
                    </label>
                @endforeach
            </div>

            <p class="mt-3 text-xs text-slate-600">Regra em vigor: <strong>{{ $regraDeRisco->descricao() }}</strong>.</p>
        </fieldset>

        <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-5 py-2 text-sm">
            Salvar
        </button>
    </form>
</div>
@endsection
