@if ($estado['mapa_itens']['visivelAdmin'] && $psicometria !== null && ! empty($psicometria['itens']))
<script>
(function () {
    'use strict';

    var itens = {{ Js::from($psicometria['itens']) }};
    var curvas = {{ Js::from($curvasItens ?? []) }};

    // Faixas de Ebel: a posição vertical já diz em qual faixa o item caiu, então
    // a cor é reforço, nunca o único canal de leitura.
    var faixas = [
        { chave: 'otimo', rotulo: 'Ótimo (D ≥ 0,40)', cor: Viz.cores.bom, de: 0.40, ate: 1.0 },
        { chave: 'bom', rotulo: 'Bom (0,30–0,39)', cor: Viz.cores.serie1, de: 0.30, ate: 0.40 },
        { chave: 'marginal', rotulo: 'Marginal (0,20–0,29)', cor: Viz.cores.atencao, de: 0.20, ate: 0.30 },
        { chave: 'revisar', rotulo: 'Revisar (< 0,20)', cor: Viz.cores.critico, de: -1.0, ate: 0.20 },
    ];

    var fundoFaixas = {
        id: 'fundoFaixas',
        beforeDatasetsDraw: function (chart) {
            var ctx = chart.ctx;
            var eixoY = chart.scales.y;
            var area = chart.chartArea;

            ctx.save();
            faixas.forEach(function (faixa) {
                var topo = eixoY.getPixelForValue(Math.min(faixa.ate, eixoY.max));
                var base = eixoY.getPixelForValue(Math.max(faixa.de, eixoY.min));
                if (base <= topo) {
                    return;
                }
                ctx.fillStyle = faixa.cor;
                ctx.globalAlpha = faixa.chave === 'revisar' ? 0.08 : 0.05;
                ctx.fillRect(area.left, topo, area.right - area.left, base - topo);
            });
            ctx.restore();
        },
    };

    var grafico = new Chart(document.getElementById('grafico-mapa-itens'), {
        type: 'scatter',
        data: {
            datasets: faixas.map(function (faixa) {
                return {
                    label: faixa.rotulo,
                    backgroundColor: faixa.cor,
                    borderColor: Viz.cores.superficie,
                    borderWidth: 1.5,
                    pointRadius: 5,
                    pointHoverRadius: 8,
                    data: itens
                        .filter(function (item) { return item.faixa === faixa.chave; })
                        .map(function (item) {
                            return { x: item.dificuldade, y: item.discriminacao === null ? 0 : item.discriminacao, item: item };
                        }),
                };
            }),
        },
        options: {
            scales: {
                x: {
                    min: 0, max: 100,
                    title: { display: true, text: 'Acertaram a questão (%)' },
                },
                y: {
                    suggestedMin: -0.2, suggestedMax: 0.8,
                    title: { display: true, text: 'Índice de discriminação' },
                },
            },
            plugins: {
                legend: {
                    onClick: function (evento, item, legenda) {
                        Chart.defaults.plugins.legend.onClick.call(this, evento, item, legenda);
                        escreverExplicacaoMapa();
                    },
                },
                tooltip: {
                    callbacks: {
                        label: function (contexto) {
                            var item = contexto.raw.item;
                            var linhas = [
                                'Acerto: ' + item.dificuldade.toFixed(1).replace('.', ',') + '%',
                                'Discriminação: ' + (item.discriminacao === null ? '—' : item.discriminacao.toFixed(2).replace('.', ',')),
                            ];
                            if (item.area) {
                                linhas.push(item.area);
                            }

                            return linhas;
                        },
                        title: function (contexto) {
                            return 'Questão ' + contexto[0].raw.item.numero;
                        },
                    },
                },
            },
            onClick: function (evento, elementos) {
                if (elementos.length) {
                    var ponto = grafico.data.datasets[elementos[0].datasetIndex].data[elementos[0].index];
                    selecionar(ponto.item);
                }
            },
        },
        plugins: [fundoFaixas],
    });

    var graficoCci = new Chart(document.getElementById('grafico-cci'), {
        type: 'line',
        data: {
            labels: ['1º', '2º', '3º', '4º', '5º'],
            datasets: [{ label: '% de acerto', data: [], borderColor: Viz.cores.serie2, backgroundColor: Viz.cores.serie2, fill: false }],
        },
        options: {
            scales: {
                y: { min: 0, max: 100, title: { display: true, text: '% de acerto' } },
                x: { title: { display: true, text: 'quinto de desempenho geral →' } },
            },
            plugins: { legend: { display: false } },
        },
    });

    var coresFaixa = {};
    faixas.forEach(function (faixa) { coresFaixa[faixa.chave] = faixa.cor; });

    function selecionar(item) {
        document.getElementById('item-numero').textContent = 'Q' + item.numero;
        document.getElementById('item-contexto').textContent = [item.area, item.tema].filter(Boolean).join(' · ') || 'sem área cadastrada';

        var faixa = document.getElementById('item-faixa');
        faixa.textContent = item.rotulo;
        faixa.style.color = coresFaixa[item.faixa];
        faixa.style.backgroundColor = coresFaixa[item.faixa] + '22';

        document.getElementById('item-p').textContent = item.dificuldade.toFixed(1).replace('.', ',') + '%';
        document.getElementById('item-d').textContent = item.discriminacao === null ? '—' : item.discriminacao.toFixed(2).replace('.', ',');
        document.getElementById('item-branco').textContent = item.respostas > 0
            ? (item.emBranco / item.respostas * 100).toFixed(1).replace('.', ',') + '%'
            : '—';
        document.getElementById('item-acao').textContent = item.acao;

        var curva = curvas[item.numero] || {};
        graficoCci.data.datasets[0].data = [1, 2, 3, 4, 5].map(function (quinto) {
            return curva[quinto] === undefined ? null : curva[quinto];
        });
        graficoCci.update();
        escreverExplicacaoCci(item, curva);
    }

    function numeroBr(valor, casas) {
        return valor.toFixed(casas).replace('.', ',');
    }

    /**
     * Leitura da curva característica do item SELECIONADO. Sem isto o popover
     * continuaria mostrando a leitura da questão anterior — cada visual tem a
     * sua, e a deste muda a cada clique no mapa.
     *
     * O que decide o tom é a direção da curva: um bom item é acertado mais
     * pelos quintos de cima do que pelos de baixo.
     */
    function escreverExplicacaoCci(item, curva) {
        var primeiro = curva[1];
        var ultimo = curva[5];
        var rotulo = 'Q' + item.numero;

        if (primeiro === undefined || ultimo === undefined) {
            Viz.escreverLeitura('expl-cci', 'Nesta avaliação: a ' + rotulo + ' não tem respostas suficientes em todos os quintos para desenhar a curva.', null);

            return;
        }

        var delta = ultimo - primeiro;
        var texto;
        var tom;

        if (delta >= 12) {
            tom = 'bom';
            texto = 'Nesta avaliação: a curva da ' + rotulo + ' sobe — o quinto mais forte acerta '
                + numeroBr(ultimo, 0) + '% contra ' + numeroBr(primeiro, 0) + '% do mais fraco ('
                + numeroBr(delta, 0) + ' pontos de diferença). É o comportamento esperado: a questão separa quem sabe de quem não sabe, e pode ser reaproveitada.';
        } else if (delta > 0) {
            tom = 'atencao';
            texto = 'Nesta avaliação: a curva da ' + rotulo + ' sobe pouco (' + numeroBr(delta, 0)
                + ' pontos entre o quinto mais fraco e o mais forte). A questão quase não diferencia os alunos — vale revisar o enunciado e as alternativas antes de reutilizá-la.';
        } else {
            tom = 'ruim';
            texto = 'Nesta avaliação: a curva da ' + rotulo + ' é plana ou invertida — quem foi bem na prova acertou '
                + numeroBr(Math.abs(delta), 0) + ' pontos a MENOS que quem foi mal. Isso costuma indicar gabarito errado, enunciado ambíguo ou distrator mais defensável que a resposta oficial. Confira o item antes de divulgar a nota.';
        }

        Viz.escreverLeitura('expl-cci', texto, tom);
    }

    /**
     * Leitura do mapa considerando só as faixas VISÍVEIS: ao filtrar pela
     * legenda, a frase tem que falar do recorte que está na tela.
     */
    function escreverExplicacaoMapa() {
        var visiveis = faixas.filter(function (faixa, indice) {
            return grafico.isDatasetVisible(indice);
        }).map(function (faixa) { return faixa.chave; });

        var mostrados = itens.filter(function (item) { return visiveis.indexOf(item.faixa) !== -1; });
        var filtrado = visiveis.length !== faixas.length;
        var prefixo = filtrado ? 'Com este filtro: ' : 'Nesta avaliação: ';

        if (!mostrados.length) {
            Viz.escreverLeitura('expl-mapa', prefixo + 'nenhuma questão está nas faixas selecionadas.', null);

            return;
        }

        var revisar = mostrados.filter(function (item) { return item.faixa === 'revisar'; }).length;
        var negativos = mostrados.filter(function (item) { return item.discriminacao !== null && item.discriminacao < 0; });
        var texto = prefixo + mostrados.length + (mostrados.length === 1 ? ' questão exibida' : ' questões exibidas') + '. ';
        var tom;

        if (negativos.length) {
            tom = 'ruim';
            texto += negativos.length === 1
                ? 'A Q' + negativos[0].numero + ' tem discriminação negativa: quem foi bem na prova errou mais que quem foi mal — confira o gabarito.'
                : negativos.length + ' delas têm discriminação negativa (quem foi bem na prova errou mais que quem foi mal) — confira o gabarito dessas questões.';
        } else if (revisar) {
            tom = 'atencao';
            texto += revisar === 1
                ? '1 está na faixa de revisão (D < 0,20): mede pouco e deve ser reescrita antes de reaproveitada.'
                : revisar + ' estão na faixa de revisão (D < 0,20): medem pouco e devem ser reescritas antes de reaproveitadas.';
        } else {
            tom = 'bom';
            texto += 'Nenhuma está na faixa de revisão — todas separam quem sabe de quem não sabe em grau aceitável.';
        }

        Viz.escreverLeitura('expl-mapa', texto, tom);
    }

    // Abre já no item mais problemático: é o que o coordenador veio ver.
    var pior = itens.slice().sort(function (a, b) {
        return (a.discriminacao === null ? -99 : a.discriminacao) - (b.discriminacao === null ? -99 : b.discriminacao);
    })[0];
    if (pior) {
        selecionar(pior);
    }
})();
</script>
@endif
