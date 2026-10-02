@if (! empty($distribuicaoTurma))
new Chart(document.getElementById('grafico-turma'), {
    type: 'bar',
    data: {
        labels: {{ Js::from(array_column($distribuicaoTurma, 'turma')) }},
        datasets: [{ label: 'Média (%)', data: {{ Js::from(array_column($distribuicaoTurma, 'media')) }}, backgroundColor: Viz.cores.serie1 }],
    },
    options: { scales: { y: { beginAtZero: true, max: 100 } }, plugins: { legend: { display: false } } },
});
@endif
