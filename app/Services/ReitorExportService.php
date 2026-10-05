<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Planilha do painel da reitoria: os mesmos números dos quadros, por curso e por período do curso, para o
 * reitor levar a uma reunião ou relatório. Só agregados — não há dado nominal de aluno aqui.
 *
 * Todo texto é gravado como STRING explícita (nome de curso vem de planilha digitada à mão; um valor começando
 * em "=" seria avaliado como fórmula pelo Excel).
 */
class ReitorExportService
{
    /**
     * @param  array<string, mixed>  $ctx  saída de ReitorDashboardService::contexto()
     * @param  array<string, mixed>  $est  saída de ReitorDashboardService::estatisticas()
     */
    public function planilha(array $ctx, array $est): Spreadsheet
    {
        $corte = $ctx['corte'];
        $patamares = ReitorDashboardService::patamares($corte);
        $faixas = ReitorDashboardService::faixas($corte);
        $avaliacao = $ctx['avaliacao'];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Por curso');

        $texto = fn ($aba, int $coluna, int $linha, mixed $valor) => $aba->getCell([$coluna, $linha])->setValueExplicit($valor === null ? '' : (string) $valor, DataType::TYPE_STRING);
        $numero = function ($aba, int $coluna, int $linha, mixed $valor) {
            if ($valor !== null) {
                $aba->setCellValue([$coluna, $linha], $valor);
            }
        };

        $texto($sheet, 1, 1, "{$avaliacao['nome']} — período letivo {$avaliacao['periodoLetivo']} — critério de proficiência: ≥ ".CoordenadorDashboardService::pct($corte).'% de acerto — meta de participação: '.CoordenadorDashboardService::pct($ctx['meta']).'%');

        $cabecalho = ['Curso', 'Previstos', 'Fizeram', 'Ausentes', 'Participação (%)', 'Distância da meta (pp)', 'Estudantes a mais p/ a meta', 'Períodos avaliados', 'Ativos sem aplicação',
            'Proficientes (nº)', 'Proficientes (%)', 'Média (%)', 'Mediana (%)', '1º quartil (%)', '3º quartil (%)'];
        foreach ($patamares as $p) {
            $cabecalho[] = "≥ {$p}% (% dos estudantes)";
        }
        foreach ($faixas as $f) {
            $cabecalho[] = "Faixa {$f['rotulo']} (% dos estudantes)";
        }
        foreach ($cabecalho as $i => $titulo) {
            $texto($sheet, $i + 1, 3, $titulo);
        }
        $sheet->getStyle([1, 3, count($cabecalho), 3])->getFont()->setBold(true);

        $linha = 4;
        foreach ([...array_values($est['cursos']), $est['total']] as $c) {
            $texto($sheet, 1, $linha, $c['nome']);
            $numero($sheet, 2, $linha, $c['previstos']);
            $numero($sheet, 3, $linha, $c['fizeram']);
            $numero($sheet, 4, $linha, $c['ausentes']);
            $numero($sheet, 5, $linha, $c['participacao']);
            $numero($sheet, 6, $linha, $c['distanciaMeta']);
            $numero($sheet, 7, $linha, $c['alunosAMais']);
            $texto($sheet, 8, $linha, $c['periodosAvaliadosRotulo'] ?? '');
            $texto($sheet, 9, $linha, $c['ativosSemAplicacaoRotulo'] ?? '');
            $numero($sheet, 10, $linha, $c['proficientes']);
            $numero($sheet, 11, $linha, $c['proficienciaPct']);
            $numero($sheet, 12, $linha, $c['media']);
            $numero($sheet, 13, $linha, $c['mediana']);
            $numero($sheet, 14, $linha, $c['q1']);
            $numero($sheet, 15, $linha, $c['q3']);
            $coluna = 16;
            foreach ($patamares as $p) {
                $numero($sheet, $coluna++, $linha, $c['patamares'][$p] ?? null);
            }
            foreach ($c['faixas'] as $faixa) {
                $numero($sheet, $coluna++, $linha, $faixa['pct']);
            }
            $linha++;
        }
        $sheet->getStyle([1, $linha - 1, count($cabecalho), $linha - 1])->getFont()->setBold(true);
        $sheet->freezePane('B4');
        for ($i = 1; $i <= count($cabecalho); $i++) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }
        $sheet->getColumnDimension('A')->setWidth(34);

        // Por período do curso
        $aba = $spreadsheet->createSheet();
        $aba->setTitle('Por período do curso');
        foreach (['Curso', 'Período do curso', 'Previstos', 'Fizeram', 'Participação (%)', 'Com nota (nº)', 'Média (%)', 'Mediana (%)', 'Proficientes (%)'] as $i => $titulo) {
            $texto($aba, $i + 1, 1, $titulo);
        }
        $aba->getStyle([1, 1, 9, 1])->getFont()->setBold(true);
        $linha = 2;
        foreach (array_values($est['cursos']) as $c) {
            foreach ($c['periodos'] as $p) {
                $texto($aba, 1, $linha, $c['nome']);
                $texto($aba, 2, $linha, $p['rotulo']);
                $numero($aba, 3, $linha, $p['previstos']);
                $numero($aba, 4, $linha, $p['fizeram']);
                $numero($aba, 5, $linha, $p['participacao']);
                $numero($aba, 6, $linha, $p['n']);
                $numero($aba, 7, $linha, $p['media']);
                $numero($aba, 8, $linha, $p['mediana']);
                $numero($aba, 9, $linha, $p['proficienciaPct']);
                $linha++;
            }
        }
        $aba->freezePane('A2');
        for ($i = 1; $i <= 9; $i++) {
            $aba->getColumnDimensionByColumn($i)->setAutoSize(true);
        }

        $spreadsheet->getProperties()->setTitle('Painel da reitoria — '.$avaliacao['nome']);
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }
}
