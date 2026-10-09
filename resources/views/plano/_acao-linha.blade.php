{{--
    Uma ação pedagógica do plano (um bloco de campos). Usada no formulário e no <template> de "adicionar outra ação" (com
    $i = '__I__', trocado pelo script). Variáveis: $i, $acao (array), $campo, $etiqueta, $dica (classes).
--}}
<fieldset class="acao-linha rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-3" data-acao>
    <div class="flex items-center justify-between gap-2">
        <legend class="text-sm font-bold" data-titulo-acao>Ação</legend>
        <button type="button" data-remover-acao class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
            <i class="ph-bold ph-trash" aria-hidden="true"></i> Remover
        </button>
    </div>
    <input type="hidden" name="acoes[{{ $i }}][id]" value="{{ $acao['id'] ?? '' }}">
    <div>
        <label for="acao-{{ $i }}-descricao" class="{{ $etiqueta }}">Ação pedagógica <span class="font-normal text-slate-500">(inicie com um verbo no infinitivo)</span></label>
        <textarea id="acao-{{ $i }}-descricao" name="acoes[{{ $i }}][descricao]" rows="2" maxlength="2000" placeholder="Ex.: Implementar atividades integradoras com situações-problema e feedback" class="{{ $campo }}">{{ $acao['descricao'] ?? '' }}</textarea>
    </div>
    <div>
        <label for="acao-{{ $i }}-execucao" class="{{ $etiqueta }}">Como a ação será executada?</label>
        <p class="{{ $dica }}">Etapas, componentes envolvidos, estudantes alcançados e estratégias.</p>
        <textarea id="acao-{{ $i }}-execucao" name="acoes[{{ $i }}][execucao]" rows="3" maxlength="5000" class="{{ $campo }}">{{ $acao['execucao'] ?? '' }}</textarea>
    </div>
    <div class="grid gap-3 sm:grid-cols-2">
        <div>
            <label for="acao-{{ $i }}-responsavel" class="{{ $etiqueta }}">Responsável</label>
            <input id="acao-{{ $i }}-responsavel" name="acoes[{{ $i }}][responsavel]" type="text" maxlength="150" value="{{ $acao['responsavel'] ?? '' }}" placeholder="Nome ou função definida" class="{{ $campo }}">
        </div>
        <div>
            <label for="acao-{{ $i }}-prazo" class="{{ $etiqueta }}">Prazo</label>
            <input id="acao-{{ $i }}-prazo" name="acoes[{{ $i }}][prazo]" type="date" value="{{ $acao['prazo'] ?? '' }}" class="{{ $campo }}">
        </div>
    </div>
    <div>
        <label for="acao-{{ $i }}-verificacao" class="{{ $etiqueta }}">Como verificaremos a execução e sinais de aprendizagem antes da próxima avaliação?</label>
        <p class="{{ $dica }}">Evidências de execução, participação e verificação intermediária.</p>
        <textarea id="acao-{{ $i }}-verificacao" name="acoes[{{ $i }}][verificacao]" rows="3" maxlength="5000" class="{{ $campo }}">{{ $acao['verificacao'] ?? '' }}</textarea>
    </div>
</fieldset>
