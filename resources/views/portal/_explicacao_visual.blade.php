{{--
    Explicação de um painel do boletim. Só resolve a chave dentro de
    $no['explicacoes'] (montado por App\Services\Portal\ExplicacaoVisualService)
    e delega a renderização ao parcial compartilhado com o BI — lá mora o
    markup do botão e do popover.

    'pessoal' vira 'leitura' na tradução porque o parcial compartilhado atende
    os dois públicos: no boletim a frase fala do aluno, no BI fala da turma.

    $no: nó da árvore com 'explicacoes' anexado; $chave: chave dentro dele.
--}}
@php $explicacaoDoNo = $no['explicacoes'][$chave] ?? null; @endphp
@if ($explicacaoDoNo)
    @include('_explicacao', ['explicacao' => [
        'generico' => $explicacaoDoNo['generico'],
        'leitura' => $explicacaoDoNo['pessoal'] ?? null,
    ]])
@endif
