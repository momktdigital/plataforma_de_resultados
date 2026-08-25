<?php

namespace App\Services;

use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Resposta;
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
 * branco — mas a coluna precisa existir). Período é opcional: só existe para
 * diferenciar tentativas do mesmo aluno na mesma avaliação em períodos diferentes.
 */
class ResultadoImportService
{
    private const RA_PATTERNS = ['/^(ra|matricula|matriculaaluno)$/'];

    private const CPF_PATTERNS = ['/^cpf$/'];

    private const NUMERO_PATTERNS = ['/^(questao|numero|item|q)$/', '/^quest/', '/^num/'];

    private const RESPOSTA_PATTERNS = ['/^(resposta|alternativa|letra|marcada)$/'];

    private const PERIODO_PATTERNS = ['/^(periodo|periodo letivo|perletivo)$/'];

    public function importar(Avaliacao $avaliacao, UploadedFile $file): ImportResult
    {
        $rows = SpreadsheetReader::readRows($file);
        $resultado = new ImportResult;

        if (empty($rows)) {
            return $resultado;
        }

        $header = array_keys($rows[0]);
        $this->validarCabecalho($header);

        DB::transaction(function () use ($avaliacao, $rows, $resultado) {
            foreach ($rows as $index => $row) {
                $resultado->registrarLinha();
                $linha = $index + 2;

                $ra = HeaderResolver::findValue($row, self::RA_PATTERNS);
                $cpf = HeaderResolver::findValue($row, self::CPF_PATTERNS);
                $numeroBruto = HeaderResolver::findValue($row, self::NUMERO_PATTERNS);
                $resposta = HeaderResolver::findValue($row, self::RESPOSTA_PATTERNS);
                $periodo = HeaderResolver::findValue($row, self::PERIODO_PATTERNS) ?? '';

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

                $criada = $this->salvarResposta($avaliacao, $ra, $cpf, $periodo, $numero, $resposta, $alunoId);

                $criada ? $resultado->registrarCriada() : $resultado->registrarAtualizada();
            }
        });

        return $resultado;
    }

    /**
     * updateOrCreate "manual" que também restaura uma resposta excluída
     * (soft-delete) em vez de colidir com o índice único. Retorna true
     * quando um registro novo foi criado (para contabilizar no resumo).
     */
    private function salvarResposta(
        Avaliacao $avaliacao,
        ?string $ra,
        ?string $cpf,
        string $periodo,
        int $numero,
        ?string $resposta,
        ?int $alunoId,
    ): bool {
        $resp = Resposta::withTrashed()
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->where('questao_numero', $numero)
            ->where('periodo', $periodo)
            ->where('ra', $ra)
            ->where('cpf', $cpf)
            ->first();

        $novo = $resp === null;

        if ($novo) {
            $resp = new Resposta([
                'avaliacao_codigo' => $avaliacao->codigo,
                'questao_numero' => $numero,
                'periodo' => $periodo,
                'ra' => $ra,
                'cpf' => $cpf,
            ]);
        } elseif ($resp->trashed()) {
            $resp->restore();
        }

        $resp->resposta = $resposta;
        $resp->aluno_id = $alunoId;
        $resp->save();

        return $novo;
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
