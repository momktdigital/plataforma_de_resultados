<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Planilha da lista de alunos do painel do coordenador (a de CoordenadorAlunosService, já filtrada). Contém dado
 * pessoal — quem chama registra a exportação.
 *
 * Todo texto é gravado como STRING explícita: nome e turma vêm de planilha digitada à mão e um valor começando em
 * "=" seria avaliado como fórmula pelo Excel (injeção de fórmula). Também preserva zeros à esquerda do RA.
 */
class AlunosDoCursoExportService
{
    private const CABECALHO = [
        'Nome', 'RA', 'Período do curso', 'Turma', 'Avaliações', 'Faltas', 'Presença (%)',
        'Média (%)', 'Abaixo de 60% (nº)', 'Última avaliação', 'Última nota (%)', 'Variação (pp)', 'Situação', 'Motivos',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $alunos
     * @param  string  $rotuloSemestre  ex.: "2026/1" ou "Todos os períodos"
     */
    public function planilha(array $alunos, string $rotuloSemestre): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Alunos do curso');

        $texto = function (int $coluna, int $linha, mixed $valor) use ($sheet) {
            $sheet->getCell([$coluna, $linha])->setValueExplicit($valor === null ? '' : (string) $valor, DataType::TYPE_STRING);
        };
        $numero = function (int $coluna, int $linha, mixed $valor) use ($sheet) {
            if ($valor !== null) {
                $sheet->setCellValue([$coluna, $linha], $valor);
            }
        };

        foreach (self::CABECALHO as $i => $titulo) {
            $texto($i + 1, 1, $titulo);
        }
        $ultima = count(self::CABECALHO);
        $sheet->getStyle([1, 1, $ultima, 1])->getFont()->setBold(true);

        $linha = 2;
        foreach ($alunos as $a) {
            $texto(1, $linha, $a['nome']);
            $texto(2, $linha, $a['ra']);
            $texto(3, $linha, $a['periodoCursoRotulo']);
            $texto(4, $linha, $a['turma']);
            $numero(5, $linha, $a['inscritos']);
            $numero(6, $linha, $a['faltas']);
            $numero(7, $linha, $a['presenca']);
            $numero(8, $linha, $a['media']);
            $numero(9, $linha, $a['abaixo']);
            $texto(10, $linha, $a['ultima']['nome'] ?? null);
            $numero(11, $linha, $a['ultima']['pc'] ?? null);
            $numero(12, $linha, $a['tendencia']['delta'] ?? null);
            $texto(13, $linha, CoordenadorAlunosService::SITUACOES[$a['situacao']] ?? $a['situacao']);
            $texto(14, $linha, implode(' ', $a['motivos']));
            $linha++;
        }

        $fim = max(2, $linha - 1);
        $sheet->getStyle("G2:H{$fim}")->getNumberFormat()->setFormatCode('0.0');
        $sheet->getStyle("K2:L{$fim}")->getNumberFormat()->setFormatCode('0.0');
        foreach (range('A', 'M') as $coluna) {
            $sheet->getColumnDimension($coluna)->setAutoSize(true);
        }
        $sheet->getColumnDimension('N')->setWidth(70);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:N{$fim}");

        $spreadsheet->getProperties()->setTitle('Alunos do curso — '.$rotuloSemestre);

        return $spreadsheet;
    }
}
