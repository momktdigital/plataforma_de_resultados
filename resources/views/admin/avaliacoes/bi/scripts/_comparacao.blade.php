@if ($comparacaoAvaliacoes !== null)
(function () {
    'use strict';

    // Mesma ordem de $comparacaoAvaliacoes['avaliacoes'] (base primeiro) e
    // mesma paleta usada nos pontinhos coloridos da legenda em HTML — a cor
    // de cada avaliação tem que ser igual nos dois lugares.
    var paleta = ['#12a37f', '#2a78d6', '#eb6834'];
    var avaliacoes = {{ Js::from(collect($comparacaoAvaliacoes['avaliacoes'])->map(fn ($a) => ['nome' => $a['nome'], 'media' => $a['resumo']['media'] ?? null])) }};
    var areas = {{ Js::from($comparacaoAvaliacoes['areas']) }};
    var porAreaESerie = {{ Js::from(collect($comparacaoAvaliacoes['avaliacoes'])->map(fn ($a) => $a['mediaPorArea'])) }};

    // Rótulo direto no fim de cada barra: com só 2-3 categorias o valor exato
    // importa mais do que em um gráfico com dezenas delas (ver dataviz:
    // "selective direct labels" — aqui elas não competem por espaço).
    var rotuloNaBarra = {
        id: 'rotuloNaBarra',
        afterDatasetsDraw: function (chart) {
            var ctx = chart.ctx;
            var meta = chart.getDatasetMeta(0);
            ctx.save();
            ctx.fillStyle = Viz.cores.tinta;
            ctx.font = '600 12px ui-sans-serif, system-ui, sans-serif';
            ctx.textBaseline = 'middle';
            meta.data.forEach(function (barra, i) {
                var valor = chart.data.datasets[0].data[i];
                if (valor === null || valor === undefined) {
                    return;
                }
                var texto = valor.toFixed(1).replace('.', ',') + '%';
                ctx.fillText(texto, barra.x + 8, barra.y);
            });
            ctx.restore();
        },
    };

    new Chart(document.getElementById('grafico-comparacao-media'), {
        type: 'bar',
        data: {
            labels: avaliacoes.map(function (a) { return a.nome; }),
            datasets: [{
                data: avaliacoes.map(function (a) { return a.media; }),
                backgroundColor: avaliacoes.map(function (a, i) { return paleta[i]; }),
                borderRadius: 4,
                maxBarThickness: 48,
            }],
        },
        options: {
            indexAxis: 'y',
            layout: { padding: { right: 36 } },
            scales: { x: { beginAtZero: true, max: 100, title: { display: true, text: '% de acerto' } } },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function (c) { return c.raw === null ? 'Respondentes insuficientes' : c.raw.toFixed(1).replace('.', ',') + '%'; },
                    },
                },
            },
        },
        plugins: [rotuloNaBarra],
    });

    @if (! empty($comparacaoAvaliacoes['areas']))
    new Chart(document.getElementById('grafico-comparacao-area'), {
        type: 'bar',
        data: {
            labels: areas,
            datasets: avaliacoes.map(function (a, i) {
                return {
                    label: a.nome,
                    backgroundColor: paleta[i],
                    borderRadius: 4,
                    maxBarThickness: 28,
                    data: areas.map(function (area) {
                        var valor = porAreaESerie[i][area];

                        return valor === undefined ? null : valor;
                    }),
                };
            }),
        },
        options: {
            indexAxis: 'y',
            scales: { x: { beginAtZero: true, max: 100, title: { display: true, text: '% de acerto' } } },
            plugins: { legend: { position: 'bottom' } },
        },
    });
    @endif
})();
@endif
