{{-- Marca como inválidos (aria-invalid) os campos que o servidor recusou, liga cada um ao resumo de erros
     (aria-describedby) e leva o foco ao primeiro — sem isso, quem usa leitor de tela só descobre que o envio falhou
     ao navegar pela página. O resumo é o elemento com [data-resumo-erros] (partials/flash e telas de login). --}}
@if ($errors->any())
<script>
document.addEventListener('DOMContentLoaded', function () {
    var campos = {{ Js::from(array_values(array_unique(array_map(fn ($chave) => explode('.', $chave)[0], $errors->keys())))) }};
    var resumo = document.querySelector('[data-resumo-erros]');
    var primeiro = null;

    campos.forEach(function (nome) {
        var campo = document.querySelector('[name="' + nome + '"]') || document.querySelector('[name="' + nome + '[]"]');
        if (!campo || campo.type === 'hidden') return;
        campo.setAttribute('aria-invalid', 'true');
        if (resumo && resumo.id) {
            campo.setAttribute('aria-describedby', ((campo.getAttribute('aria-describedby') || '') + ' ' + resumo.id).trim());
        }
        primeiro = primeiro || campo;
    });

    if (primeiro && typeof primeiro.focus === 'function' && !document.activeElement.matches('input, select, textarea')) {
        primeiro.focus();
    }
});
</script>
@endif
