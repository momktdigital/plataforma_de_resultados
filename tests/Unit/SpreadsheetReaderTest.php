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

    public function test_limite_real_de_producao_e_450_mil_celulas(): void
    {
        // Congela o valor de produção pra qualquer mudança futura ser
        // deliberada — sem gerar as 450.000 células de verdade (lento demais
        // pra rodar em toda execução da suíte; os testes de comportamento
        // abaixo usam FakeSpreadsheetReader com um limite pequeno).
        $this->assertSame(450_000, (new \ReflectionClass(SpreadsheetReader::class))->getConstant('MAX_CELULAS_XLSX'));
    }

    public function test_rejeita_xlsx_acima_do_limite_de_celulas(): void
    {
        // 3 colunas x 4 linhas = 12 células > limite de 10 da subclasse de teste.
        $arquivo = $this->xlsxComLinhasEColunas(4, 3);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('4 linhas de dados x 3 colunas (12 células)');
        $this->expectExceptionMessage('máximo suportado é 10 células');

        FakeSpreadsheetReader::readRows($arquivo);
    }

    public function test_aceita_xlsx_exatamente_no_limite_de_celulas(): void
    {
        // 2 colunas x 5 linhas = 10 células == limite (não pode passar, mas
        // bater exatamente no teto tem que continuar funcionando).
        $arquivo = $this->xlsxComLinhasEColunas(5, 2);

        $linhas = FakeSpreadsheetReader::readRows($arquivo);

        $this->assertCount(5, $linhas);
    }

    public function test_planilha_larga_e_limitada_por_menos_linhas_que_uma_estreita(): void
    {
        // Mesmo limite de células (10) pras duas: 1 coluna aceita até 10
        // linhas, mas 5 colunas só aceita até 2 — a lógica é por CÉLULA
        // (linhas x colunas), não só por linha (bug do limite antigo, que
        // deixava uma planilha larga — ex.: import de questões com ~20
        // colunas — consumir memória desproporcional sem ser barrada).
        $estreita = $this->xlsxComLinhasEColunas(10, 1);
        $this->assertCount(10, FakeSpreadsheetReader::readRows($estreita));

        $larga = $this->xlsxComLinhasEColunas(3, 5);
        $this->expectException(RuntimeException::class);
        FakeSpreadsheetReader::readRows($larga);
    }

    public function test_ignora_linhas_em_branco_no_meio_do_xlsx(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['coluna'], null, 'A1');
        $sheet->setCellValue('A2', 'valor-1');
        // A3 fica em branco de propósito — linha vazia no meio dos dados.
        $sheet->setCellValue('A4', 'valor-2');

        $caminho = tempnam(sys_get_temp_dir(), 'xlsx_reader_test_').'.xlsx';
        (new Xlsx($spreadsheet))->save($caminho);

        $linhas = SpreadsheetReader::readRows(new UploadedFile($caminho, 'planilha.xlsx', test: true));

        $this->assertCount(2, $linhas);
        $this->assertSame('valor-1', $linhas[0]['coluna']);
        $this->assertSame('valor-2', $linhas[1]['coluna']);
    }

    public function test_extensao_nao_suportada_lanca_excecao(): void
    {
        $caminho = tempnam(sys_get_temp_dir(), 'reader_test_').'.pdf';
        file_put_contents($caminho, 'não é uma planilha');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Formato de arquivo não suportado: .pdf');

        SpreadsheetReader::readRows(new UploadedFile($caminho, 'arquivo.pdf', test: true));
    }

    public function test_le_csv_com_delimitador_virgula(): void
    {
        $linhas = SpreadsheetReader::readRows($this->csvComConteudo("coluna_a,coluna_b\nx,y\n"));

        $this->assertSame([['coluna_a' => 'x', 'coluna_b' => 'y']], $linhas);
    }

    public function test_le_csv_com_delimitador_ponto_e_virgula(): void
    {
        $linhas = SpreadsheetReader::readRows($this->csvComConteudo("coluna_a;coluna_b\nx;y\n"));

        $this->assertSame([['coluna_a' => 'x', 'coluna_b' => 'y']], $linhas);
    }

    public function test_le_csv_removendo_bom_utf8(): void
    {
        $linhas = SpreadsheetReader::readRows($this->csvComConteudo("\xEF\xBB\xBFcoluna\nvalor\n"));

        $this->assertSame([['coluna' => 'valor']], $linhas);
    }

    public function test_le_csv_em_windows_1252_convertendo_para_utf8(): void
    {
        $conteudo = mb_convert_encoding("coluna\nJosé\n", 'Windows-1252', 'UTF-8');

        $linhas = SpreadsheetReader::readRows($this->csvComConteudo($conteudo));

        $this->assertSame('José', $linhas[0]['coluna']);
    }

    public function test_csv_vazio_retorna_lista_vazia(): void
    {
        $this->assertSame([], SpreadsheetReader::readRows($this->csvComConteudo('')));
    }

    public function test_csv_ignora_coluna_sem_nome_no_cabecalho(): void
    {
        $linhas = SpreadsheetReader::readRows($this->csvComConteudo("coluna_a,,coluna_c\nx,y,z\n"));

        $this->assertSame(['coluna_a' => 'x', 'coluna_c' => 'z'], $linhas[0]);
    }

    private function csvComConteudo(string $conteudo): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'csv_reader_test_').'.csv';
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, 'planilha.csv', test: true);
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

    private function xlsxComLinhasEColunas(int $linhas, int $colunas): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $cabecalho = array_map(fn ($c) => "coluna{$c}", range(1, $colunas));
        $sheet->fromArray($cabecalho, null, 'A1');

        for ($linha = 2; $linha <= $linhas + 1; $linha++) {
            $sheet->fromArray(array_fill(0, $colunas, 'v'), null, "A{$linha}");
        }

        $caminho = tempnam(sys_get_temp_dir(), 'xlsx_reader_test_').'.xlsx';
        (new Xlsx($spreadsheet))->save($caminho);

        return new UploadedFile($caminho, 'planilha.xlsx', test: true);
    }
}

/**
 * Limite de células reduzido só pra testar a LÓGICA de
 * SpreadsheetReader::readSpreadsheet() sem precisar gerar as 450.000 células
 * reais do limite de produção a cada rodada da suíte (levaria dezenas de
 * segundos). MAX_CELULAS_XLSX é `protected` justamente pra permitir isso.
 */
class FakeSpreadsheetReader extends SpreadsheetReader
{
    protected const MAX_CELULAS_XLSX = 10;
}
