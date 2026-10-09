/**
 * Tour guiado do portal do aluno: um popup que destaca, um a um, os elementos da tela e explica para que servem.
 *
 * Cada página declara os passos num <script type="application/json" id="portal-tour-dados"> (ver
 * resources/views/portal/_tour.blade.php): { chave, passos: [{ alvo, titulo, texto }] }. Passo sem `alvo` é um quadro
 * central (boas-vindas); passo cujo alvo não existe ou está oculto na tela (ex.: um painel que a avaliação não libera)
 * é pulado sozinho — o tour nunca aponta para o vazio.
 *
 * Abre sozinho na PRIMEIRA visita de cada página (registro no localStorage do navegador, uma chave por página) e pode ser
 * refeito a qualquer momento pelo botão "Refazer tour da página" do rodapé (#portal-refazer-tour).
 *
 * Acessibilidade: diálogo modal com foco preso, setas/Enter/Esc, anúncio do passo por leitor de tela e sem animação
 * quando o usuário pede movimento reduzido. Sem dependências.
 */
(function () {
    'use strict';

    var dadosEl = document.getElementById('portal-tour-dados');
    if (!dadosEl) return;

    var tour;
    try {
        tour = JSON.parse(dadosEl.textContent);
    } catch (e) {
        return;
    }
    if (!tour || !tour.chave || !Array.isArray(tour.passos) || tour.passos.length === 0) return;

    var CHAVE_STORAGE = 'portal_tour_v1_' + tour.chave;
    var semMovimento = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var MARGEM_DESTAQUE = 6;
    var ESPACO = 12;

    var passos = [];
    var indice = 0;
    var aberto = false;
    var focoAnterior = null;
    var elementos = {};

    function lerVisto() {
        try { return window.localStorage.getItem(CHAVE_STORAGE) === '1'; } catch (e) { return false; }
    }

    function marcarVisto() {
        try { window.localStorage.setItem(CHAVE_STORAGE, '1'); } catch (e) { /* navegação privada: o tour só reaparece */ }
    }

    function visivel(el) {
        if (!el || el.getClientRects().length === 0) return false;
        var estilo = window.getComputedStyle(el);

        return estilo.visibility !== 'hidden' && estilo.display !== 'none';
    }

    function alvoDoPasso(passo) {
        return passo.alvo ? document.querySelector(passo.alvo) : null;
    }

    function passosAplicaveis() {
        return tour.passos.filter(function (passo) {
            return !passo.alvo || visivel(alvoDoPasso(passo));
        });
    }

    function criar(tag, atributos, texto) {
        var el = document.createElement(tag);
        Object.keys(atributos || {}).forEach(function (k) { el.setAttribute(k, atributos[k]); });
        if (texto !== undefined) el.textContent = texto;

        return el;
    }

    function montar() {
        var fundo = criar('div', { 'class': 'portal-tour-fundo', 'aria-hidden': 'true' });
        var destaque = criar('div', { 'class': 'portal-tour-destaque', 'aria-hidden': 'true' });
        var dialogo = criar('div', {
            'class': 'portal-tour-dialogo',
            role: 'dialog',
            'aria-modal': 'true',
            'aria-labelledby': 'portal-tour-titulo',
            'aria-describedby': 'portal-tour-texto',
            tabindex: '-1',
        });

        var contador = criar('p', { 'class': 'portal-tour-contador' });
        var titulo = criar('h2', { id: 'portal-tour-titulo', 'class': 'portal-tour-titulo' });
        var texto = criar('p', { id: 'portal-tour-texto', 'class': 'portal-tour-texto' });
        var rodape = criar('div', { 'class': 'portal-tour-botoes' });
        var pular = criar('button', { type: 'button', 'class': 'portal-tour-pular' }, 'Pular tour');
        var grupo = criar('div', { 'class': 'portal-tour-grupo' });
        var anterior = criar('button', { type: 'button', 'class': 'portal-tour-secundario' }, 'Anterior');
        var proximo = criar('button', { type: 'button', 'class': 'portal-tour-primario' }, 'Próximo');
        var aviso = criar('div', { 'class': 'portal-tour-sr', 'aria-live': 'polite', role: 'status' });

        grupo.appendChild(anterior);
        grupo.appendChild(proximo);
        rodape.appendChild(pular);
        rodape.appendChild(grupo);
        dialogo.appendChild(contador);
        dialogo.appendChild(titulo);
        dialogo.appendChild(texto);
        dialogo.appendChild(rodape);

        document.body.appendChild(fundo);
        document.body.appendChild(destaque);
        document.body.appendChild(dialogo);
        document.body.appendChild(aviso);

        pular.addEventListener('click', encerrar);
        anterior.addEventListener('click', function () { ir(indice - 1); });
        proximo.addEventListener('click', function () {
            if (indice >= passos.length - 1) encerrar(); else ir(indice + 1);
        });

        elementos = {
            fundo: fundo, destaque: destaque, dialogo: dialogo, contador: contador, titulo: titulo,
            texto: texto, pular: pular, anterior: anterior, proximo: proximo, aviso: aviso,
        };
    }

    function posicionar() {
        if (!aberto) return;

        var passo = passos[indice];
        var alvo = alvoDoPasso(passo);
        var d = elementos.dialogo;
        var destaque = elementos.destaque;
        var larguraTela = document.documentElement.clientWidth;
        var alturaTela = window.innerHeight;

        // Quadro central: sem destaque, diálogo no meio da tela.
        if (!alvo || !visivel(alvo)) {
            destaque.style.display = 'none';
            elementos.fundo.classList.add('portal-tour-fundo--cheio');
            d.style.top = '50%';
            d.style.left = '50%';
            d.style.transform = 'translate(-50%, -50%)';
            d.classList.remove('portal-tour-dialogo--rodape');

            return;
        }

        elementos.fundo.classList.remove('portal-tour-fundo--cheio');
        d.style.transform = 'none';

        var r = alvo.getBoundingClientRect();
        destaque.style.display = 'block';
        destaque.style.top = (r.top - MARGEM_DESTAQUE) + 'px';
        destaque.style.left = (r.left - MARGEM_DESTAQUE) + 'px';
        destaque.style.width = (r.width + MARGEM_DESTAQUE * 2) + 'px';
        destaque.style.height = (r.height + MARGEM_DESTAQUE * 2) + 'px';

        // Telas estreitas: o diálogo vira uma folha fixa no pé da tela (nunca cobre o destaque se der para evitar).
        if (larguraTela < 640) {
            d.classList.add('portal-tour-dialogo--rodape');
            d.style.top = 'auto';
            d.style.left = '0';

            return;
        }
        d.classList.remove('portal-tour-dialogo--rodape');

        var lar = d.offsetWidth;
        var alt = d.offsetHeight;
        var abaixo = r.bottom + MARGEM_DESTAQUE + ESPACO;
        var acima = r.top - MARGEM_DESTAQUE - ESPACO - alt;
        var top;

        if (abaixo + alt <= alturaTela - ESPACO) {
            top = abaixo;
        } else if (acima >= ESPACO) {
            top = acima;
        } else {
            // Alvo muito alto: o diálogo vai ao lado ou sobre a parte de baixo dele, sempre dentro da tela.
            top = Math.max(ESPACO, Math.min(alturaTela - alt - ESPACO, r.top + ESPACO));
        }

        var left = r.left + r.width / 2 - lar / 2;
        left = Math.max(ESPACO, Math.min(left, larguraTela - lar - ESPACO));

        d.style.top = top + 'px';
        d.style.left = left + 'px';
    }

    function mostrar() {
        var passo = passos[indice];
        var ultimo = indice === passos.length - 1;

        elementos.contador.textContent = 'Passo ' + (indice + 1) + ' de ' + passos.length;
        elementos.titulo.textContent = passo.titulo || '';
        elementos.texto.textContent = passo.texto || '';
        elementos.anterior.hidden = indice === 0;
        elementos.proximo.textContent = ultimo ? 'Concluir' : 'Próximo';
        elementos.pular.hidden = ultimo;
        elementos.aviso.textContent = 'Passo ' + (indice + 1) + ' de ' + passos.length + ': ' + (passo.titulo || '');

        var alvo = alvoDoPasso(passo);
        if (alvo && visivel(alvo)) {
            alvo.scrollIntoView({ block: 'center', behavior: semMovimento ? 'auto' : 'smooth' });
        }

        // Duas passadas: a primeira já posiciona; a segunda corrige depois que a rolagem suave termina.
        posicionar();
        window.setTimeout(posicionar, semMovimento ? 0 : 350);

        elementos.proximo.focus({ preventScroll: true });
    }

    function ir(novo) {
        if (novo < 0 || novo >= passos.length) return;
        indice = novo;
        mostrar();
    }

    function aoTeclar(evento) {
        if (!aberto) return;

        if (evento.key === 'Escape') {
            evento.preventDefault();
            encerrar();
        } else if (evento.key === 'ArrowRight') {
            evento.preventDefault();
            if (indice < passos.length - 1) ir(indice + 1);
        } else if (evento.key === 'ArrowLeft') {
            evento.preventDefault();
            ir(indice - 1);
        } else if (evento.key === 'Tab') {
            // Foco preso no diálogo: Tab circula só entre os botões visíveis dele.
            var focaveis = Array.prototype.filter.call(
                elementos.dialogo.querySelectorAll('button'),
                function (b) { return !b.hidden; }
            );
            if (focaveis.length === 0) return;
            var primeiro = focaveis[0];
            var ultimo = focaveis[focaveis.length - 1];

            if (evento.shiftKey && document.activeElement === primeiro) {
                evento.preventDefault();
                ultimo.focus();
            } else if (!evento.shiftKey && document.activeElement === ultimo) {
                evento.preventDefault();
                primeiro.focus();
            } else if (!elementos.dialogo.contains(document.activeElement)) {
                evento.preventDefault();
                primeiro.focus();
            }
        }
    }

    function iniciar() {
        if (aberto) return;

        passos = passosAplicaveis();
        if (passos.length === 0) return;

        focoAnterior = document.activeElement;
        if (!elementos.dialogo) montar();

        aberto = true;
        indice = 0;
        elementos.fundo.hidden = false;
        elementos.dialogo.hidden = false;
        elementos.aviso.hidden = false;
        document.addEventListener('keydown', aoTeclar, true);
        window.addEventListener('resize', posicionar);
        window.addEventListener('scroll', posicionar, true);
        mostrar();
    }

    function encerrar() {
        if (!aberto) return;

        aberto = false;
        marcarVisto();
        elementos.fundo.hidden = true;
        elementos.destaque.style.display = 'none';
        elementos.dialogo.hidden = true;
        elementos.aviso.textContent = '';
        document.removeEventListener('keydown', aoTeclar, true);
        window.removeEventListener('resize', posicionar);
        window.removeEventListener('scroll', posicionar, true);

        if (focoAnterior && typeof focoAnterior.focus === 'function') {
            focoAnterior.focus({ preventScroll: true });
        }
    }

    function aoCarregar() {
        var botao = document.getElementById('portal-refazer-tour');
        if (botao) botao.addEventListener('click', iniciar);

        // Primeira visita a esta página: abre sozinho, depois de a tela (fontes, gráficos, barra de acessibilidade) assentar.
        if (!lerVisto()) window.setTimeout(iniciar, 800);
    }

    if (document.readyState === 'complete') {
        aoCarregar();
    } else {
        window.addEventListener('load', aoCarregar);
    }
})();
