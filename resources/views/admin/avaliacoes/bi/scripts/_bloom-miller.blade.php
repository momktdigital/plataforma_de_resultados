@if (! empty($mediaPorBloom))
new Chart(document.getElementById('grafico-bloom'), {
    type: 'bar',
    data: {
        labels: {{ Js::from(array_keys($mediaPorBloom)) }},
        datasets: [{ label: '% de acerto', data: {{ Js::from(array_values($mediaPorBloom)) }}, backgroundColor: Viz.cores.serie1 }],
    },
    options: { indexAxis: 'y', scales: { x: { beginAtZero: true, max: 100 } }, plugins: { legend: { display: false } } },
});
@endif

@if (! empty($mediaPorMiller))
new Chart(document.getElementById('grafico-miller'), {
    type: 'bar',
    data: {
        labels: {{ Js::from(array_keys($mediaPorMiller)) }},
        datasets: [{ label: '% de acerto', data: {{ Js::from(array_values($mediaPorMiller)) }}, backgroundColor: Viz.cores.serie1 }],
    },
    options: { indexAxis: 'y', scales: { x: { beginAtZero: true, max: 100 } }, plugins: { legend: { display: false } } },
});
@endif
