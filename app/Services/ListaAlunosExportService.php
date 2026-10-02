<?php

namespace App\Services;

use App\Models\Avaliacao;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Planilha da lista nominal de alunos do painel BI (a mesma de
 * RelatorioAdminService::rankingCompleto(), já restrita ao escopo de quem
 * exporta). Contém dado pessoal — quem chama registra a exportação.
 *
 * Todo texto é gravado como STRING explícita: nome/curso vêm de planilha
 * digitada à mão e um valor começando em "=" seria avaliado como fórmula pelo
 * Excel (injeção de fórmula). Também preserva zeros à esquerda do RA.
 */
class ListaAlunosExportService
{
    private const CABECALHO = [
        'Posição', 'Nome', 'RA', 'Curso', 'Período', 'Turma',
        'Acertos', 'Total de questões', 'Percentual (%)', 'Situação', 'Foto de perfil (URL)',
    ];

    /** @param array<int, array<string, mixed>> $alunos saída de RelatorioAdminService::rankingCompleto() */
    public function planilha(Avaliacao $avaliacao, array $alunos): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Alunos');

        $texto = function (int $coluna, int $linha, mixed $valor) use ($sheet) {
            $valor = $valor === null ? '' : (string) $valor;
            $sheet->getCell([$coluna, $linha])->setValueExplicit($valor, DataType::TYPE_STRING);
        };

        foreach (self::CABECALHO as $i => $titulo) {
            $texto($i + 1, 1, $titulo);
        }
        $sheet->getStyle('A1:K1')->getFont()->setBold(true);

        $linha = 2;
        foreach ($alunos as $posicao => $aluno) {
            $ausente = (bool) $aluno['ausente'];

            // Ausente não tem posição no ranking.
            if ($ausente) {
                $texto(1, $linha, '');
            } else {
                $sheet->setCellValue([1, $linha], $posicao + 1);
            }
            $texto(2, $linha, $aluno['aluno_nome']);
            $texto(3, $linha, $aluno['ra']);
            $texto(4, $linha, $aluno['curso']);
            $texto(5, $linha, $aluno['periodo_curso']);
            $texto(6, $linha, $aluno['turma']);
            $sheet->setCellValue([7, $linha], $aluno['acertos']);
            $sheet->setCellValue([8, $linha], $aluno['total']);
            if ($aluno['percentual'] !== null) {
                $sheet->setCellValue([9, $linha], $aluno['percentual']);
            }
            $texto(10, $linha, $ausente ? 'Ausente' : 'Presente');
            $texto(11, $linha, $aluno['foto']);

            $linha++;
        }

        $sheet->getStyle('I2:I'.max(2, $linha - 1))->getNumberFormat()->setFormatCode('0.0');

        foreach (range('A', 'K') as $coluna) {
            $sheet->getColumnDimension($coluna)->setAutoSize(true);
        }
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:K'.max(2, $linha - 1));

        return $spreadsheet;
    }
}
