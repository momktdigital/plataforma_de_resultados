@if (! empty($mediaPorArea))
new Chart(document.getElementById('grafico-area'), {
    type: 'radar',
    data: {
        labels: {{ Js::from(array_keys($mediaPorArea)) }},
        datasets: [{ label: '% de acerto', data: {{ Js::from(array_values($mediaPorArea)) }}, backgroundColor: 'rgba(18,163,127,0.2)', borderColor: Viz.cores.serie1 }],
    },
    options: { scales: { r: { beginAtZero: true, max: 100 } } },
});
@endif
