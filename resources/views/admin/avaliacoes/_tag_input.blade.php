{{--
    Campo de múltiplos valores em formato de "chips" (digite e pressione
    vírgula/Enter — cada valor vira um card removível com ×), ver
    public/js/tag-input.js. Espera: $name, $label, $placeholder (opcional).
--}}
<div>
    <label class="block text-sm font-medium mb-1">{{ $label }}</label>
    <div data-tag-input data-name="{{ $name }}"
         class="tag-input flex flex-wrap items-center gap-1.5 w-full rounded-lg border border-slate-300 px-2 py-1.5 min-h-[42px] focus-within:ring-2 focus-within:ring-emerald-500 focus-within:border-emerald-500">
        <input type="text" class="tag-input-text flex-1 min-w-[100px] border-0 focus:ring-0 text-sm p-1"
               placeholder="{{ $placeholder ?? 'Digite e pressione vírgula ou Enter' }}">
    </div>
</div>
