{{--
    Regra de cor de desempenho para LINHAS de gráfico (Chart.js): verde a partir de 60%,
    amarelo abaixo — a mesma de App\Support\CorDesempenho (anel/barras do aluno). Quando
    o trecho entre dois pontos cruza o limite, a linha faz degradê de uma cor para a outra.
    Não depende do Chart.js estar carregado na hora de incluir; só é chamado ao desenhar.

    Uso:  datasets: [ LinhaDesempenho.serie({ label: 'Média (%)', data: [...] }) ]
--}}
<script>
window.LinhaDesempenho = (function () {
    'use strict';

    var LIMIAR = 60;
    var VERDE = '#10b981';    // = CorDesempenho::hex(>= 60)
    var AMARELO = '#f59e0b';  // = CorDesempenho::hex(< 60)
    var NEUTRO = '#64748b';   // legenda: a cor da linha é o desempenho, não a série

    function cor(valor) { return valor >= LIMIAR ? VERDE : AMARELO; }

    // Cor de um trecho da linha: sólida se os dois extremos estão do mesmo lado do
    // limite; degradê (extremo inicial → final) se cruza.
    function segmento(ctx) {
        var c0 = cor(ctx.p0.parsed.y);
        var c1 = cor(ctx.p1.parsed.y);
        if (c0 === c1) { return c0; }
        var g = ctx.chart.ctx.createLinearGradient(ctx.p0.x, ctx.p0.y, ctx.p1.x, ctx.p1.y);
        g.addColorStop(0, c0);
        g.addColorStop(1, c1);
        return g;
    }

    function corDoPonto(ctx) { return ctx.parsed ? cor(ctx.parsed.y) : NEUTRO; }

    // Dataset cuja linha e pontos seguem a regra. Cor "estática" (legenda) é neutra.
    function serie(extra) {
        return Object.assign({
            borderColor: NEUTRO,
            backgroundColor: NEUTRO,
            segment: { borderColor: segmento },
            pointBackgroundColor: corDoPonto,
            pointBorderColor: corDoPonto,
            tension: 0.2,
        }, extra);
    }

    // Legenda neutra: a cor de uma linha é o desempenho dela, então o marcador da legenda
    // não pode ter a cor de um ponto qualquer (senão parece que "a turma X é amarela").
    function legenda(extra) {
        return Object.assign({
            generateLabels: function (chart) {
                return Chart.defaults.plugins.legend.labels.generateLabels(chart).map(function (item) {
                    item.fillStyle = NEUTRO;
                    item.strokeStyle = NEUTRO;
                    return item;
                });
            },
        }, extra);
    }

    return { LIMIAR: LIMIAR, VERDE: VERDE, AMARELO: AMARELO, NEUTRO: NEUTRO, cor: cor, segmento: segmento, serie: serie, legenda: legenda };
})();
</script>
