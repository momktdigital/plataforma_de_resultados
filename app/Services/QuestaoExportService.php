<?php

namespace App\Services;

use App\Models\Avaliacao;
use App\Models\Questao;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Monta a planilha de exportação das questões de uma avaliação. As colunas
 * espelham exatamente o formato aceito por QuestaoImportService — "Matriz
 * Prova A/B/C", "DCN A/B" etc. em vez de uma célula só com valores separados
 * por ";" — para que o arquivo exportado possa ser editado e reimportado
 * sem precisar reformatar nada.
 */
class QuestaoExportService
{
    private const CABECALHO = [
        'Questão', 'Gabarito', 'Área', 'Tema', 'Habilidade',
        'Bloom (nível)', 'Bloom (verbo)', 'Miller (nível)',
        'Dificuldade Pedagógica', 'Dificuldade TRI',
        'Matriz (período)', 'Matriz (disciplina)', 'Matriz (código)',
        'Matriz Prova A', 'Matriz Prova B', 'Matriz Prova C',
        'DCN A', 'DCN B',
        'Portaria INEP A', 'Portaria INEP B', 'Portaria INEP C',
        'PPC A', 'PPC B', 'PPC C', 'PPC D',
    ];

    public function planilha(Avaliacao $avaliacao): Spreadsheet
    {
        $questoes = $avaliacao->questoes()->orderBy('numero')->with(['matrizes', 'referencias'])->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Questões');
        $sheet->fromArray(self::CABECALHO, null, 'A1');

        $linha = 2;
        foreach ($questoes as $questao) {
            $sheet->fromArray($this->linha($questao), null, "A{$linha}");
            $linha++;
        }

        foreach (range('A', 'Y') as $coluna) {
            $sheet->getColumnDimension($coluna)->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        return $spreadsheet;
    }

    /** @return array<int, mixed> */
    private function linha(Questao $questao): array
    {
        $porTipo = $questao->referencias->groupBy('tipo')->map(fn ($grupo) => $grupo->pluck('valor')->values()->all());

        $matrizProva = $porTipo->get('matriz_prova', []);
        $dcn = $porTipo->get('dcn', []);
        $portariaInep = $porTipo->get('portaria_inep', []);
        $ppc = $porTipo->get('ppc', []);

        return [
            $questao->numero,
            $questao->gabarito,
            $questao->area,
            $questao->tema,
            $questao->habilidade,
            $questao->bloom_nivel,
            $questao->bloom_verbo,
            $questao->miller_nivel,
            $questao->dificuldade_pedagogica,
            $questao->dificuldade_tri,
            $questao->matrizes->pluck('periodo')->filter()->implode(';'),
            $questao->matrizes->pluck('disciplina')->filter()->implode(';'),
            $questao->matrizes->pluck('codigo')->filter()->implode(';'),
            $matrizProva[0] ?? null, $matrizProva[1] ?? null, $matrizProva[2] ?? null,
            $dcn[0] ?? null, $dcn[1] ?? null,
            $portariaInep[0] ?? null, $portariaInep[1] ?? null, $portariaInep[2] ?? null,
            $ppc[0] ?? null, $ppc[1] ?? null, $ppc[2] ?? null, $ppc[3] ?? null,
        ];
    }
}
