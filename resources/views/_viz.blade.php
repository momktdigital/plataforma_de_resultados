{{-- Camada visual comum dos gráficos (BI e portal do aluno).

     Inclua SEMPRE depois da tag <script> do Chart.js da tela — este partial
     não carrega a biblioteca, só ajusta os padrões dela e expõe `Viz`. Antes
     disto cada `new Chart()` repetia cor, fonte e tooltip na mão, e os
     valores divergiam entre telas.

     A paleta não é escolha de gosto: foi validada para daltonismo sobre o
     fundo branco dos cartões (ΔE CVD 10,7 e contraste ≥ 3:1 nos três tons de
     série). Trocar um tom aqui exige revalidar — não basta "parecer bonito",
     porque a diferença entre as séries é o que um leitor com deuteranopia
     usa para ler o gráfico. --}}
<script>
window.Viz = (function () {
    'use strict';

    var cores = {
        // Séries categóricas, nesta ordem — nunca gere uma 4ª cor: agrupe o
        // resto em "Outros" ou quebre em vários gráficos.
        serie1: '#12a37f',
        serie2: '#2a78d6',
        serie3: '#eb6834',

        // Estado (bom/atenção/grave/crítico). Reservadas: nunca usar como
        // "mais uma série", senão um vermelho de série vira falso alarme.
        bom: '#0ca30c',
        atencao: '#fab219',
        grave: '#ec835a',
        critico: '#d03b3b',

        tinta: '#0f1720',
        tinta2: '#566270',
        tinta3: '#8a939c',
        grade: '#e3e9e7',
        superficie: '#ffffff',
    };

    // Rampa de UMA cor, do claro ao escuro — para magnitude (mapas de calor).
    var sequencial = ['#e2f4ee', '#b9e5d7', '#7ed2b9', '#3fb99b', '#12a37f', '#0a7159'];

    var semMovimento = window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (window.Chart) {
        Chart.defaults.font.family = 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif';
        Chart.defaults.font.size = 12;
        Chart.defaults.color = cores.tinta2;
        Chart.defaults.borderColor = cores.grade;

        Chart.defaults.animation.duration = semMovimento ? 0 : 700;
        Chart.defaults.animation.easing = 'easeOutQuart';

        // Alvo de leitura generoso: o ponteiro só precisa estar PERTO da
        // marca, não em cima dela (um ponto de 4px é impossível de acertar).
        Chart.defaults.interaction.mode = 'nearest';
        Chart.defaults.interaction.intersect = false;

        Chart.defaults.plugins.tooltip.backgroundColor = cores.tinta;
        Chart.defaults.plugins.tooltip.padding = 10;
        Chart.defaults.plugins.tooltip.cornerRadius = 8;
        Chart.defaults.plugins.tooltip.titleFont = { weight: '600', size: 12 };
        Chart.defaults.plugins.tooltip.bodyFont = { size: 12 };
        Chart.defaults.plugins.tooltip.boxPadding = 4;
        Chart.defaults.plugins.tooltip.usePointStyle = true;

        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.plugins.legend.labels.boxWidth = 8;
        Chart.defaults.plugins.legend.labels.padding = 14;

        // Marcas finas, com a ponta arredondada encostada na linha de base.
        Chart.defaults.elements.bar.borderRadius = 4;
        Chart.defaults.elements.bar.borderSkipped = 'bottom';
        Chart.defaults.elements.line.tension = 0.25;
        Chart.defaults.elements.line.borderWidth = 2;
        Chart.defaults.elements.point.radius = 4;
        Chart.defaults.elements.point.hoverRadius = 6;
        Chart.defaults.elements.point.borderWidth = 2;
        Chart.defaults.elements.point.borderColor = cores.superficie;

        Chart.defaults.scale.grid.color = cores.grade;
        Chart.defaults.scale.grid.drawTicks = false;
        Chart.defaults.scale.border = Chart.defaults.scale.border || {};
        Chart.defaults.scale.border.color = cores.grade;
    }

    /** Cor da rampa sequencial para um valor de 0 a 100. */
    function corSequencial(valor) {
        if (valor === null || valor === undefined || isNaN(valor)) {
            return cores.grade;
        }
        var i = Math.floor(valor / 100 * sequencial.length);

        return sequencial[Math.max(0, Math.min(sequencial.length - 1, i))];
    }

    /** Texto legível sobre a cor devolvida por corSequencial(). */
    function tintaSobreSequencial(valor) {
        return (valor !== null && valor !== undefined && valor >= 66) ? '#ffffff' : cores.tinta;
    }

    /** Mostra/esconde a tabela equivalente de um gráfico (acessibilidade). */
    function alternarTabela(botao) {
        var alvo = document.getElementById(botao.getAttribute('data-tabela'));
        if (!alvo) {
            return;
        }
        var aberta = alvo.hasAttribute('hidden') === false;
        if (aberta) {
            alvo.setAttribute('hidden', '');
        } else {
            alvo.removeAttribute('hidden');
        }
        botao.setAttribute('aria-expanded', aberta ? 'false' : 'true');
        botao.textContent = aberta ? 'Ver como tabela' : 'Ocultar tabela';
    }

    /**
     * Reescreve a LEITURA de uma explicação sem recarregar a página — para os
     * visuais que mudam com clique ou filtro (ver $id em _explicacao.blade.php).
     * Os rótulos/classes aqui têm que bater com o mapa $tons do parcial.
     */
    var tons = {
        bom: { rotulo: 'Bom sinal', classe: 'text-emerald-700 bg-emerald-50' },
        atencao: { rotulo: 'Atenção', classe: 'text-amber-700 bg-amber-50' },
        ruim: { rotulo: 'Precisa de ação', classe: 'text-red-700 bg-red-50' },
    };

    function escreverLeitura(id, texto, tom) {
        var wrapper = document.getElementById(id + '-leitura');
        var badge = document.getElementById(id + '-tom');
        var paragrafo = document.getElementById(id + '-texto');
        if (!wrapper || !badge || !paragrafo) {
            return;
        }

        if (!texto) {
            wrapper.classList.add('hidden');

            return;
        }

        wrapper.classList.remove('hidden');
        paragrafo.textContent = texto;

        badge.className = 'explicacao-tom inline-block text-[10px] font-bold uppercase tracking-wide px-1.5 py-0.5 rounded '
            + ((tons[tom] && tons[tom].classe) || 'text-slate-600 bg-slate-100');
        badge.textContent = (tons[tom] && tons[tom].rotulo) || '';
        if (!tons[tom]) {
            badge.classList.add('hidden');
        }
    }

    /**
     * Popover "o que isso significa" (ver _explicacao.blade.php). Listener
     * delegado num lugar só: o parcial se repete dezenas de vezes por página,
     * e tanto o BI quanto o boletim do aluno usam o mesmo markup.
     */
    function posicionarExplicacao(botao, conteudo) {
        var rect = botao.getBoundingClientRect();
        var largura = conteudo.offsetWidth;
        var left = Math.max(8, Math.min(rect.right - largura, window.innerWidth - largura - 8));
        conteudo.style.top = (rect.bottom + 4) + 'px';
        conteudo.style.left = left + 'px';
    }

    function fecharExplicacoes() {
        document.querySelectorAll('.explicacao-conteudo').forEach(function (c) { c.hidden = true; });
    }

    document.addEventListener('click', function (evento) {
        var botao = evento.target.closest('[data-tabela]');
        if (botao) {
            alternarTabela(botao);

            return;
        }

        var toggle = evento.target.closest('.explicacao-toggle');
        if (toggle) {
            var conteudo = toggle.nextElementSibling;
            var estavaAberto = !conteudo.hidden;
            fecharExplicacoes();
            if (!estavaAberto) {
                conteudo.hidden = false;
                posicionarExplicacao(toggle, conteudo);
            }
            evento.stopPropagation();

            return;
        }

        if (!evento.target.closest('.explicacao-conteudo')) {
            fecharExplicacoes();
        }
    });

    // position:fixed é relativo à VIEWPORT: sem isto o popover ficaria
    // "grudado" na tela depois que o botão já rolou para outro lugar.
    // capture=true para pegar rolagem de containers internos também.
    document.addEventListener('scroll', fecharExplicacoes, true);

    return {
        cores: cores,
        sequencial: sequencial,
        corSequencial: corSequencial,
        tintaSobreSequencial: tintaSobreSequencial,
        semMovimento: semMovimento,
        escreverLeitura: escreverLeitura,
    };
})();
</script>
