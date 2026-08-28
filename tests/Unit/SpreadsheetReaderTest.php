<?php

namespace Tests\Unit;

use App\Support\SpreadsheetReader;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SpreadsheetReaderTest extends TestCase
{
    public function test_le_linhas_de_um_xlsx_dentro_do_limite(): void
    {
        $arquivo = $this->xlsxComLinhas(5);

        $linhas = SpreadsheetReader::readRows($arquivo);

        $this->assertCount(5, $linhas);
        $this->assertSame('valor-1', $linhas[0]['coluna']);
    }

    public function test_rejeita_xlsx_acima_do_limite_de_linhas(): void
    {
        $arquivo = $this->xlsxComLinhas(50_001);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('50001 linhas de dados');

        SpreadsheetReader::readRows($arquivo);
    }

    private function xlsxComLinhas(int $linhas): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['coluna'], null, 'A1');

        for ($i = 1; $i <= $linhas; $i++) {
            $sheet->setCellValue('A'.($i + 1), "valor-{$i}");
        }

        $caminho = tempnam(sys_get_temp_dir(), 'xlsx_reader_test_').'.xlsx';
        (new Xlsx($spreadsheet))->save($caminho);

        return new UploadedFile($caminho, 'planilha.xlsx', test: true);
    }
}
