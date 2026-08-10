<?php

namespace App\Services;

use App\Models\Aluno;
use App\Models\Prova;
use App\Models\Resultado;
use App\Support\HeaderResolver;
use App\Support\ImportResult;
use App\Support\SpreadsheetReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Import de resultados no formato "longo": uma linha por resposta de um
 * respondente a uma questão. Únicos campos obrigatórios: CPF ou RA, Questão
 * e Resposta (a resposta pode ser vazia — significa que o aluno deixou em
 * branco — mas a coluna precisa existir).
 */
class ResultadoImportService
{
    private const RA_PATTERNS = ['/^(ra|matricula|matriculaaluno)$/'];

    private const CPF_PATTERNS = ['/^cpf$/'];

    private const NUMERO_PATTERNS = ['/^(questao|numero|item|q)$/', '/^quest/', '/^num/'];

    private const RESPOSTA_PATTERNS = ['/^(resposta|alternativa|letra|marcada)$/'];

    public function importar(Prova $prova, UploadedFile $file): ImportResult
    {
        $rows = SpreadsheetReader::readRows($file);
        $resultado = new ImportResult;

        if (empty($rows)) {
            return $resultado;
        }

        $header = array_keys($rows[0]);
        $this->validarCabecalho($header);

        DB::transaction(function () use ($prova, $rows, $resultado) {
            foreach ($rows as $index => $row) {
                $resultado->registrarLinha();
                $linha = $index + 2;

                $ra = HeaderResolver::findValue($row, self::RA_PATTERNS);
                $cpf = HeaderResolver::findValue($row, self::CPF_PATTERNS);
                $numeroBruto = HeaderResolver::findValue($row, self::NUMERO_PATTERNS);
                $resposta = HeaderResolver::findValue($row, self::RESPOSTA_PATTERNS);

                if ($ra === null && $cpf === null) {
                    $resultado->ignorarLinha($linha, 'CPF e RA ausentes — ao menos um é obrigatório.');

                    continue;
                }

                if ($numeroBruto === null || ! preg_match('/\d+/', $numeroBruto, $matches)) {
                    $resultado->ignorarLinha($linha, 'Coluna de Questão ausente ou sem número.');

                    continue;
                }

                $cpf = $cpf !== null ? preg_replace('/\D/', '', $cpf) : null;
                $ra = $ra !== null ? trim($ra) : null;
                $numero = (int) $matches[0];
                $resposta = $resposta !== null ? mb_strtoupper($resposta, 'UTF-8') : null;

                $alunoId = Aluno::query()
                    ->when($cpf, fn ($query) => $query->orWhere('cpf', $cpf))
                    ->when($ra, fn ($query) => $query->orWhere('ra', $ra))
                    ->value('id');

                $registro = Resultado::updateOrCreate(
                    [
                        'prova_codigo' => $prova->codigo,
                        'questao_numero' => $numero,
                        'ra' => $ra,
                        'cpf' => $cpf,
                    ],
                    [
                        'resposta' => $resposta,
                        'aluno_id' => $alunoId,
                    ],
                );

                $registro->wasRecentlyCreated ? $resultado->registrarCriada() : $resultado->registrarAtualizada();
            }
        });

        return $resultado;
    }

    /** @param array<int, string> $header */
    private function validarCabecalho(array $header): void
    {
        $temIdentificador = HeaderResolver::hasColumn($header, self::RA_PATTERNS)
            || HeaderResolver::hasColumn($header, self::CPF_PATTERNS);

        if (! $temIdentificador) {
            throw new RuntimeException('O arquivo precisa ter uma coluna de CPF ou de RA.');
        }

        if (! HeaderResolver::hasColumn($header, self::NUMERO_PATTERNS)) {
            throw new RuntimeException('O arquivo precisa ter uma coluna de Questão.');
        }

        if (! HeaderResolver::hasColumn($header, self::RESPOSTA_PATTERNS)) {
            throw new RuntimeException('O arquivo precisa ter uma coluna de Resposta.');
        }
    }
}
