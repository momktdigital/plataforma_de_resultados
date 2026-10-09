@if (! empty($perfilDemografico['sexo']))
new Chart(document.getElementById('grafico-sexo'), {
    type: 'doughnut',
    data: {
        labels: {{ Js::from(array_keys($perfilDemografico['sexo'])) }},
        datasets: [{ data: {{ Js::from(array_values($perfilDemografico['sexo'])) }}, backgroundColor: [Viz.cores.serie1, Viz.cores.serie2, Viz.cores.serie3, Viz.cores.tinta3] }],
    },
    options: { plugins: { legend: { position: 'bottom' } } },
});
@endif

@if (! empty($perfilDemografico['cor_raca']))
new Chart(document.getElementById('grafico-cor-raca'), {
    type: 'bar',
    data: {
        labels: {{ Js::from(array_keys($perfilDemografico['cor_raca'])) }},
        datasets: [{ data: {{ Js::from(array_values($perfilDemografico['cor_raca'])) }}, backgroundColor: Viz.cores.serie1, borderRadius: 4, maxBarThickness: 22 }],
    },
    options: { indexAxis: 'y', scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { display: false } } },
});
@endif

@if (! empty($perfilDemografico['forma_ingresso']))
new Chart(document.getElementById('grafico-forma-ingresso'), {
    type: 'bar',
    data: {
        labels: {{ Js::from(array_keys($perfilDemografico['forma_ingresso'])) }},
        datasets: [{ data: {{ Js::from(array_values($perfilDemografico['forma_ingresso'])) }}, backgroundColor: Viz.cores.serie2, borderRadius: 4, maxBarThickness: 22 }],
    },
    options: { indexAxis: 'y', scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { display: false } } },
});
@endif
