<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Lê um arquivo .csv/.xlsx/.xls enviado no import e devolve uma lista de
 * linhas como arrays associativos (cabeçalho => valor). Mantém o mesmo
 * comportamento tolerante da aplicação legada: detecta separador (`,`/`;`),
 * remove BOM e recupera de CSVs salvos em Windows-1252.
 */
class SpreadsheetReader
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function readRows(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return match ($extension) {
            'csv', 'txt' => self::readCsv($file->getRealPath()),
            'xlsx', 'xls' => self::readSpreadsheet($file->getRealPath()),
            default => throw new RuntimeException("Formato de arquivo não suportado: .{$extension}"),
        };
    }

    private static function readCsv(string $path): array
    {
        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException('Não foi possível ler o arquivo enviado.');
        }

        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        $delimiter = self::detectDelimiter($raw);

        $lines = array_filter(preg_split('/\r\n|\r|\n/', $raw), fn ($line) => trim($line) !== '');
        $lines = array_values($lines);

        if (empty($lines)) {
            return [];
        }

        $header = str_getcsv(array_shift($lines), $delimiter);
        $header = array_map(fn ($h) => trim((string) $h), $header);

        $rows = [];
        foreach ($lines as $line) {
            $values = str_getcsv($line, $delimiter);
            $row = [];
            foreach ($header as $index => $columnName) {
                if ($columnName === '') {
                    continue;
                }
                $row[$columnName] = $values[$index] ?? null;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private static function detectDelimiter(string $raw): string
    {
        $firstLine = strtok($raw, "\r\n");

        if ($firstLine === false) {
            return ',';
        }

        $commaCount = substr_count($firstLine, ',');
        $semicolonCount = substr_count($firstLine, ';');

        return $semicolonCount > $commaCount ? ';' : ',';
    }

    private static function readSpreadsheet(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray(null, true, true, false);

        if (empty($data)) {
            return [];
        }

        $header = array_map(fn ($h) => trim((string) $h), array_shift($data));

        $rows = [];
        foreach ($data as $line) {
            if (self::isBlankLine($line)) {
                continue;
            }

            $row = [];
            foreach ($header as $index => $columnName) {
                if ($columnName === '') {
                    continue;
                }
                $row[$columnName] = $line[$index] ?? null;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private static function isBlankLine(array $line): bool
    {
        foreach ($line as $value) {
            if (! is_null($value) && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
