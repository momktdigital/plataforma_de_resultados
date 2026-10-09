/**
 * Gráficos da tela de desempenho do coordenador (/painel/desempenho): evolução entre as avaliações da categoria e
 * desempenho por área, nível de Bloom e tema — cada um com duas abas, "Geral" e "Por período" (do curso).
 *
 * Os dados vêm de <script type="application/json" id="painel-dados"> ({ <índice da categoria>: { evolucao, area,
 * bloom, tema } }). Cada categoria é um <details>; os gráficos só são criados quando ela é aberta (um canvas dentro de
 * um <details> fechado tem tamanho zero) e recriados a cada troca de aba. Exige o Chart.js já carregado.
 */
(function () {
    'use strict';

    var dadosEl = document.getElementById('painel-dados');
    if (!dadosEl || typeof Chart === 'undefined') return;

    var dados;
    try {
        dados = JSON.parse(dadosEl.textContent);
    } catch (e) {
        return;
    }

    var VERDE = '#10b981';
    var AMARELO = '#f59e0b';
    var ACENTO = '#00b48d';
    var GRADE = '#f1f5f9';
    var LIMIAR = 60;

    function formatar(v) {
        return Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 1 });
    }

    /** Uma cor por período do curso, espaçadas no círculo cromático (o 1º período é sempre a mesma cor). */
    function corDoPeriodo(ordinal) {
        return 'hsl(' + ((ordinal * 47 + 150) % 360) + ', 62%, 42%)';
    }

    function rotuloPeriodo(ordinal) {
        return ordinal + 'º período';
    }

    function limitarRotulo(rotulo, max) {
        return rotulo.length > max ? rotulo.slice(0, max - 1) + '…' : rotulo;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Evolução
    // ---------------------------------------------------------------------------------------------------------

    function desenharEvolucao(canvas, d, aba) {
        var pontos = d.pontos;
        var esperado = d.modo === 'esperado';
        var rotulos = pontos.map(function (p) { return p.nome + (p.periodoLetivo ? ' (' + p.periodoLetivo + ')' : ''); });
        var datasets;

        if (aba === 'periodo') {
            datasets = d.periodos.map(function (ordinal) {
                var cor = corDoPeriodo(ordinal);

                return {
                    label: rotuloPeriodo(ordinal),
                    data: pontos.map(function (p) { return p.porPeriodo[ordinal] ? p.porPeriodo[ordinal].valor : null; }),
                    detalhes: pontos.map(function (p) { return p.porPeriodo[ordinal] ? p.porPeriodo[ordinal].detalhe : null; }),
                    borderColor: cor,
                    backgroundColor: cor,
                    pointBackgroundColor: cor,
                    spanGaps: true,
                    tension: 0.2,
                };
            });
        } else {
            datasets = [{
                label: esperado ? 'Alunos dentro do esperado (%)' : 'Média (%)',
                data: pontos.map(function (p) { return p.geral.valor; }),
                detalhes: pontos.map(function (p) { return p.geral.detalhe; }),
                borderColor: ACENTO,
                backgroundColor: ACENTO,
                pointBackgroundColor: ACENTO,
                // As avaliações do período letivo selecionado aparecem maiores.
                pointRadius: pontos.map(function (p) { return p.noPeriodo ? 7 : 4; }),
                tension: 0.2,
            }];

            if (!esperado) {
                datasets.push({
                    label: 'Referência de ' + LIMIAR + '%',
                    data: pontos.map(function () { return LIMIAR; }),
                    borderColor: '#94a3b8',
                    borderDash: [6, 6],
                    pointRadius: 0,
                });
            }
        }

        return new Chart(canvas, {
            type: 'line',
            data: { labels: rotulos, datasets: datasets },
            options: {
                maintainAspectRatio: false,
                scales: {
                    y: { min: 0, max: 100, grid: { color: GRADE }, title: { display: true, text: esperado ? '% de alunos dentro do esperado' : '% de acerto' } },
                    x: { grid: { display: false } },
                },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var detalhe = ctx.dataset.detalhes ? ctx.dataset.detalhes[ctx.dataIndex] : null;

                                return ctx.dataset.label + ': ' + formatar(ctx.parsed.y) + '%' + (detalhe ? ' (' + detalhe + ')' : '');
                            },
                        },
                    },
                },
            },
        });
    }

    // ---------------------------------------------------------------------------------------------------------
    // Barras (área, Bloom, tema)
    // ---------------------------------------------------------------------------------------------------------

    function desenharBarras(canvas, area, d, aba) {
        var itens = d.geral;
        var rotulos = itens.map(function (i) { return i.rotulo; });
        var datasets;
        var altura;

        if (aba === 'periodo') {
            datasets = d.periodos.map(function (ordinal) {
                var cor = corDoPeriodo(ordinal);

                return {
                    label: rotuloPeriodo(ordinal),
                    data: itens.map(function (i) {
                        var v = d.porPeriodo[ordinal] ? d.porPeriodo[ordinal][i.rotulo] : null;

                        return v === undefined ? null : v;
                    }),
                    backgroundColor: cor,
                    borderRadius: 3,
                    maxBarThickness: 14,
                };
            });
            altura = itens.length * (d.periodos.length * 14 + 18) + 70;
        } else {
            datasets = [{
                label: '% de acerto',
                data: itens.map(function (i) { return i.percentual; }),
                respostas: itens.map(function (i) { return i.respostas; }),
                // Verde a partir de 60%, amarelo abaixo — a mesma regra de cor do resto do painel.
                backgroundColor: itens.map(function (i) { return i.percentual >= LIMIAR ? VERDE : AMARELO; }),
                borderRadius: 4,
                maxBarThickness: 22,
            }];
            altura = itens.length * 30 + 60;
        }

        area.style.height = Math.max(180, altura) + 'px';

        return new Chart(canvas, {
            type: 'bar',
            data: { labels: rotulos, datasets: datasets },
            options: {
                indexAxis: 'y',
                maintainAspectRatio: false,
                scales: {
                    x: { min: 0, max: 100, grid: { color: GRADE }, ticks: { callback: function (v) { return v + '%'; } } },
                    y: { grid: { display: false }, ticks: { autoSkip: false, callback: function (valor) { return limitarRotulo(this.getLabelForValue(valor), 34); } } },
                },
                plugins: {
                    legend: { display: aba === 'periodo', position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var respostas = ctx.dataset.respostas ? ' (' + ctx.dataset.respostas[ctx.dataIndex] + ' respostas)' : '';

                                return ctx.dataset.label + ': ' + formatar(ctx.parsed.x) + '%' + respostas;
                            },
                        },
                    },
                },
            },
        });
    }

    // ---------------------------------------------------------------------------------------------------------
    // Abas
    // ---------------------------------------------------------------------------------------------------------

    function renderizar(card, aba) {
        var d = (dados[card.dataset.cat] || {})[card.dataset.tipo];
        var area = card.querySelector('[data-area-grafico]');
        var canvas = card.querySelector('canvas');
        if (!d || !canvas) return;

        var antigo = Chart.getChart(canvas);
        if (antigo) antigo.destroy();

        card.querySelectorAll('.grafico-aba').forEach(function (botao) {
            var ativa = botao.dataset.aba === aba;
            botao.setAttribute('aria-selected', ativa ? 'true' : 'false');
            botao.tabIndex = ativa ? 0 : -1;
            botao.classList.toggle('bg-slate-800', ativa);
            botao.classList.toggle('text-white', ativa);
            botao.classList.toggle('text-slate-700', !ativa);
            botao.classList.toggle('hover:bg-slate-200', !ativa);
        });
        var abaAtiva = card.querySelector('.grafico-aba[data-aba="' + aba + '"]');
        if (abaAtiva) area.setAttribute('aria-labelledby', abaAtiva.id);

        if (card.dataset.tipo === 'evolucao') {
            area.style.height = '300px';
            desenharEvolucao(canvas, d, aba);
        } else {
            desenharBarras(canvas, area, d, aba);
        }
    }

    function iniciarCard(card) {
        if (card.dataset.pronto === '1') return;
        card.dataset.pronto = '1';

        var abas = Array.prototype.slice.call(card.querySelectorAll('.grafico-aba'));
        abas.forEach(function (botao, i) {
            botao.addEventListener('click', function () { renderizar(card, botao.dataset.aba); });
            // Setas esquerda/direita entre as abas (padrão de abas acessíveis).
            botao.addEventListener('keydown', function (evento) {
                if (evento.key !== 'ArrowRight' && evento.key !== 'ArrowLeft') return;
                var alvo = abas[(i + (evento.key === 'ArrowRight' ? 1 : abas.length - 1)) % abas.length];
                evento.preventDefault();
                alvo.focus();
                renderizar(card, alvo.dataset.aba);
            });
        });

        renderizar(card, 'geral');
    }

    function iniciarCategoria(detalhes) {
        detalhes.querySelectorAll('.grafico-card').forEach(iniciarCard);
    }

    document.querySelectorAll('details.categoria-painel').forEach(function (detalhes) {
        if (detalhes.open) iniciarCategoria(detalhes);
        detalhes.addEventListener('toggle', function () {
            if (detalhes.open) iniciarCategoria(detalhes);
        });
    });
})();
