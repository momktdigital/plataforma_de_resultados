@if (! empty($dispersaoTri))
new Chart(document.getElementById('grafico-tri'), {
    type: 'scatter',
    data: {
        datasets: [{
            label: 'Questões',
            data: {{ Js::from(array_map(fn ($p) => ['x' => $p['dificuldade_tri'], 'y' => $p['taxa_acerto']], $dispersaoTri)) }},
            backgroundColor: Viz.cores.serie1,
        }],
    },
    options: {
        scales: {
            x: { title: { display: true, text: 'Dificuldade TRI' } },
            y: { title: { display: true, text: '% de acerto observado' }, min: 0, max: 100 },
        },
    },
});
@endif
