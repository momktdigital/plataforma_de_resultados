{{--
    Campos para anexar uma evidência (link e/ou arquivo) num formulário de acompanhamento (que precisa de enctype="multipart/form-data").
    Variável: $prefixo (para os ids dos campos).
--}}
@php $campoEvidencia = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary'; @endphp
<fieldset class="rounded-lg border border-slate-200 bg-white p-3">
    <legend class="px-1 text-xs font-semibold text-slate-700">Evidência (opcional)</legend>
    <p class="text-xs text-slate-500 mb-2">Um link (pasta compartilhada, formulário, vídeo) e/ou um arquivo ({{ implode(', ', \App\Models\PlanoAcaoAnexo::EXTENSOES) }}; até {{ \App\Models\PlanoAcaoAnexo::MAXIMO_KB / 1024 }} MB): lista de presença, relatório, imagem.</p>
    <div class="grid gap-3 sm:grid-cols-2">
        <div>
            <label for="{{ $prefixo }}-link-url" class="block text-xs font-semibold text-slate-700 mb-1">Link</label>
            <input id="{{ $prefixo }}-link-url" name="link_url" type="url" maxlength="500" placeholder="https://..." value="{{ old('link_url') }}" class="{{ $campoEvidencia }}">
        </div>
        <div>
            <label for="{{ $prefixo }}-link-titulo" class="block text-xs font-semibold text-slate-700 mb-1">Título da evidência</label>
            <input id="{{ $prefixo }}-link-titulo" name="link_titulo" type="text" maxlength="190" placeholder="Ex.: Lista de presença da oficina" value="{{ old('link_titulo') }}" class="{{ $campoEvidencia }}">
        </div>
        <div class="sm:col-span-2">
            <label for="{{ $prefixo }}-arquivo" class="block text-xs font-semibold text-slate-700 mb-1">Arquivo</label>
            <input id="{{ $prefixo }}-arquivo" name="arquivo" type="file" accept="{{ '.'.implode(',.', \App\Models\PlanoAcaoAnexo::EXTENSOES) }}" class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-slate-700 hover:file:bg-slate-200">
        </div>
    </div>
</fieldset>
