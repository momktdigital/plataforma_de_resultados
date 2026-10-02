@if (! empty($dados) && empty($dados['semGabarito']) && empty($dados['semRespostas']))
    @if ($estado['histograma']['visivelAdmin'])
    new Chart(document.getElementById('grafico-histograma'), {
        type: 'bar',
        data: {
            labels: [@foreach($dados['histograma'] as $i => $c) '{{ $i * 10 }}-{{ $i * 10 + 9 }}%', @endforeach],
            datasets: [{ label: 'Respondentes', data: {{ Js::from($dados['histograma']) }}, backgroundColor: Viz.cores.serie1 }],
        },
        options: { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { display: false } } },
    });
    @endif

    @if ($estado['radar_disciplina']['visivelAdmin'] && ! empty($dados['radar']))
    new Chart(document.getElementById('grafico-radar'), {
        type: 'radar',
        data: {
            labels: {{ Js::from(array_keys($dados['radar'])) }},
            datasets: [{ label: '% de acerto', data: {{ Js::from(array_values($dados['radar'])) }}, backgroundColor: 'rgba(18,163,127,0.2)', borderColor: Viz.cores.serie1 }],
        },
        options: { scales: { r: { beginAtZero: true, max: 100 } } },
    });
    @endif
@endif
