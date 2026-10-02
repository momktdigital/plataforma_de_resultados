@if (! empty($evolucaoCategoria) && count($evolucaoCategoria) >= 2)
(function () {
    var geral = {{ Js::from($evolucaoCategoria) }};
    var porPeriodo = {{ Js::from(\App\Support\EvolucaoDoDashboard::periodosComEvolucao($evolucaoPorPeriodo ?? null)) }};
    var graficos = {};
    var semAusentes = function () { return document.getElementById('evolucao-sem-ausentes').checked; };

    // Média a exibir no ponto, conforme a caixa "Desconsiderar ausentes".
    function valor(p) { return p ? (semAusentes() ? p.mediaPresentes : p.media) : null; }
    function contagem(p) { return semAusentes() ? p.presentes : p.respondentes; }

    function opcoes(rotulosPontos) {
        return {
            spanGaps: true,
            scales: { y: { beginAtZero: true, max: 100 } },
            plugins: {
                // A cor da linha é o desempenho (verde ≥ 60%, amarelo abaixo, degradê entre um e outro);
                // as séries se distinguem pelo formato do marcador e pelo traço.
                legend: { labels: LinhaDesempenho.legenda({ usePointStyle: true }) },
                tooltip: {
                    callbacks: {
                        afterLabel: function (c) {
                            var p = rotulosPontos[c.datasetIndex] && rotulosPontos[c.datasetIndex][c.dataIndex];
                            return p ? contagem(p) + (semAusentes() ? ' presente(s)' : ' respondente(s)') : '';
                        },
                    },
                },
            },
        };
    }

    // Um gráfico só nasce quando a aba aparece: em contêiner oculto o Chart.js mede largura 0.
    function desenhar(id, datasets, pontosPorSerie) {
        if (graficos[id]) { graficos[id].destroy(); }
        graficos[id] = new Chart(document.getElementById(id), {
            type: 'line',
            data: { labels: geral.map(function (p) { return p.nome; }), datasets: datasets },
            options: opcoes(pontosPorSerie),
        });
    }

    function desenharGeral() {
        desenhar('grafico-evolucao', [LinhaDesempenho.serie({
            label: 'Média (%)', data: geral.map(valor), pointRadius: 4,
        })], [geral]);
    }

    // Uma linha por turma, todas na regra de cor do desempenho: o que distingue uma turma da
    // outra é o formato do marcador e o traço (contínuo, tracejado, pontilhado), não a cor.
    function desenharPeriodo() {
        var seletor = document.getElementById('seletor-periodo-evolucao');
        if (!seletor || !porPeriodo[seletor.value]) return;
        var turmas = porPeriodo[seletor.value].turmas;
        var marcadores = ['circle', 'rect', 'triangle', 'rectRot', 'star', 'crossRot'];
        var tracos = [[], [8, 4], [2, 3]];
        var nomes = Object.keys(turmas);
        var series = [];
        var pontos = [];
        nomes.forEach(function (turma, i) {
            var porCodigo = {};
            turmas[turma].forEach(function (p) { porCodigo[p.codigo] = p; });
            var alinhados = geral.map(function (g) { return porCodigo[g.codigo] || null; });
            pontos.push(alinhados);
            series.push(LinhaDesempenho.serie({
                label: turma, data: alinhados.map(valor),
                pointStyle: marcadores[i % marcadores.length], borderDash: tracos[i % tracos.length],
                pointRadius: 5,
            }));
        });
        desenhar('grafico-evolucao-periodo', series, pontos);
    }

    function mostrar(aba) {
        document.querySelectorAll('.aba-evolucao').forEach(function (b) {
            var ativa = b.dataset.abaEvolucao === aba;
            b.setAttribute('aria-selected', ativa ? 'true' : 'false');
            b.classList.toggle('border-emerald-600', ativa);
            b.classList.toggle('text-emerald-700', ativa);
            b.classList.toggle('border-transparent', !ativa);
            b.classList.toggle('text-slate-500', !ativa);
        });
        var painelPeriodo = document.getElementById('painel-evolucao-periodo');
        if (painelPeriodo) painelPeriodo.classList.toggle('hidden', aba !== 'periodo');
        document.getElementById('painel-evolucao-geral').classList.toggle('hidden', aba !== 'geral');
        if (aba === 'periodo') desenharPeriodo(); else desenharGeral();
    }

    function abaAtual() { return document.querySelector('.aba-evolucao[aria-selected="true"]').dataset.abaEvolucao; }

    document.querySelectorAll('.aba-evolucao').forEach(function (b) {
        b.addEventListener('click', function () { mostrar(b.dataset.abaEvolucao); });
    });
    var seletor = document.getElementById('seletor-periodo-evolucao');
    if (seletor) seletor.addEventListener('change', desenharPeriodo);
    document.getElementById('evolucao-sem-ausentes').addEventListener('change', function () { mostrar(abaAtual()); });

    mostrar(abaAtual());
})();
@endif
