<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Questões — Avaliação #{{ $avaliacao->codigo }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            color: #1f2937;
            margin: 24px;
            font-size: 11px;
        }
        h1 { font-size: 16px; margin: 0 0 2px; }
        p.subtitulo { margin: 0 0 16px; color: #64748b; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th, td {
            border: 1px solid #cbd5e1;
            padding: 4px 6px;
            text-align: left;
            vertical-align: top;
        }
        th { background: #f1f5f9; font-weight: bold; }
        @page { size: A4 landscape; margin: 12mm; }
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <button type="button" class="no-print" onclick="window.print()" style="margin-bottom: 12px; padding: 6px 14px;">Imprimir / Salvar como PDF</button>

    <h1>Questões — Avaliação #{{ $avaliacao->codigo }}{{ $avaliacao->nome ? ' — '.$avaliacao->nome : '' }}</h1>
    <p class="subtitulo">{{ $questoes->count() }} questão(ões) — gerado em {{ now()->format('d/m/Y H:i') }}</p>

    <table>
        <thead>
            <tr>
                <th>Nº</th>
                <th>Gabarito</th>
                <th>Área</th>
                <th>Tema</th>
                <th>Habilidade</th>
                <th>Bloom (nível)</th>
                <th>Bloom (verbo)</th>
                <th>Miller</th>
                <th>Dif. Pedagógica</th>
                <th>Dif. TRI</th>
                <th>Matriz Prova</th>
                <th>DCN</th>
                <th>Portaria INEP</th>
                <th>PPC</th>
                <th>Matriz curricular</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($questoes as $questao)
                @php
                    $porTipo = $questao->referencias->groupBy('tipo')->map(fn ($grupo) => $grupo->pluck('valor')->implode('; '));
                    $matrizCurricular = $questao->matrizes->map(fn ($m) => collect([$m->periodo, $m->disciplina, $m->codigo])->filter()->implode(' · '))->implode('; ');
                @endphp
                <tr>
                    <td>{{ $questao->numero }}</td>
                    <td>{{ $questao->gabarito ?: '—' }}</td>
                    <td>{{ $questao->area ?: '—' }}</td>
                    <td>{{ $questao->tema ?: '—' }}</td>
                    <td>{{ $questao->habilidade ?: '—' }}</td>
                    <td>{{ $questao->bloom_nivel ?: '—' }}</td>
                    <td>{{ $questao->bloom_verbo ?: '—' }}</td>
                    <td>{{ $questao->miller_nivel ?: '—' }}</td>
                    <td>{{ $questao->dificuldade_pedagogica ?: '—' }}</td>
                    <td>{{ $questao->dificuldade_tri ?? '—' }}</td>
                    <td>{{ $porTipo->get('matriz_prova') ?: '—' }}</td>
                    <td>{{ $porTipo->get('dcn') ?: '—' }}</td>
                    <td>{{ $porTipo->get('portaria_inep') ?: '—' }}</td>
                    <td>{{ $porTipo->get('ppc') ?: '—' }}</td>
                    <td>{{ $matrizCurricular ?: '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
