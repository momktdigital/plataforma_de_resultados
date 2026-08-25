/**
 * Campo de múltiplos valores em formato de "chips" (como as tags do
 * YouTube): digite um valor e pressione vírgula ou Enter para transformá-lo
 * num card removível (× remove). Cada chip carrega um <input type="hidden">
 * com name="{campo}[]" — o formulário envia um array normal no submit.
 *
 * Marcação esperada (ver admin/avaliacoes/_tag_input.blade.php):
 *   <div data-tag-input data-name="algum_campo">
 *       <input class="tag-input-text" type="text">
 *   </div>
 */
(function () {
    function criarChip(container, valor) {
        var nome = container.dataset.name;

        var chip = document.createElement('span');
        chip.className = 'tag-input-chip inline-flex items-center gap-1 bg-slate-100 text-slate-700 text-xs font-medium rounded-full pl-2.5 pr-1 py-1';

        var texto = document.createElement('span');
        texto.textContent = valor;
        chip.appendChild(texto);

        var remover = document.createElement('button');
        remover.type = 'button';
        remover.className = 'tag-input-chip-remove hover:bg-slate-200 rounded-full w-4 h-4 flex items-center justify-center leading-none';
        remover.setAttribute('aria-label', 'Remover ' + valor);
        remover.textContent = '×';
        remover.addEventListener('click', function () {
            chip.remove();
        });
        chip.appendChild(remover);

        var oculto = document.createElement('input');
        oculto.type = 'hidden';
        oculto.name = nome + '[]';
        oculto.value = valor;
        chip.appendChild(oculto);

        var campoTexto = container.querySelector('.tag-input-text');
        container.insertBefore(chip, campoTexto);
    }

    function limparChips(container) {
        container.querySelectorAll('.tag-input-chip').forEach(function (chip) {
            chip.remove();
        });
    }

    function definirValores(container, valores) {
        limparChips(container);
        (valores || []).forEach(function (valor) {
            var texto = valor === null || valor === undefined ? '' : String(valor).trim();
            if (texto !== '') {
                criarChip(container, texto);
            }
        });
    }

    function confirmarPendente(container) {
        var campoTexto = container.querySelector('.tag-input-text');
        var valor = campoTexto.value.trim();
        if (valor !== '') {
            criarChip(container, valor);
        }
        campoTexto.value = '';
    }

    document.querySelectorAll('[data-tag-input]').forEach(function (container) {
        var campoTexto = container.querySelector('.tag-input-text');

        campoTexto.addEventListener('keydown', function (evento) {
            if (evento.key === ',' || evento.key === 'Enter') {
                evento.preventDefault();
                confirmarPendente(container);
            } else if (evento.key === 'Backspace' && campoTexto.value === '') {
                var chips = container.querySelectorAll('.tag-input-chip');
                if (chips.length) {
                    chips[chips.length - 1].remove();
                }
            }
        });

        campoTexto.addEventListener('blur', confirmarPendente.bind(null, container));

        container.addEventListener('click', function (evento) {
            if (evento.target === container) {
                campoTexto.focus();
            }
        });
    });

    window.TagInput = {
        setValues: function (nome, valores) {
            var container = document.querySelector('[data-tag-input][data-name="' + nome + '"]');
            if (container) {
                definirValores(container, valores);
            }
        },
        clearAll: function () {
            document.querySelectorAll('[data-tag-input]').forEach(limparChips);
        },
    };
})();
