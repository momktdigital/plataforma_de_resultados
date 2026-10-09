{{--
    Campos de uma pendência (a mesma ordem da planilha): pendência, encaminhamento, prazo, situação e responsável.
    Variáveis: $p (CronogramaPendencia ou null, para registrar uma nova), $sufixo (id único), $campo (classes do campo).
    No formulário de edição os valores antigos (old) só valem quando o erro veio DESTA pendência — senão uma pendência
    com erro de validação sobrescreveria o texto de todas as outras na tela.
--}}
@php
    $usarOld = $p === null ? ! old('_pendencia') : (int) old('_pendencia') === $p->id;
    $valor = fn (string $nome, $padrao) => $usarOld ? old($nome, $padrao) : $padrao;
@endphp
@if ($p)<input type="hidden" name="_pendencia" value="{{ $p->id }}">@endif
<div>
    <label class="block text-sm font-medium mb-1" for="pendencia-{{ $sufixo }}">Pendência</label>
    <textarea id="pendencia-{{ $sufixo }}" name="pendencia" required maxlength="2000" rows="2" placeholder="Ex.: 2 provas pendentes" class="{{ $campo }}">{{ $valor('pendencia', $p?->pendencia) }}</textarea>
</div>
<div>
    <label class="block text-sm font-medium mb-1" for="encaminhamento-{{ $sufixo }}">Encaminhamento</label>
    <textarea id="encaminhamento-{{ $sufixo }}" name="encaminhamento" maxlength="2000" rows="2" placeholder="Ex.: Coordenação acionada" class="{{ $campo }}">{{ $valor('encaminhamento', $p?->encaminhamento) }}</textarea>
</div>
<div class="grid gap-3 sm:grid-cols-3">
    <div>
        <label class="block text-sm font-medium mb-1" for="prazo-{{ $sufixo }}">Prazo</label>
        <input id="prazo-{{ $sufixo }}" name="prazo" type="date" value="{{ $valor('prazo', $p?->prazo?->toDateString()) }}" class="{{ $campo }}">
    </div>
    <div>
        <label class="block text-sm font-medium mb-1" for="status-pendencia-{{ $sufixo }}">Situação</label>
        <select id="status-pendencia-{{ $sufixo }}" name="status" required class="{{ $campo }}">
            @foreach (\App\Models\CronogramaPendencia::STATUS as $chave => $rotulo)
                <option value="{{ $chave }}" @selected($valor('status', $p?->status ?? 'pendente') === $chave)>{{ $rotulo }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium mb-1" for="responsavel-{{ $sufixo }}">Responsável</label>
        <input id="responsavel-{{ $sufixo }}" name="responsavel" type="text" maxlength="120" value="{{ $valor('responsavel', $p?->responsavel) }}" class="{{ $campo }}">
    </div>
</div>
