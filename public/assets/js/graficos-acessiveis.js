/**
 * Texto alternativo dos gráficos (Chart.js). Um <canvas> é só uma imagem para o leitor de tela; aqui cada gráfico
 * ganha role="img" e um aria-label montado a partir do próprio título da seção e dos dados que ele desenha
 * ("Gráfico de barras: Distribuição de acertos. Respondentes — 0-9%: 3; 10-19%: 5; ...").
 *
 * Roda quando a página termina de carregar (os gráficos são criados por scripts inline no fim da página) e de novo,
 * em silêncio, depois de um clique/alteração — as telas redesenham gráficos ao trocar de aba ou marcar uma caixa.
 */
(function () {
    'use strict';

    var TIPOS = {
        bar: 'barras', line: 'linhas', radar: 'radar', scatter: 'dispersão',
        doughnut: 'rosca', pie: 'pizza', bubble: 'bolhas', polarArea: 'área polar',
    };
    var MAX_PONTOS = 14;

    function tituloDaSecao(canvas) {
        var ancestral = canvas.parentElement;
        while (ancestral) {
            var ultimo = null;
            ancestral.querySelectorAll('h1, h2, h3').forEach(function (h) {
                // só títulos que vêm ANTES do gráfico no documento
                if (h.compareDocumentPosition(canvas) & Node.DOCUMENT_POSITION_FOLLOWING) ultimo = h;
            });
            if (ultimo) return ultimo.textContent.replace(/\s+/g, ' ').trim();
            ancestral = ancestral.parentElement;
        }
        return '';
    }

    function formatar(valor) {
        if (typeof valor === 'number') return String(Math.round(valor * 100) / 100).replace('.', ',');
        if (valor && typeof valor === 'object') {
            return ('x' in valor ? 'x ' + formatar(valor.x) + ', y ' + formatar(valor.y) : JSON.stringify(valor));
        }
        return String(valor);
    }

    function descrever(canvas, grafico) {
        var rotulos = (grafico.data && grafico.data.labels) || [];
        var titulo = canvas.getAttribute('data-titulo') || tituloDaSecao(canvas);
        var series = [];

        var datasets = (grafico.data && grafico.data.datasets) || [];
        datasets.forEach(function (serie, i) {
            var dados = serie.data || [];
            var pontos = [];
            dados.slice(0, MAX_PONTOS).forEach(function (valor, j) {
                if (valor === null || valor === undefined) return;
                pontos.push((rotulos[j] !== undefined ? rotulos[j] + ': ' : '') + formatar(valor));
            });
            if (pontos.length === 0) return;
            var resto = dados.length > MAX_PONTOS ? ' (e mais ' + (dados.length - MAX_PONTOS) + ' pontos)' : '';
            // série sem nome e única (rosca, pizza): lista só os pontos, sem o "Série 1 —" que não diz nada
            var nome = serie.label || (datasets.length > 1 ? 'Série ' + (i + 1) : '');
            series.push((nome ? nome + ' — ' : '') + pontos.join('; ') + resto);
        });

        var texto = 'Gráfico de ' + (TIPOS[grafico.config.type] || 'dados') + (titulo ? ': ' + titulo : '') + '.'
            + (series.length ? ' ' + series.join('. ') + '.' : ' Sem dados para exibir.');

        canvas.setAttribute('role', 'img');
        canvas.setAttribute('aria-label', texto.length > 1200 ? texto.slice(0, 1197) + '...' : texto);
    }

    function descreverTodos() {
        if (!window.Chart || typeof Chart.getChart !== 'function') return;
        document.querySelectorAll('canvas').forEach(function (canvas) {
            var grafico = Chart.getChart(canvas);
            if (grafico) descrever(canvas, grafico);
        });
    }

    var agendado = null;
    function agendar() {
        clearTimeout(agendado);
        agendado = setTimeout(descreverTodos, 500);
    }

    window.descreverGraficos = descreverTodos;
    window.addEventListener('load', descreverTodos);
    document.addEventListener('click', agendar);
    document.addEventListener('change', agendar);
})();
